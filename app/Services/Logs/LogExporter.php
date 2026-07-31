<?php

namespace App\Services\Logs;

use App\Models\BandwidthSample;
use App\Models\ConnectionLog;
use App\Models\TrafficLog;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Shared by both the `logs:export` artisan command and the Filament
 * "Export all logs" page action, so the two never drift out of sync.
 */
class LogExporter
{
    /**
     * Builds a zip containing one CSV per log table and returns its path.
     * Callers are responsible for deleting the returned file once done
     * with it (e.g. after streaming it to the browser).
     */
    public function exportZip(?int $serviceId = null): string
    {
        $workDir = sys_get_temp_dir().'/vpnforge-export-'.Str::random(12);
        mkdir($workDir, 0700, true);

        $this->writeCsv(
            "{$workDir}/traffic_logs.csv",
            ['id', 'service_id', 'service_user_id', 'kind', 'occurred_at', 'source_ip', 'host', 'detail'],
            TrafficLog::query()->when($serviceId, fn ($q) => $q->where('service_id', $serviceId))->orderBy('occurred_at'),
            fn (TrafficLog $row) => [
                $row->id, $row->service_id, $row->service_user_id, $row->kind->value,
                $row->occurred_at, $row->source_ip, $row->host, json_encode($row->detail),
            ],
        );

        $this->writeCsv(
            "{$workDir}/connection_logs.csv",
            ['id', 'service_user_id', 'connected_at', 'disconnected_at', 'peer_ip', 'bytes_in', 'bytes_out'],
            ConnectionLog::query()
                ->when($serviceId, fn ($q) => $q->whereHas('serviceUser', fn ($q2) => $q2->where('service_id', $serviceId)))
                ->orderBy('connected_at'),
            fn (ConnectionLog $row) => [
                $row->id, $row->service_user_id, $row->connected_at, $row->disconnected_at,
                $row->peer_ip, $row->bytes_in, $row->bytes_out,
            ],
        );

        $this->writeCsv(
            "{$workDir}/bandwidth_samples.csv",
            ['id', 'service_id', 'service_user_id', 'sampled_at', 'bytes_in_delta', 'bytes_out_delta'],
            BandwidthSample::query()->when($serviceId, fn ($q) => $q->where('service_id', $serviceId))->orderBy('sampled_at'),
            fn (BandwidthSample $row) => [
                $row->id, $row->service_id, $row->service_user_id,
                $row->sampled_at, $row->bytes_in_delta, $row->bytes_out_delta,
            ],
        );

        $zipPath = sys_get_temp_dir().'/vpnforge-export-'.Str::random(12).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);

        foreach (glob("{$workDir}/*.csv") as $csvFile) {
            $zip->addFile($csvFile, basename($csvFile));
        }

        $zip->close();

        foreach (glob("{$workDir}/*.csv") as $csvFile) {
            unlink($csvFile);
        }
        rmdir($workDir);

        return $zipPath;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  callable(*): array<int, mixed>  $rowMapper
     */
    private function writeCsv(string $path, array $header, $query, callable $rowMapper): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, $header);

        $query->chunk(1000, function ($rows) use ($handle, $rowMapper) {
            foreach ($rows as $row) {
                fputcsv($handle, $rowMapper($row));
            }
        });

        fclose($handle);
    }
}
