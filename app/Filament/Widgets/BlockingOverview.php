<?php

namespace App\Filament\Widgets;

use App\Enums\TrafficLogKind;
use App\Models\TrafficLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * A front-page summary of DNS blocking over the last 24h, sitting alongside
 * TopDomains. The two counts are range scans over a large table, so they are
 * cached for as long as the widget polls -- the ranking barely moves in between.
 */
class BlockingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 6;

    protected ?string $pollingInterval = '300s';

    protected function getStats(): array
    {
        [$total, $blocked] = Cache::remember('blocking:overview:24h', 300, function (): array {
            $since = now()->subDay();

            $base = fn () => TrafficLog::query()
                ->where('kind', TrafficLogKind::Dns)
                ->where('occurred_at', '>=', $since);

            return [$base()->count(), $base()->where('blocked', true)->count()];
        });

        $rate = $total > 0 ? round($blocked / $total * 100, 1) : 0.0;

        return [
            Stat::make(__('DNS lookups (24h)'), number_format($total)),
            Stat::make(__('Blocked (24h)'), number_format($blocked))
                ->color($blocked > 0 ? 'danger' : 'gray'),
            Stat::make(__('Block rate'), $rate.'%')
                ->description(__('Answered locally, never forwarded')),
        ];
    }
}
