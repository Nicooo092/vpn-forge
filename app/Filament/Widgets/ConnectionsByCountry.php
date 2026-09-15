<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasChartWindowFilter;
use App\Models\ConnectionLog;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * GeoIP has enriched every connection since 2026-08-22 (see the
 * add_geo_columns_to_connection_logs migration), but nothing on the
 * dashboard ever showed it -- it only ever surfaced one row at a time, deep
 * in a user's connection history.
 */
class ConnectionsByCountry extends ChartWidget
{
    use HasChartWindowFilter;

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = '300s';

    protected static ?int $sort = 9;

    public function getHeading(): string|Htmlable|null
    {
        return __('Connections by country');
    }

    protected function getData(): array
    {
        $rows = ConnectionLog::query()
            ->where('connected_at', '>=', $this->windowStart())
            ->selectRaw('COALESCE(country_name, ?) as country, COUNT(*) as total', [__('Unknown')])
            ->groupBy('country')
            ->orderByDesc('total')
            ->get();

        [$labels, $data] = $this->topNWithOthers($rows, 'country', 'total');

        return [
            'datasets' => [[
                'data' => $data,
                'backgroundColor' => $this->palette(),
                'borderWidth' => 0,
            ]],
            'labels' => $labels,
        ];
    }

    public function getDescription(): ?string
    {
        return __('GeoIP-resolved source of the client IP at connect time. Enrichment is best-effort and depends on the monthly GeoIP database refresh.');
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
