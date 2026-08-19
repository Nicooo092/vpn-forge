<?php

namespace App\Services\Metrics;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\System\SystemHealth;

/**
 * Renders the panel's health as a Prometheus exposition-format text document.
 * Read-only and deliberately sparse: counts, aggregates and per-service up/down
 * only. No keys, addresses or config values ever reach this output -- service
 * names are the only identifiers, and those are not secret. A null reading
 * (a metric unavailable off Linux, or before the worker's first heartbeat) is
 * emitted as NaN, which Prometheus stores as a genuine "no value" rather than a
 * misleading zero that would page someone.
 */
class PrometheusExporter
{
    public function __construct(private readonly SystemHealth $health) {}

    public function render(): string
    {
        $snapshot = $this->health->snapshot();
        $counts = $snapshot['counts'];

        $lines = [];

        $this->gauge($lines, 'vpnforge_up', 'Whether the metrics endpoint is serving (always 1).', 1);
        $this->gauge($lines, 'vpnforge_services_total', 'Configured services.', $counts['services_total']);
        $this->gauge($lines, 'vpnforge_services_active', 'Services in the active state.', $counts['services_active']);
        $this->gauge($lines, 'vpnforge_users_total', 'Configured service users.', $counts['users_total']);
        $this->gauge($lines, 'vpnforge_users_active', 'Service users in the active state.', $counts['users_active']);
        $this->gauge($lines, 'vpnforge_connected_now', 'Sessions connected right now.', $counts['connected_now']);
        $this->gauge($lines, 'vpnforge_bandwidth_24h_bytes', 'Bytes transferred in the last 24 hours.', $snapshot['bandwidth_24h']);
        $this->gauge($lines, 'vpnforge_worker_heartbeat_age_seconds', 'Seconds since the privileged worker last checked in.', $snapshot['worker']['age_seconds']);
        $this->gauge($lines, 'vpnforge_failed_jobs', 'Jobs sitting in the failed_jobs table.', $snapshot['failed_jobs']);
        $this->gauge($lines, 'vpnforge_disk_used_percent', 'Disk usage of the install partition, in percent.', $snapshot['disk']['percent']);

        $this->serviceUp($lines);

        // Exposition format expects a trailing newline after the final sample.
        return implode("\n", $lines)."\n";
    }

    /**
     * One single-sample gauge: its HELP, its TYPE, then the value.
     *
     * @param  list<string>  $lines
     */
    private function gauge(array &$lines, string $name, string $help, int|float|null $value): void
    {
        $lines[] = '# HELP '.$name.' '.$help;
        $lines[] = '# TYPE '.$name.' gauge';
        $lines[] = $name.' '.$this->value($value);
    }

    /**
     * vpnforge_service_up is a labelled family -- one HELP/TYPE header, then a
     * sample per service. Ordered by name so the output is stable between
     * scrapes (Prometheus does not care, a human reading it does).
     *
     * @param  list<string>  $lines
     */
    private function serviceUp(array &$lines): void
    {
        $lines[] = '# HELP vpnforge_service_up Whether each service is in the active state (1) or not (0).';
        $lines[] = '# TYPE vpnforge_service_up gauge';

        foreach (Service::query()->orderBy('name')->get(['name', 'status']) as $service) {
            $up = $service->status === ServiceStatus::Active ? 1 : 0;
            $lines[] = 'vpnforge_service_up{service="'.$this->label((string) $service->name).'"} '.$up;
        }
    }

    private function value(int|float|null $value): string
    {
        // A missing reading is NaN, not 0 -- Prometheus treats the two very
        // differently and a false zero would read as a healthy count.
        if ($value === null) {
            return 'NaN';
        }

        return (string) (is_float($value) ? $value : (int) $value);
    }

    /**
     * Escape a label value per the exposition format: backslash, double quote
     * and newline. Service names are operator-chosen, so this is belt-and-braces
     * against one carrying a quote that would corrupt the line.
     */
    private function label(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
