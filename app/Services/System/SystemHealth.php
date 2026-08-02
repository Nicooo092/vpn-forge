<?php

namespace App\Services\System;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\BandwidthSample;
use App\Models\ConnectionLog;
use App\Models\Service;
use App\Models\ServiceUser;
use Illuminate\Support\Facades\DB;

/**
 * A snapshot of how the box is doing, read straight from the kernel's /proc
 * files and the app's own tables. Everything here is readable by the
 * unprivileged web process; anything that is not available (e.g. off Linux,
 * during tests) degrades to null rather than erroring.
 */
class SystemHealth
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'disk' => $this->disk(),
            'uptime_seconds' => $this->uptimeSeconds(),
            'counts' => $this->counts(),
            'bandwidth_24h' => $this->bandwidth24h(),
        ];
    }

    /**
     * @return array{load1: ?float, load5: ?float, load15: ?float, cores: ?int}
     */
    private function cpu(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;

        return [
            'load1' => $load[0] ?? null,
            'load5' => $load[1] ?? null,
            'load15' => $load[2] ?? null,
            'cores' => $this->cores(),
        ];
    }

    private function cores(): ?int
    {
        if (! is_readable('/proc/cpuinfo')) {
            return null;
        }

        return substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor') ?: null;
    }

    /**
     * @return array{total: ?int, used: ?int, available: ?int, percent: ?int}
     */
    private function memory(): array
    {
        if (! is_readable('/proc/meminfo')) {
            return ['total' => null, 'used' => null, 'available' => null, 'percent' => null];
        }

        $info = (string) @file_get_contents('/proc/meminfo');
        $total = $this->meminfoValue($info, 'MemTotal');
        $available = $this->meminfoValue($info, 'MemAvailable');

        if ($total === null || $available === null || $total === 0) {
            return ['total' => $total, 'used' => null, 'available' => $available, 'percent' => null];
        }

        $used = $total - $available;

        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'percent' => (int) round($used / $total * 100),
        ];
    }

    private function meminfoValue(string $info, string $key): ?int
    {
        // Values are in kB.
        if (preg_match('/^'.preg_quote($key, '/').':\s+(\d+)\s*kB/mi', $info, $m) === 1) {
            return (int) $m[1] * 1024;
        }

        return null;
    }

    /**
     * @return array{total: ?int, used: ?int, free: ?int, percent: ?int}
     */
    private function disk(): array
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || ! $total) {
            return ['total' => null, 'used' => null, 'free' => null, 'percent' => null];
        }

        $used = (int) ($total - $free);

        return [
            'total' => (int) $total,
            'used' => $used,
            'free' => (int) $free,
            'percent' => (int) round($used / $total * 100),
        ];
    }

    private function uptimeSeconds(): ?int
    {
        if (! is_readable('/proc/uptime')) {
            return null;
        }

        $uptime = (string) @file_get_contents('/proc/uptime');

        return (int) (float) explode(' ', trim($uptime))[0];
    }

    /**
     * @return array{services_active: int, services_total: int, users_active: int, users_total: int, connected_now: int}
     */
    private function counts(): array
    {
        return [
            'services_active' => Service::where('status', ServiceStatus::Active)->count(),
            'services_total' => Service::count(),
            'users_active' => ServiceUser::where('status', ServiceUserStatus::Active)->count(),
            'users_total' => ServiceUser::count(),
            'connected_now' => ConnectionLog::whereNull('disconnected_at')->count(),
        ];
    }

    private function bandwidth24h(): int
    {
        // The per-service aggregate rows (service_user_id null) already sum
        // every user, so this counts each byte once.
        return (int) BandwidthSample::whereNull('service_user_id')
            ->where('sampled_at', '>=', now()->subDay())
            ->sum(DB::raw('bytes_in_delta + bytes_out_delta'));
    }
}
