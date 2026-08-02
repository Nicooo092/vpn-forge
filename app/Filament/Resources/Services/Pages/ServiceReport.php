<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ServiceReportData;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * "What did this service's users actually visit." Aggregates the DNS traffic
 * log by domain and by category over a chosen window, so a flat stream of
 * thousands of lookups becomes a readable summary. The heavy lifting lives in
 * ServiceReportData, shared with the PDF and scheduled exports.
 */
class ServiceReport extends Page
{
    use InteractsWithRecord;

    protected string $view = 'filament.pages.service-report';

    protected static ?string $title = 'Report';

    #[Url]
    public string $period = '7d';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public static function getNavigationLabel(): string
    {
        return __('Report');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-chart-pie';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Report -- '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->visible(fn (): bool => $this->data()->hasData())
                ->action(fn () => response()->streamDownload(
                    fn () => print (app(ReportPdf::class)->render($this->record, $this->since(), $this->periodLabel())),
                    "report-{$this->record->interface_name}-{$this->period}.pdf",
                )),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];
    }

    private function periodLabel(): string
    {
        return $this->periodOptions()[$this->period] ?? 'Last 7 days';
    }

    private function since(): Carbon
    {
        return match ($this->period) {
            '24h' => now()->subDay(),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };
    }

    private function data(): ServiceReportData
    {
        return new ServiceReportData($this->record, $this->since());
    }

    protected function getViewData(): array
    {
        $data = $this->data();

        return [
            'hasData' => $data->hasData(),
            'breakdown' => $data->categoryBreakdown(),
            'topDomains' => $data->topDomains(),
            'topUsers' => $data->topUsers(),
        ];
    }

    public static function getResource(): string
    {
        return ServiceResource::class;
    }
}
