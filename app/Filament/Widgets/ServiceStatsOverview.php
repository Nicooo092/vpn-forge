<?php

namespace App\Filament\Widgets;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\BandwidthSample;
use App\Models\Service;
use App\Models\ServiceUser;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ServiceStatsOverview extends StatsOverviewWidget
{
    // Above the chart: the counts are what you check first, and they were
    // landing underneath it.
    protected static ?int $sort = 1;

    // Handshakes and bandwidth totals are refreshed by the once-a-minute
    // poller; polling faster only re-ran the queries against unchanged rows.
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $activeServices = Service::where('status', ServiceStatus::Active)->count();
        $totalUsers = ServiceUser::where('status', ServiceUserStatus::Active)->count();
        $connectedNow = ServiceUser::where('status', ServiceUserStatus::Active)
            ->where('last_handshake_at', '>=', now()->subMinutes(3))
            ->count();

        $erroredServices = Service::where('status', ServiceStatus::Error)->count();

        $today = BandwidthSample::whereNull('service_user_id')
            ->where('sampled_at', '>=', now()->startOfDay())
            ->selectRaw('SUM(bytes_in_delta) as down, SUM(bytes_out_delta) as up')
            ->first();

        $down = (int) ($today->down ?? 0);
        $up = (int) ($today->up ?? 0);

        return [
            Stat::make(__('Active services'), $activeServices)
                ->description($erroredServices > 0
                    ? __(':count failed to provision', ['count' => $erroredServices])
                    : __('None in error'))
                ->color($erroredServices > 0 ? 'danger' : 'success'),

            Stat::make(__('Connected now'), "{$connectedNow} / {$totalUsers}")
                ->description($totalUsers === 0
                    ? __('No users configured yet')
                    : __('Of the users with an active config')),

            Stat::make(__('Bandwidth today'), $this->formatBytes($down + $up))
                ->description(__(':down down / :up up', ['down' => $this->formatBytes($down), 'up' => $this->formatBytes($up)])),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        foreach ([[1024 ** 3, 'GB'], [1024 ** 2, 'MB'], [1024, 'KB']] as [$divisor, $unit]) {
            if ($bytes >= $divisor) {
                return round($bytes / $divisor, 2).' '.$unit;
            }
        }

        return $bytes.' B';
    }
}
