<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasChartWindowFilter;
use App\Models\BandwidthSample;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * "Who is actually using this thing" -- per-user rows exist in
 * bandwidth_samples already (one sample a minute per connected user), just
 * never rolled up anywhere on the dashboard.
 */
class BandwidthByUser extends ChartWidget
{
    use HasChartWindowFilter;

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = '300s';

    protected static ?int $sort = 12;

    public function getHeading(): string|Htmlable|null
    {
        return __('Bandwidth by user');
    }

    protected function getData(): array
    {
        $rows = BandwidthSample::query()
            ->whereNotNull('service_user_id')
            ->where('sampled_at', '>=', $this->windowStart())
            ->join('service_users', 'service_users.id', '=', 'bandwidth_samples.service_user_id')
            ->selectRaw('service_users.name as name, SUM(bytes_in_delta + bytes_out_delta) as total')
            ->groupBy('service_users.id', 'service_users.name')
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
        return 'doughnut';
    }
}
