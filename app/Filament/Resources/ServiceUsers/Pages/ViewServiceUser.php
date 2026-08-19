<?php

namespace App\Filament\Resources\ServiceUsers\Pages;

use App\Enums\TrafficLogKind;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\ServiceUsers\ServiceUserResource;
use App\Models\ConnectionLog;
use App\Services\Geo\GeoLocator;
use App\Services\Traffic\DomainCategory;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Everything about one person in one place: who they are, what limits apply,
 * when they last connected, and where they have been. Previously all of this
 * was spread across three service-wide tables that had to be read sideways
 * to work out anything about an individual.
 */
class ViewServiceUser extends Page
{
    protected static string $resource = ServiceUserResource::class;

    protected string $view = 'filament.pages.service-user';

    // Filament's own trait rather than a typed public $record of our own:
    // Livewire binds the route parameter straight onto a public property of
    // the same name, so declaring it as a ServiceUser makes hydration try to
    // assign the raw id to it and fail.
    use InteractsWithRecord;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record)->load('service');
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            ServiceResource::getUrl('index') => __('Services'),
            ServiceResource::getUrl('users', ['record' => $this->record->service]) => $this->record->service->name,
            $this->record->name,
        ];
    }

    /**
     * @return array<int, array{host: string, hits: int, last_seen: string, category: DomainCategory}>
     */
    public function getTopDomains(): array
    {
        return $this->record->trafficLogs()
            ->where('kind', TrafficLogKind::Dns)
            ->whereNotNull('host')
            ->where('occurred_at', '>=', now()->subDays(7))
            ->selectRaw('host, COUNT(*) as hits, MAX(occurred_at) as last_seen')
            ->groupBy('host')
            ->orderByDesc('hits')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'host' => $row->host,
                'hits' => (int) $row->hits,
                'last_seen' => Carbon::parse($row->last_seen)->diffForHumans(),
                'category' => DomainCategory::for($row->host),
            ])
            ->all();
    }

    /**
     * @return array<int, array{connected_at: string, ended: string, peer_ip: ?string, country: ?string}>
     */
    public function getRecentSessions(): array
    {
        $geo = app(GeoLocator::class);

        return $this->record->connectionLogs()
            ->latest('connected_at')
            ->limit(10)
            ->get()
            ->map(fn (ConnectionLog $log) => [
                'connected_at' => $log->connected_at?->toDayDateTimeString() ?? '--',
                'ended' => $log->disconnected_at?->diffForHumans() ?? __('still connected'),
                'peer_ip' => $log->peer_ip,
                'country' => self::sessionCountry($log, $geo),
            ])
            ->all();
    }

    /**
     * A flag + country label for a session: the geo stored on the row, or a live
     * lookup for rows recorded before the databases existed. Null when unknown.
     */
    private static function sessionCountry(ConnectionLog $log, GeoLocator $geo): ?string
    {
        $name = $log->country_name;
        $code = $log->country_code;

        if ($name === null && $code === null) {
            $resolved = $geo->locate($log->peer_ip);
            $name = $resolved['country_name'] ?? null;
            $code = $resolved['country_code'] ?? null;
        }

        if ($name === null && $code === null) {
            return null;
        }

        return trim(GeoLocator::flagEmoji($code).' '.($name ?? $code));
    }

    public function formatBytes(int $bytes): string
    {
        foreach ([[1024 ** 3, 'GB'], [1024 ** 2, 'MB'], [1024, 'KB']] as [$divisor, $unit]) {
            if ($bytes >= $divisor) {
                return round($bytes / $divisor, 2).' '.$unit;
            }
        }

        return $bytes.' B';
    }
}
