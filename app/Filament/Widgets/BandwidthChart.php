<?php

namespace App\Filament\Widgets;

use App\Models\BandwidthSample;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

class BandwidthChart extends ChartWidget
{
    protected ?string $heading = 'Bandwidth (all services)';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = '30s';

    // The dashboard grid is two columns wide, and a widget occupies one of
    // them by default -- which left the chart squeezed into the left half with
    // an empty column beside it.
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    /**
     * Largest byte value in the current window, kept from getData() so
     * getDescription() can talk about the same numbers without querying
     * twice.
     */
    private ?int $peakBytes = null;

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
        [$start, $stepSeconds, $bucketCount, $labelFormat] = $this->window();

        // Every bucket in the window exists up front, at zero. Without this
        // the series only has points where a sample happened to land, so a
        // quiet period renders as two disconnected dots rather than a line
        // sitting on the floor -- which is what made this look broken.
        $in = array_fill(0, $bucketCount, 0);
        $out = array_fill(0, $bucketCount, 0);
        $labels = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $labels[] = $start->addSeconds($i * $stepSeconds)->format($labelFormat);
        }

        // service_user_id is null on the service-level total rows -- this is
        // the aggregate-across-everything view for the global dashboard.
        $samples = BandwidthSample::whereNull('service_user_id')
            ->where('sampled_at', '>=', $start)
            ->orderBy('sampled_at')
            ->get();

        foreach ($samples as $sample) {
            $index = (int) floor($start->diffInSeconds($sample->sampled_at) / $stepSeconds);

            if ($index < 0 || $index >= $bucketCount) {
                continue;
            }

            $in[$index] += $sample->bytes_in_delta;
            $out[$index] += $sample->bytes_out_delta;
        }

        $this->peakBytes = max([0, ...$in, ...$out]);
        [$divisor, $unit] = $this->unitFor($this->peakBytes);

        return [
            'datasets' => [
                [
                    'label' => "Download ({$unit})",
                    'data' => array_map(fn (int $bytes) => round($bytes / $divisor, 2), $in),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'borderWidth' => 2,
                ],
                [
                    'label' => "Upload ({$unit})",
                    'data' => array_map(fn (int $bytes) => round($bytes / $divisor, 2), $out),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    public function getDescription(): ?string
    {
        // getData() runs before this in the render cycle, so peakBytes is
        // already populated for the window currently on screen.
        if ($this->peakBytes === null || $this->peakBytes > 0) {
            return null;
        }

        return 'No traffic recorded in this window. Samples are only written while a client is connected and the service is being polled.';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['maxTicksLimit' => 5],
                ],
                'x' => [
                    'grid' => ['display' => false],
                    // 24 hourly or 30 daily labels do not fit side by side;
                    // Chart.js thins them out rather than overlapping.
                    'ticks' => ['maxTicksLimit' => 8, 'autoSkip' => true],
                ],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'maintainAspectRatio' => false,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * Anchored on the current bucket and counted backwards, so "last 24
     * hours" is exactly 24 hourly buckets ending with the one in progress --
     * subtracting the span first and rounding down instead yields an extra
     * partial bucket on the end.
     *
     * Six-hourly over a week, daily over a month: fine enough to see the
     * shape, coarse enough that the line is not mostly noise.
     *
     * @return array{0: CarbonImmutable, 1: int, 2: int, 3: string}
     */
    private function window(): array
    {
        $now = CarbonImmutable::now();

        return match ($this->filter ?? '24h') {
            '7d' => [$now->startOfHour()->subHours(6 * 27), 6 * 3600, 28, 'D H:00'],
            '30d' => [$now->startOfDay()->subDays(29), 86400, 30, 'j M'],
            default => [$now->startOfHour()->subHours(23), 3600, 24, 'H:00'],
        };
    }

    /**
     * Scale the axis to the data instead of always reporting megabytes -- a
     * handful of DNS lookups in MB is a flat line at zero, and a busy month
     * in MB is an axis full of five-digit numbers.
     *
     * @return array{0: int, 1: string}
     */
    private function unitFor(int $peakBytes): array
    {
        foreach ([[1024 ** 3, 'GB'], [1024 ** 2, 'MB'], [1024, 'KB']] as [$divisor, $unit]) {
            if ($peakBytes >= $divisor) {
                return [$divisor, $unit];
            }
        }

        return [1, 'B'];
    }
}
