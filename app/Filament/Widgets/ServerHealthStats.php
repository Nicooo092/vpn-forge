<?php

namespace App\Filament\Widgets;

use App\Services\System\SystemHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The Server health read-out, built from Filament's own stat cards rather than
 * hand-rolled markup, so it inherits the panel's card, spacing and dark-mode
 * treatment instead of approximating them. Load/memory/disk are colour-coded;
 * the rest stay neutral so the eye lands on the gauges that can actually go
 * wrong.
 */
class ServerHealthStats extends StatsOverviewWidget
{
    // This belongs to the Server health page, which lists it explicitly as a
    // header widget. Left discoverable it also landed on the Dashboard, where
    // it repeated ServiceStatsOverview's counts and pushed the getting-started
    // checklist below the fold. Filament registers the Livewire component
    // before it consults this flag, so the page still renders it.
    protected static bool $isDiscovered = false;

    // Eager, not the widget default of lazy: the snapshot is cheap (a read of
    // /proc plus a few row counts), so there is nothing to defer -- rendering
    // it inline puts the numbers in the first paint instead of flashing a
    // loading skeleton first.
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '10s';

    protected int|array|null $columns = 3;

    protected function getStats(): array
    {
        $s = app(SystemHealth::class)->snapshot();

        return [
            $this->cpuStat($s['cpu']),
            $this->memoryStat($s['memory']),
            $this->diskStat($s['disk']),
            Stat::make(__('Services'), $s['counts']['services_active'].' / '.$s['counts']['services_total'])
                ->description(__('active / total'))
                ->descriptionIcon('heroicon-m-rectangle-stack'),
            Stat::make(__('Users'), $s['counts']['users_active'].' / '.$s['counts']['users_total'])
                ->description(__('active / total'))
                ->descriptionIcon('heroicon-m-users'),
            Stat::make(__('Connected now'), (string) $s['counts']['connected_now'])
                ->description(__('devices'))
                ->descriptionIcon('heroicon-m-signal'),
            $this->workerStat($s['worker']),
            $this->failedJobsStat($s['failed_jobs']),
            Stat::make(__('Traffic (24h)'), $this->formatBytes($s['bandwidth_24h']))
                ->description(__('last 24 hours'))
                ->descriptionIcon('heroicon-m-arrows-right-left'),
            Stat::make(__('Uptime'), $this->formatDuration($s['uptime_seconds']))
                ->description(__('since last reboot'))
                ->descriptionIcon('heroicon-m-clock'),
            Stat::make('PHP', PHP_VERSION)
                ->description('Laravel '.app()->version())
                ->descriptionIcon('heroicon-m-code-bracket'),
        ];
    }

    /**
     * @param  array<string, int|float|null>  $cpu
     */
    private function cpuStat(array $cpu): Stat
    {
        $load1 = $cpu['load1'];
        $cores = $cpu['cores'];

        if ($load1 === null) {
            return Stat::make(__('CPU load'), '--')
                ->description(__('unavailable'))
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color('gray');
        }

        $m5 = $cpu['load5'] !== null ? number_format($cpu['load5'], 2) : '--';
        $m15 = $cpu['load15'] !== null ? number_format($cpu['load15'], 2) : '--';

        $description = $cores
            ? __(':cores cores · 5 min :m5 · 15 min :m15', ['cores' => $cores, 'm5' => $m5, 'm15' => $m15])
            : __('5 min :m5 · 15 min :m15', ['m5' => $m5, 'm15' => $m15]);

        // Load relative to core count: at 1.0 per core the box is saturated.
        $ratio = $cores ? $load1 / $cores : $load1;

        return Stat::make(__('CPU load'), number_format($load1, 2))
            ->description($description)
            ->descriptionIcon('heroicon-m-cpu-chip')
            ->color($this->thresholdColor($ratio, 0.7, 1.0));
    }

    /**
     * @param  array<string, int|float|null>  $mem
     */
    private function memoryStat(array $mem): Stat
    {
        if ($mem['percent'] === null) {
            return Stat::make(__('Memory'), '--')
                ->description(__('unavailable'))
                ->descriptionIcon('heroicon-m-circle-stack')
                ->color('gray');
        }

        return Stat::make(__('Memory'), $mem['percent'].'%')
            ->description(__(':used of :total used', [
                'used' => $this->formatBytes($mem['used']),
                'total' => $this->formatBytes($mem['total']),
            ]))
            ->descriptionIcon('heroicon-m-circle-stack')
            ->color($this->thresholdColor($mem['percent'], 75, 90));
    }

    /**
     * @param  array<string, int|float|null>  $disk
     */
    private function diskStat(array $disk): Stat
    {
        if ($disk['percent'] === null) {
            return Stat::make(__('Disk'), '--')
                ->description(__('unavailable'))
                ->descriptionIcon('heroicon-m-server')
                ->color('gray');
        }

        return Stat::make(__('Disk'), $disk['percent'].'%')
            ->description(__(':used of :total used · :free free', [
                'used' => $this->formatBytes($disk['used']),
                'total' => $this->formatBytes($disk['total']),
                'free' => $this->formatBytes($disk['free']),
            ]))
            ->descriptionIcon('heroicon-m-server')
            ->color($this->thresholdColor($disk['percent'], 70, 85));
    }

    /**
     * @param  array{last_run: ?int, age_seconds: ?int, healthy: ?bool}  $worker
     */
    private function workerStat(array $worker): Stat
    {
        if ($worker['healthy'] === null) {
            return Stat::make(__('Worker'), '--')
                ->description(__('no heartbeat yet'))
                ->descriptionIcon('heroicon-m-bolt')
                ->color('gray');
        }

        $healthy = $worker['healthy'];
        $age = (int) $worker['age_seconds'];

        return Stat::make(__('Worker'), $healthy ? __('Running') : __('Stalled'))
            ->description($healthy
                ? __('last run :s s ago', ['s' => $age])
                : __('no heartbeat for :s s', ['s' => $age]))
            ->descriptionIcon('heroicon-m-bolt')
            ->color($healthy ? 'success' : 'danger');
    }

    private function failedJobsStat(int $count): Stat
    {
        return Stat::make(__('Failed jobs'), (string) $count)
            ->description($count === 0 ? __('none') : __('need attention'))
            ->descriptionIcon('heroicon-m-exclamation-triangle')
            ->color($count === 0 ? 'success' : 'danger');
    }

    /**
     * Green until $warn, amber up to $danger, red beyond -- the same three-band
     * scheme for every gauge so a colour means the same thing across the row.
     */
    private function thresholdColor(int|float $value, int|float $warn, int|float $danger): string
    {
        return match (true) {
            $value >= $danger => 'danger',
            $value >= $warn => 'warning',
            default => 'success',
        };
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '--';
        }

        foreach ([[1024 ** 4, 'TB'], [1024 ** 3, 'GB'], [1024 ** 2, 'MB'], [1024, 'KB']] as [$divisor, $unit]) {
            if ($bytes >= $divisor) {
                return round($bytes / $divisor, 1).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    private function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '--';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }
}
