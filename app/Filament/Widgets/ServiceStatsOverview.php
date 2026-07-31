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
    protected function getStats(): array
    {
        $activeServices = Service::where('status', ServiceStatus::Active)->count();
        $totalUsers = ServiceUser::where('status', ServiceUserStatus::Active)->count();
        $connectedNow = ServiceUser::where('status', ServiceUserStatus::Active)
            ->where('last_handshake_at', '>=', now()->subMinutes(3))
            ->count();

        $bandwidthToday = BandwidthSample::whereNull('service_user_id')
            ->where('sampled_at', '>=', now()->startOfDay())
            ->selectRaw('SUM(bytes_in_delta + bytes_out_delta) as total')
            ->value('total') ?? 0;

        return [
            Stat::make('Active services', $activeServices),
            Stat::make('Connected now', "{$connectedNow} / {$totalUsers}")
                ->description('Users currently connected'),
            Stat::make('Bandwidth today', round($bandwidthToday / 1024 / 1024 / 1024, 2).' GB'),
        ];
    }
}
