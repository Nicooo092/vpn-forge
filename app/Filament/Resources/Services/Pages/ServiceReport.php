<?php

namespace App\Filament\Resources\Services\Pages;

use App\Enums\TrafficLogKind;
use App\Filament\Resources\Services\ServiceResource;
use App\Services\Traffic\DomainCategory;
use BackedEnum;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * "What did this service's users actually visit." Aggregates the DNS traffic
 * log by domain and by category over a chosen window, so a flat stream of
 * thousands of lookups becomes a readable summary.
 */
class ServiceReport extends Page
{
    use InteractsWithRecord;

    protected string $view = 'filament.pages.service-report';

    protected static ?string $title = 'Report';

    #[Url]
    public string $period = '7d';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public static function getNavigationLabel(): string
    {
        return 'Report';
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-chart-pie';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Report -- '.$this->record->name;
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];
    }

    private function since(): Carbon
    {
        return match ($this->period) {
            '24h' => now()->subDay(),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };
    }

    /**
     * Every DNS lookup host + count in the window, categorised in PHP. One
     * query, grouped in the database; categorisation is cheap per distinct
     * host.
     *
     * @return Collection<int, object{host: string, hits: int, category: DomainCategory}>
     */
    private function domains(): Collection
    {
        return $this->record->trafficLogs()
            ->where('kind', TrafficLogKind::Dns)
            ->whereNotNull('host')
            ->where('occurred_at', '>=', $this->since())
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
        return $this->record->trafficLogs()
            ->where('kind', TrafficLogKind::Dns)
            ->where('occurred_at', '>=', $this->since())
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

    protected function getViewData(): array
    {
        return [
            'hasData' => $this->hasData(),
            'breakdown' => $this->categoryBreakdown(),
            'topDomains' => $this->topDomains(),
            'topUsers' => $this->topUsers(),
        ];
    }

    public static function getResource(): string
    {
        return ServiceResource::class;
    }
}
