<?php

namespace App\Filament\Pages;

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
    protected string $view = 'filament.pages.backups';

    protected static ?int $navigationSort = 90;

    protected ?string $pollingInterval = '5s';

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-archive-box';
    }

    public static function getNavigationLabel(): string
    {
        return __('Backups');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create backup')
                ->icon('heroicon-o-plus')
                ->requiresConfirmation()
                ->modalDescription('Dumps the database and copies the WireGuard keys, the OpenVPN certificate authority and the service configuration into one archive. It runs in the background and appears below when it is ready.')
                ->action(function (): void {
                    CreateBackup::dispatch();

                    Notification::make()
                        ->title('Backup started')
                        ->body('It will appear in the list once the worker has finished.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return array<int, array{name: string, path: string, size: int, created_at: int}>
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
        $path = $this->resolve($name);

        if ($path === null) {
            Notification::make()->title('That backup no longer exists')->danger()->send();

            return null;
        }

        return response()->download($path);
    }

    public function delete(string $name): void
    {
        $path = $this->resolve($name);

        if ($path !== null) {
            File::delete($path);

            Notification::make()->title('Backup deleted')->success()->send();
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
        $path = $this->resolve($name);

        if ($path === null) {
            Notification::make()->title('That backup no longer exists')->danger()->send();

            return;
        }

        RestoreBackup::dispatch($path);

        Notification::make()
            ->title('Restore started')
            ->body('Overwriting the database and keys in the background. Running tunnels keep their old config until you reboot -- reboot the server once it finishes so the restored configuration takes effect. You will be alerted (if a notification channel is set) only if it fails.')
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
