<?php

namespace App\Filament\Widgets;

use App\Enums\TrafficLogKind;
use App\Filament\Widgets\Concerns\HasChartWindowFilter;
use App\Models\TrafficLog;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * BlockingOverview already puts the same two numbers in stat tiles; this is
 * the proportion those numbers actually represent, which a "1,204 / 89,311"
 * pair does not make obvious at a glance.
 */
class TrafficBlockedChart extends ChartWidget
{
    use HasChartWindowFilter;

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = '300s';

    protected static ?int $sort = 10;

    public function getHeading(): string|Htmlable|null
    {
        return __('DNS lookups: blocked vs allowed');
    }

    protected function getData(): array
    {
        $base = fn () => TrafficLog::query()
            ->where('kind', TrafficLogKind::Dns)
            ->where('occurred_at', '>=', $this->windowStart());

        $total = $base()->count();
        $blocked = $base()->where('blocked', true)->count();

        return [
            'datasets' => [[
                'data' => [$blocked, max(0, $total - $blocked)],
                'backgroundColor' => ['#f43f5e', '#10b981'],
                'borderWidth' => 0,
            ]],
            'labels' => [__('Blocked'), __('Allowed')],
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
