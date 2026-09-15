<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasChartWindowFilter;
use App\Models\ConnectionLog;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * When people actually connect, by hour of day (server time zone) -- not a
 * proportion chart like its neighbours, but it answers a question the same
 * family of "where does the traffic actually come from" ideas raised.
 */
class ConnectionsByHour extends ChartWidget
{
    use HasChartWindowFilter;

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = '300s';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 13;

    public function getHeading(): string|Htmlable|null
    {
        return __('Connections by hour of day');
    }

    protected function getData(): array
    {
        $rows = ConnectionLog::query()
            ->where('connected_at', '>=', $this->windowStart())
            ->selectRaw($this->hourExpression().' as hour, COUNT(*) as total')
            ->groupBy('hour')
            ->toBase()
            ->get()
            ->keyBy(fn ($row) => (int) $row->hour);

        $labels = [];
        $data = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $labels[] = sprintf('%02dh', $hour);
            $data[] = (int) ($rows[$hour]->total ?? 0);
        }

        return [
            'datasets' => [[
                'label' => __('Connections'),
                'data' => $data,
                'backgroundColor' => '#3b82f6',
                'borderRadius' => 4,
            ]],
            'labels' => $labels,
        ];
    }

    /**
     * MariaDB and SQLite (the test suite's driver) share no date function
     * here -- the same split BandwidthChart's bucketExpression() uses.
     */
    private function hourExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%H', connected_at) AS INTEGER)",
            default => 'HOUR(connected_at)',
        };
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['maxTicksLimit' => 5]],
                'x' => ['grid' => ['display' => false]],
            ],
            'plugins' => ['legend' => ['display' => false]],
            'maintainAspectRatio' => false,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
