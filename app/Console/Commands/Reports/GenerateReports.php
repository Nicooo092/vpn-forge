<?php

namespace App\Console\Commands\Reports;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Reports\ExportArchive;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ServiceReportData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('vpnforge:export-report {--days= : Window in days (default: config)} {--service= : Only this service ID}')]
#[Description('Render each active service\'s sites report to a stored PDF and prune old exports')]
class GenerateReports extends Command
{
    public function handle(ReportPdf $pdf, ExportArchive $archive): int
    {
        $days = (int) ($this->option('days') ?: config('vpnforge.exports.days', 7));
        $since = now()->subDays($days);
        $periodLabel = "Last {$days} days";
        $stamp = now()->format('Y-m-d');

        $services = Service::query()
            ->where('status', ServiceStatus::Active)
            ->when($this->option('service'), fn ($q) => $q->whereKey((int) $this->option('service')))
            ->get();

        $written = 0;

        foreach ($services as $service) {
            // Skip services with nothing in the window rather than pile up
            // "no activity" PDFs under a weekly schedule.
            if (! (new ServiceReportData($service, $since))->hasData()) {
                continue;
            }

            $name = "report-{$service->interface_name}-{$stamp}.pdf";
            $archive->put($name, $pdf->render($service, $since, $periodLabel));
            $written++;

            $this->info("Wrote {$name}");
        }

        $pruned = $archive->prune((int) config('vpnforge.exports.retention_days', 60));

        $this->info("Generated {$written} report(s); pruned {$pruned} old export(s).");

        return self::SUCCESS;
    }
}
