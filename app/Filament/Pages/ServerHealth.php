<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ServerHealthStats;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A live read-out of how the box itself is doing -- load, memory, disk, uptime
 * -- alongside the panel's own counts. Disk especially: the DNS traffic log is
 * write-heavy and the most likely thing to fill it. The read-out itself is the
 * ServerHealthStats widget (native Filament stat cards); this page just hosts
 * and titles it.
 */
class ServerHealth extends Page
{
    protected string $view = 'filament.pages.server-health';

    protected static ?int $navigationSort = 86;

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
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [ServerHealthStats::class];
    }

    // One column so the single stats widget spans the full width and its own
    // grid lays out the cards, rather than being boxed into half the page.
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
