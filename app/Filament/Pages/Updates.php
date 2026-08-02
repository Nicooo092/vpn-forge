<?php

namespace App\Filament\Pages;

use App\Services\Update\UpdateChecker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Shows whether a newer version is out and, if so, the exact command to run
 * over SSH to install it. It never performs the upgrade itself -- see
 * UpdateChecker for why that is a deliberate boundary, not a missing feature.
 */
class Updates extends Page
{
    protected string $view = 'filament.pages.updates';

    protected static ?int $navigationSort = 95;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-arrow-path';
    }

    public static function getNavigationLabel(): string
    {
        return __('Updates');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label('Check now')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    app(UpdateChecker::class)->check(fresh: true);

                    Notification::make()->title('Checked for updates')->success()->send();
                }),
        ];
    }

    /**
     * @return array{status: string, current: ?string, behind: int, commits: array<int, string>, checked_at: ?int, message: ?string}
     */
    public function getStatus(): array
    {
        return app(UpdateChecker::class)->check();
    }

    public function getUpgradeCommand(): string
    {
        return app(UpdateChecker::class)->upgradeCommand();
    }
}
