<?php

namespace App\Console\Commands\Logs;

use App\Services\Logs\LogExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('logs:export {--service= : Only export logs for this service ID} {--output= : Destination zip path}')]
#[Description('Export traffic, connection and bandwidth logs to a zip of CSV files')]
class ExportLogs extends Command
{
    public function handle(LogExporter $exporter): int
    {
        $serviceId = $this->option('service') ? (int) $this->option('service') : null;

        $zipPath = $exporter->exportZip($serviceId);
        $destination = $this->option('output') ?? getcwd().'/vpnforge-logs-'.now()->format('Y-m-d_His').'.zip';

        rename($zipPath, $destination);

        $this->info("Exported logs to {$destination}");

        return self::SUCCESS;
    }
}
