<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasChartWindowFilter;
use App\Models\BandwidthSample;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * BandwidthChart already sums every service into one line -- this is the
 * same samples split back out, for anyone running more than one service who
 * wants to know which one is actually carrying the traffic.
 */
class BandwidthByService extends ChartWidget
{
    use HasChartWindowFilter;

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = '300s';

    protected static ?int $sort = 11;

    public function getHeading(): string|Htmlable|null
    {
        return __('Bandwidth by service');
    }

    protected function getData(): array
    {
        // service_user_id is null on the service-level total rows -- the
        // same distinction BandwidthChart's own query relies on.
        $rows = BandwidthSample::query()
            ->whereNull('service_user_id')
            ->where('sampled_at', '>=', $this->windowStart())
            ->join('services', 'services.id', '=', 'bandwidth_samples.service_id')
            ->selectRaw('services.name as name, SUM(bytes_in_delta + bytes_out_delta) as total')
            ->groupBy('services.id', 'services.name')
            ->orderByDesc('total')
            ->get();

        [$labels, $bytes] = $this->topNWithOthers($rows, 'name', 'total');

        [$divisor, $unit] = $this->unitFor((int) $rows->max('total') ?: 0);

        return [
            'datasets' => [[
                'label' => $unit,
                'data' => array_map(fn (int $b) => round($b / $divisor, 2), $bytes),
                'backgroundColor' => $this->palette(),
                'borderWidth' => 0,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'right']],
            'maintainAspectRatio' => false,
        ];
    }

    protected function getType(): string
    {
        return 'pie';
    }
}
