<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardsAdminActions;
use App\Services\Logs\LogExporter;
use App\Services\Reports\ExportArchive;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * The generated-exports folder: the PDF site reports (on demand or on a
 * schedule) and the log CSV zips, listed for download. Mirrors the Backups
 * page, but these hold no key material, so they live under the app's storage
 * and are produced in the web/scheduler process rather than the privileged
 * worker.
 */
class Exports extends Page
{
    use GuardsAdminActions;

    protected string $view = 'filament.pages.exports';

    protected static ?int $navigationSort = 85;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-document-arrow-down';
    }

    public static function getNavigationLabel(): string
    {
        return __('Exports');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Exports');
    }

    public function getScheduleSummary(): string
    {
        return match (config('vpnforge.exports.schedule', 'weekly')) {
            'off' => __('Automatic reports are off.'),
            'daily' => __('A report for each active service is generated every day.'),
            'monthly' => __('A report for each active service is generated on the 1st of each month.'),
            default => __('A report for each active service is generated every Monday.'),
        };
    }

    /**
     * Both actions write their file during the request, so the re-render that
     * follows already lists it -- no polling needed, and the page property that
     * used to claim otherwise did nothing on a Filament page anyway.
     */
    protected function getHeaderActions(): array
    {
        // These produce files that hold every user's browsing history, so
        // generating and exporting them is admin-only, not merely read. As on
        // the Backups page, ->visible hides the button but Filament's
        // mountAction checks isDisabled(), not isVisible() -- so ->disabled()
        // plus the server-side guard inside each closure are what actually stop
        // an auditor mounting these by name.
        return [
            Action::make('generateReports')
                ->label(__('Generate reports now'))
                ->icon('heroicon-o-chart-pie')
                ->visible(static::adminOnly())
                ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                ->action(function (): void {
                    $this->abortUnlessAdmin();

                    Artisan::call('vpnforge:export-report');

                    Notification::make()
                        ->title(__('Reports generated'))
                        ->body(__('A PDF was written for each active service that had activity this week.'))
                        ->success()
                        ->send();
                }),
            Action::make('exportLogs')
                ->label(__('Export logs (CSV)'))
                ->icon('heroicon-o-table-cells')
                ->visible(static::adminOnly())
                ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                ->requiresConfirmation()
                ->modalDescription(__('Writes the traffic, connection and bandwidth logs to a zip of CSV files you can download below.'))
                ->action(function (LogExporter $exporter, ExportArchive $archive): void {
                    $this->abortUnlessAdmin();

                    $zip = $exporter->exportZip();
                    $archive->put('logs-'.now()->format('Y-m-d_His').'.zip', File::get($zip));
                    File::delete($zip);

                    Notification::make()->title(__('Logs exported'))->success()->send();
                }),
        ];
    }

    /**
     * @return array<int, array{name: string, path: string, size: int, created_at: int}>
     */
    public function getExports(): array
    {
        return app(ExportArchive::class)->list();
    }

    public function download(string $name): mixed
    {
        // These exports contain every user's browsing history -- downloading
        // one is admin-only, and a plain Livewire call would otherwise reach
        // this method whether or not the button is shown.
        $this->abortUnlessAdmin();

        $path = app(ExportArchive::class)->resolve($name);

        if ($path === null) {
            Notification::make()->title(__('That file no longer exists'))->danger()->send();

            return null;
        }

        return response()->download($path);
    }

    public function delete(string $name): void
    {
        $this->abortUnlessAdmin();

        $path = app(ExportArchive::class)->resolve($name);

        if ($path !== null) {
            File::delete($path);

            Notification::make()->title(__('Export deleted'))->success()->send();
        }
    }
}
