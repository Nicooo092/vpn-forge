<?php

namespace App\Filament\Widgets;

use App\Models\BandwidthSample;
use Filament\Widgets\ChartWidget;

class BandwidthChart extends ChartWidget
{
    protected ?string $heading = 'Bandwidth (all services)';

    protected function getFilters(): ?array
    {
        return [
            '24h' => 'Last 24 hours',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
        ];
    }

    protected function getData(): array
    {
        $range = $this->filter ?? '24h';

        [$start, $bucketFormat] = match ($range) {
            '7d' => [now()->subDays(7), 'Y-m-d H:00'],
            '30d' => [now()->subDays(30), 'Y-m-d'],
            default => [now()->subHours(24), 'Y-m-d H:00'],
        };

        // service_user_id is null on service-level total rows -- this is
        // the aggregate-across-everything view for the global dashboard.
        $samples = BandwidthSample::whereNull('service_user_id')
            ->where('sampled_at', '>=', $start)
            ->orderBy('sampled_at')
            ->get();

        $buckets = [];

        foreach ($samples as $sample) {
            $key = $sample->sampled_at->format($bucketFormat);
            $buckets[$key]['in'] = ($buckets[$key]['in'] ?? 0) + $sample->bytes_in_delta;
            $buckets[$key]['out'] = ($buckets[$key]['out'] ?? 0) + $sample->bytes_out_delta;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Download (MB)',
                    'data' => array_map(fn (array $b) => round($b['in'] / 1024 / 1024, 2), array_values($buckets)),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                ],
                [
                    'label' => 'Upload (MB)',
                    'data' => array_map(fn (array $b) => round($b['out'] / 1024 / 1024, 2), array_values($buckets)),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                ],
            ],
            'labels' => array_keys($buckets),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
