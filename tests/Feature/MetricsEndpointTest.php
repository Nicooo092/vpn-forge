<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Services\Metrics\PrometheusExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /metrics endpoint sits outside the panel's session auth so a monitoring
 * scraper can reach it, which makes the bearer token the only thing between an
 * anonymous request and the panel's counts. These pin that boundary: no token
 * configured means the route does not exist, and a wrong/absent token is
 * refused. The exporter's text shape is asserted too, since a single malformed
 * line makes Prometheus drop the whole scrape.
 */
class MetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function service(string $name = 'office', ServiceStatus $status = ServiceStatus::Active, int $port = 51820): Service
    {
        return Service::create([
            'name' => $name,
            'interface_name' => 'wg-'.$name,
            'subnet_cidr' => '10.0.0.0/24',
            // Distinct per service: (transport, listen_port) is unique -- two
            // UDP services cannot share a port -- so a second service needs its own.
            'listen_port' => $port,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => $status,
            'logging_enabled_default' => true,
            'config' => [],
        ]);
    }

    public function test_the_endpoint_is_absent_when_no_token_is_configured(): void
    {
        config()->set('vpnforge.metrics.token', null);

        $this->get('/metrics')->assertNotFound();
    }

    public function test_a_missing_or_wrong_token_is_refused(): void
    {
        config()->set('vpnforge.metrics.token', 'super-secret-token');

        $this->get('/metrics')->assertStatus(401);
        $this->withToken('not-the-token')->get('/metrics')->assertStatus(401);
    }

    public function test_the_right_token_returns_prometheus_text(): void
    {
        config()->set('vpnforge.metrics.token', 'super-secret-token');
        $this->service('office');
        $this->service('branch', ServiceStatus::Disabled, 51821);

        $response = $this->withToken('super-secret-token')->get('/metrics');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        $body = (string) $response->getContent();
        $this->assertStringContainsString("# TYPE vpnforge_up gauge\nvpnforge_up 1", $body);
        $this->assertStringContainsString('vpnforge_service_up{service="office"} 1', $body);
        $this->assertStringContainsString('vpnforge_service_up{service="branch"} 0', $body);
        $this->assertStringContainsString('vpnforge_services_total 2', $body);
    }

    public function test_the_exporter_emits_a_well_formed_line_for_every_metric(): void
    {
        $this->service('office');

        $text = app(PrometheusExporter::class)->render();

        foreach ([
            'vpnforge_up',
            'vpnforge_services_total',
            'vpnforge_users_total',
            'vpnforge_connected_now',
            'vpnforge_bandwidth_24h_bytes',
            'vpnforge_failed_jobs',
            'vpnforge_service_up',
        ] as $metric) {
            $this->assertStringContainsString('# HELP '.$metric.' ', $text);
            $this->assertStringContainsString('# TYPE '.$metric.' gauge', $text);
        }

        // Every non-comment line must carry a numeric value or NaN -- a bare
        // metric name is a formatting bug that voids the scrape.
        foreach (explode("\n", trim($text)) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $this->assertMatchesRegularExpression('/^\S+\s+(-?\d+(\.\d+)?|NaN)$/', $line, "malformed line: {$line}");
        }
    }
}
