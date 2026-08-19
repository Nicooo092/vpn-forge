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

    // Without this the heading falls back to the class basename, so the
    // navigation entry reads translated while the page it opens does not.
    public function getTitle(): string|Htmlable
    {
        return __('Updates');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label(__('Check now'))
                ->icon('heroicon-o-arrow-path')
                // Makes an outbound request and rewrites the cached status --
                // a side effect, so admin-only like every other action here.
                ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                ->action(function (): void {
                    abort_unless(auth()->user()?->isAdmin() ?? false, 403);

                    app(UpdateChecker::class)->check(fresh: true);

                    Notification::make()->title(__('Checked for updates'))->success()->send();
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
