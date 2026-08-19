<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardsAdminActions;
use App\Jobs\Maintenance\CreateBackup;
use App\Jobs\Maintenance\RestoreBackup;
use App\Services\Backup\BackupArchive;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\File;

class Backups extends Page
{
    use GuardsAdminActions;

    protected string $view = 'filament.pages.backups';

    protected static ?int $navigationSort = 90;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-archive-box';
    }

    public static function getNavigationLabel(): string
    {
        return __('Backups');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Backups');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('Create backup'))
                ->icon('heroicon-o-plus')
                ->visible(static::adminOnly())
                // ->visible hides the button, but Filament's mountAction checks
                // isDisabled(), not isVisible() -- so ->disabled() plus the
                // server-side guard below are what actually stop an auditor
                // mounting this by name to dispatch the key-vault backup job.
                ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                ->requiresConfirmation()
                ->modalDescription(__('Dumps the database and copies the WireGuard keys, the OpenVPN certificate authority and the service configuration into one archive. It runs in the background on the privileged worker.'))
                // The archive is written by a queued job, so it does not exist
                // yet when this returns and the page does not watch for it.
                // Saying so beats promising a list that never updates on its
                // own.
                ->action(function (): void {
                    $this->abortUnlessAdmin();

                    CreateBackup::dispatch();

                    Notification::make()
                        ->title(__('Backup started'))
                        ->body(__('It takes a few seconds. Reload this page to see it in the list below.'))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return array<int, array{name: string, path: string, size: int, created_at: int, encrypted: bool}>
     */
    public function getBackups(): array
    {
        return app(BackupArchive::class)->list();
    }

    public function isSecure(): bool
    {
        return request()->isSecure();
    }

    public function download(string $name): mixed
    {
        // The archive is a plaintext vault of every key on the box; extracting
        // it is admin-only, not merely read.
        $this->abortUnlessAdmin();

        $path = $this->resolve($name);

        if ($path === null) {
            Notification::make()->title(__('That backup no longer exists'))->danger()->send();

            return null;
        }

        return response()->download($path);
    }

    public function delete(string $name): void
    {
        $this->abortUnlessAdmin();

        $path = $this->resolve($name);

        if ($path !== null) {
            File::delete($path);

            Notification::make()->title(__('Backup deleted'))->success()->send();
        }
    }

    /**
     * Overwrites the live database and on-disk key material with the archive's.
     * Runs on the privileged worker (the web process cannot write the PKI).
     * Resolves the name against the listing rather than joining it onto a path,
     * so a browser-supplied value cannot escape the backups directory.
     */
    public function restore(string $name): void
    {
        // Restore overwrites the live database and all key material -- the most
        // destructive thing the panel can do. Admin-only, guarded server-side.
        $this->abortUnlessAdmin();

        $path = $this->resolve($name);

        if ($path === null) {
            Notification::make()->title(__('That backup no longer exists'))->danger()->send();

            return;
        }

        RestoreBackup::dispatch($path);

        Notification::make()
            ->title(__('Restore started'))
            ->body(__('Overwriting the database and keys in the background. Running tunnels keep their old config until you reboot -- reboot the server once it finishes so the restored configuration takes effect. You will be alerted (if a notification channel is set) only if it fails.'))
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * Resolve a name coming from the browser back to a real archive, by
     * matching it against the listing rather than joining it onto a path --
     * a name is user input, and joining would let "../.." out of the
     * directory entirely.
     */
    private function resolve(string $name): ?string
    {
        foreach ($this->getBackups() as $backup) {
            if ($backup['name'] === $name) {
                return $backup['path'];
            }
        }

        return null;
    }
}
