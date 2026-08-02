<?php

namespace App\Filament\Pages;

use App\Services\System\SystemHealth;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A live read-out of how the box itself is doing -- load, memory, disk, uptime
 * -- alongside the panel's own counts. Disk especially: the DNS traffic log is
 * write-heavy and the most likely thing to fill it.
 */
class ServerHealth extends Page
{
    protected string $view = 'filament.pages.server-health';

    protected static ?int $navigationSort = 86;

    protected ?string $pollingInterval = '10s';

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-cpu-chip';
    }

    public static function getNavigationLabel(): string
    {
        return __('Server health');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Server health');
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return app(SystemHealth::class)->snapshot();
    }

    public function formatBytes(?int $bytes): string
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

    public function formatDuration(?int $seconds): string
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
