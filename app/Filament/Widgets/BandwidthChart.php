<?php

namespace App\Filament\Widgets;

use App\Models\BandwidthSample;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BandwidthChart extends ChartWidget
{
    protected ?string $maxHeight = '260px';

    // The scheduler writes one sample a minute, so anything faster than this
    // only ever redrew the same series.
    protected ?string $pollingInterval = '60s';

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

    public function getHeading(): string|Htmlable|null
    {
        return __('Bandwidth (all services)');
    }

    protected function getFilters(): ?array
    {
        // Keys are the stored filter values and stay English; only what the
        // select shows is translated.
        return [
            '24h' => __('Last 24 hours'),
            '7d' => __('Last 7 days'),
            '30d' => __('Last 30 days'),
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

        foreach ($this->bucketTotals($start, $stepSeconds) as $row) {
            $index = (int) $row->bucket;

            if ($index < 0 || $index >= $bucketCount) {
                continue;
            }

            $in[$index] = (int) $row->bytes_in;
            $out[$index] = (int) $row->bytes_out;
        }

        $this->peakBytes = max([0, ...$in, ...$out]);
        [$divisor, $unit] = $this->unitFor($this->peakBytes);

        return [
            'datasets' => [
                [
                    'label' => __('Download (:unit)', ['unit' => $unit]),
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
                    'label' => __('Upload (:unit)', ['unit' => $unit]),
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

        return __('No traffic recorded in this window. Samples are only written while a client is connected and the service is being polled.');
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
     * One row per bucket that actually has traffic -- at most 30 -- summed by
     * the database. This used to hydrate every sample in the window (a month
     * is ~43,000 rows) purely to add them up into 30 numbers in PHP.
     *
     * service_user_id is null on the service-level total rows -- this is
     * the aggregate-across-everything view for the global dashboard.
     *
     * @return Collection<int, object>
     */
    private function bucketTotals(CarbonImmutable $start, int $stepSeconds): Collection
    {
        $bucket = $this->bucketExpression($stepSeconds);

        return BandwidthSample::query()
            ->whereNull('service_user_id')
            ->where('sampled_at', '>=', $start)
            ->selectRaw(
                "{$bucket} as bucket, SUM(bytes_in_delta) as bytes_in, SUM(bytes_out_delta) as bytes_out",
                [$start],
            )
            ->groupByRaw($bucket, [$start])
            ->toBase()
            ->get();
    }

    /**
     * The bucket index, computed by the database instead of in PHP:
     * floor((sampled_at - start) / step), exactly what the row-by-row loop
     * used to work out, so the series lands in the same places.
     *
     * MariaDB and SQLite (which the test suite runs on) share no date
     * function here, so the expression is per driver. Both forms subtract two
     * naive datetimes without a session time zone anywhere in the middle --
     * UNIX_TIMESTAMP() would have applied the MySQL session zone to one side
     * only and shifted the whole chart on a non-UTC server.
     */
    private function bucketExpression(int $stepSeconds): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST((CAST(strftime('%s', sampled_at) AS INTEGER) - CAST(strftime('%s', ?) AS INTEGER)) / {$stepSeconds} AS INTEGER)",
            default => "FLOOR(TIMESTAMPDIFF(SECOND, ?, sampled_at) / {$stepSeconds})",
        };
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
