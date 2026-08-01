<?php

namespace App\Services\Reports;

use App\Enums\TrafficLogKind;
use App\Models\Service;
use App\Services\Traffic\DomainCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The "sites visités" aggregation in one place, so the on-screen report, the
 * PDF export and the scheduled export all read exactly the same numbers rather
 * than each rolling their own query and drifting apart.
 */
class ServiceReportData
{
    /**
     * @var Collection<int, object{host: string, hits: int, category: DomainCategory}>|null
     */
    private ?Collection $domains = null;

    public function __construct(
        public readonly Service $service,
        public readonly Carbon $since,
    ) {}

    /**
     * Every DNS lookup host + count in the window, categorised once. Memoised
     * so the summary, the breakdown and the top list share a single query.
     *
     * @return Collection<int, object{host: string, hits: int, category: DomainCategory}>
     */
    private function domains(): Collection
    {
        return $this->domains ??= $this->service->trafficLogs()
            ->where('kind', TrafficLogKind::Dns)
            ->whereNotNull('host')
            ->where('occurred_at', '>=', $this->since)
            ->selectRaw('host, COUNT(*) as hits')
            ->groupBy('host')
            ->orderByDesc('hits')
            ->get()
            ->map(fn ($row) => (object) [
                'host' => $row->host,
                'hits' => (int) $row->hits,
                'category' => DomainCategory::for($row->host),
            ]);
    }

    public function hasData(): bool
    {
        return $this->domains()->isNotEmpty();
    }

    public function totalLookups(): int
    {
        return (int) $this->domains()->sum('hits');
    }

    public function distinctDomains(): int
    {
        return $this->domains()->count();
    }

    /**
     * @return array<int, array{category: DomainCategory, hits: int, domains: int, percent: float}>
     */
    public function categoryBreakdown(): array
    {
        $domains = $this->domains();
        $total = max(1, $domains->sum('hits'));

        return $domains
            ->groupBy(fn ($d) => $d->category->value)
            ->map(fn ($group) => [
                'category' => $group->first()->category,
                'hits' => $group->sum('hits'),
                'domains' => $group->count(),
                'percent' => round($group->sum('hits') / $total * 100, 1),
            ])
            ->sortByDesc('hits')
            ->values()
            ->all();
    }

    /**
     * @return array<int, object{host: string, hits: int, category: DomainCategory}>
     */
    public function topDomains(int $limit = 25): array
    {
        return $this->domains()->take($limit)->all();
    }

    /**
     * @return array<int, array{name: string, hits: int}>
     */
    public function topUsers(int $limit = 10): array
    {
        return $this->service->trafficLogs()
            ->where('kind', TrafficLogKind::Dns)
            ->where('occurred_at', '>=', $this->since)
            ->with('serviceUser:id,name')
            ->selectRaw('service_user_id, COUNT(*) as hits')
            ->groupBy('service_user_id')
            ->orderByDesc('hits')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->serviceUser?->name ?? 'unknown',
                'hits' => (int) $row->hits,
            ])
            ->all();
    }
}
