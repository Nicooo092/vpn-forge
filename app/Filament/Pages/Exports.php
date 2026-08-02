<?php

namespace App\Filament\Pages;

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
    protected string $view = 'filament.pages.exports';

    protected static ?int $navigationSort = 85;

    protected ?string $pollingInterval = '5s';

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-document-arrow-down';
    }

    public static function getNavigationLabel(): string
    {
        return __('Exports');
    }

    public function getScheduleSummary(): string
    {
        return match (config('vpnforge.exports.schedule', 'weekly')) {
            'off' => 'Automatic reports are off.',
            'daily' => 'A report for each active service is generated every day.',
            'monthly' => 'A report for each active service is generated on the 1st of each month.',
            default => 'A report for each active service is generated every Monday.',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateReports')
                ->label('Generate reports now')
                ->icon('heroicon-o-chart-pie')
                ->action(function (): void {
                    Artisan::call('vpnforge:export-report');

                    Notification::make()
                        ->title('Reports generated')
                        ->body('A PDF was written for each active service that had activity this week.')
                        ->success()
                        ->send();
                }),
            Action::make('exportLogs')
                ->label('Export logs (CSV)')
                ->icon('heroicon-o-table-cells')
                ->requiresConfirmation()
                ->modalDescription('Writes the traffic, connection and bandwidth logs to a zip of CSV files you can download below.')
                ->action(function (LogExporter $exporter, ExportArchive $archive): void {
                    $zip = $exporter->exportZip();
                    $archive->put('logs-'.now()->format('Y-m-d_His').'.zip', File::get($zip));
                    File::delete($zip);

                    Notification::make()->title('Logs exported')->success()->send();
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
        $path = app(ExportArchive::class)->resolve($name);

        if ($path === null) {
            Notification::make()->title('That file no longer exists')->danger()->send();

            return null;
        }

        return response()->download($path);
    }

    public function delete(string $name): void
    {
        $path = app(ExportArchive::class)->resolve($name);

        if ($path !== null) {
            File::delete($path);

            Notification::make()->title('Export deleted')->success()->send();
        }
    }
}
