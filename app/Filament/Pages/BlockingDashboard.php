<?php

namespace App\Filament\Pages;

use App\Enums\TrafficLogKind;
use App\Models\Service;
use App\Models\TrafficLog;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;

/**
 * DNS blocking insight, read-only (open to auditors): over a 24h/7d window, how
 * many lookups the per-service resolvers answered locally (blocked) versus
 * resolved, plus the domains blocked most -- overall and per service.
 *
 * "Blocked" is a fact the capture agent records on each DNS row (traffic_logs
 * .blocked), set when dnsmasq answered the name itself (address=/x/ -> NXDOMAIN,
 * or a 0.0.0.0 addn-hosts blocklist entry) rather than forwarding it upstream.
 * Every query here is a range over (kind, blocked, occurred_at) or the existing
 * (kind, occurred_at, host) index, and the results are cached -- this table is
 * large and the numbers barely move minute to minute.
 */
class BlockingDashboard extends Page
{
    protected string $view = 'filament.pages.blocking-dashboard';

    protected static ?int $navigationSort = 30;

    #[Url]
    public string $window = '24h';

    #[Url]
    public ?int $serviceId = null;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-shield-check';
    }

    public static function getNavigationLabel(): string
    {
        return __('DNS blocking');
    }

    public function getTitle(): string|Htmlable
    {
        return __('DNS blocking');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('How many DNS lookups were answered locally as NXDOMAIN instead of being resolved.');
    }

    /**
     * @return array<string, string>
     */
    public function windowOptions(): array
    {
        return [
            '24h' => __('Last 24 hours'),
            '7d' => __('Last 7 days'),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    public function serviceOptions(): array
    {
        return ['' => __('All services')]
            + Service::orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array{total: int, blocked: int, allowed: int, rate: float}
     */
    public function stats(): array
    {
        return Cache::remember($this->cacheKey('stats'), $this->ttl(), function (): array {
            $total = $this->baseQuery()->count();
            $blocked = $this->baseQuery()->where('blocked', true)->count();

            return [
                'total' => $total,
                'blocked' => $blocked,
                'allowed' => $total - $blocked,
                'rate' => $total > 0 ? round($blocked / $total * 100, 1) : 0.0,
            ];
        });
    }

    /**
     * @return Collection<int, object>
     */
    public function topBlocked(): Collection
    {
        return Cache::remember($this->cacheKey('top'), $this->ttl(), fn (): Collection => $this->baseQuery()
            ->where('blocked', true)
            ->whereNotNull('host')
            ->selectRaw('host, COUNT(*) as hits, MAX(occurred_at) as last_seen')
            ->groupBy('host')
            ->orderByDesc('hits')
            ->limit((int) config('vpnforge.blocking.top_limit', 10))
            ->get());
    }

    /**
     * @return Collection<int, array{name: string, total: int, blocked: int, rate: float}>
     */
    public function perService(): Collection
    {
        return Cache::remember($this->cacheKey('per_service'), $this->ttl(), function (): Collection {
            $since = $this->since();

            $totals = TrafficLog::query()
                ->where('kind', TrafficLogKind::Dns)
                ->where('occurred_at', '>=', $since)
                ->selectRaw('service_id, COUNT(*) as aggregate')
                ->groupBy('service_id')
                ->pluck('aggregate', 'service_id');

            $blocked = TrafficLog::query()
                ->where('kind', TrafficLogKind::Dns)
                ->where('occurred_at', '>=', $since)
                ->where('blocked', true)
                ->selectRaw('service_id, COUNT(*) as aggregate')
                ->groupBy('service_id')
                ->pluck('aggregate', 'service_id');

            $names = Service::whereIn('id', $totals->keys())->pluck('name', 'id');

            return $totals
                ->map(function ($total, $id) use ($blocked, $names): array {
                    $total = (int) $total;
                    $blockedCount = (int) ($blocked[$id] ?? 0);

                    return [
                        'name' => $names[$id] ?? ('#'.$id),
                        'total' => $total,
                        'blocked' => $blockedCount,
                        'rate' => $total > 0 ? round($blockedCount / $total * 100, 1) : 0.0,
                    ];
                })
                ->sortByDesc('blocked')
                ->values();
        });
    }

    /**
     * A fresh range query over the selected window and service. The
     * (kind, occurred_at, host) index turns the window bound into a range seek;
     * adding ->where('blocked', true) lets the planner switch to the
     * (kind, blocked, occurred_at) index instead; the optional service filter
     * falls to the (service_id, occurred_at) composite.
     */
    private function baseQuery(): Builder
    {
        return TrafficLog::query()
            ->where('kind', TrafficLogKind::Dns)
            ->where('occurred_at', '>=', $this->since())
            ->when($this->serviceId, fn (Builder $query, $id) => $query->where('service_id', $id));
    }

    private function since(): Carbon
    {
        return $this->window === '7d' ? now()->subWeek() : now()->subDay();
    }

    private function ttl(): int
    {
        return (int) config('vpnforge.blocking.cache_ttl', 60);
    }

    private function cacheKey(string $suffix): string
    {
        return 'blocking:'.$suffix.':'.$this->window.':'.($this->serviceId ?? 'all');
    }
}
