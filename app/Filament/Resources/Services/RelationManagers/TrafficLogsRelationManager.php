<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Enums\TrafficLogKind;
use App\Models\Service;
use App\Models\TrafficLog;
use App\Services\Logs\LogExporter;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only: rows here come only from the Go capture agent, never from
 * admin input.
 */
class TrafficLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'trafficLogs';

    protected static ?string $title = 'Traffic logs';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->badge(),
                TextColumn::make('serviceUser.name')
                    ->label('User')
                    ->placeholder('unknown peer'),
                TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('source_ip')
                    ->label('Source IP')
                    ->fontFamily('mono')
                    ->placeholder('--'),
                TextColumn::make('host')
                    ->searchable()
                    ->placeholder('not resolved')
                    ->limit(50),
                // ->state(), not ->formatStateUsing(): detail is a json column
                // cast to an array, and Filament runs the formatter once per
                // array element and joins the results with ", " -- so a DNS
                // row (3 keys) printed the same summary three times over, and
                // an HTTP row (5 keys) five times. Worse, a null or empty
                // detail is treated as blank before formatting runs at all, so
                // the formatter never fired and the cell came out empty.
                // Computing the state directly sidesteps both.
                TextColumn::make('summary')
                    ->label('Summary')
                    ->state(fn (TrafficLog $record) => $this->summarize($record))
                    ->placeholder('no detail recorded')
                    ->limit(60),
            ])
            ->filters([
                SelectFilter::make('kind')->options(TrafficLogKind::class),
            ])
            ->recordActions([
                Action::make('view_detail')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->schema(fn (TrafficLog $record) => [
                        TextEntry::make('detail_json')
                            ->label('Full detail')
                            ->state(json_encode($record->detail, JSON_PRETTY_PRINT))
                            ->fontFamily('mono'),
                    ])
                    ->modalSubmitAction(false),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Export all logs for this service')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function () {
                        /** @var Service $service */
                        $service = $this->getOwnerRecord();
                        $zipPath = app(LogExporter::class)->exportZip($service->id);

                        return response()
                            ->download($zipPath, "vpnforge-{$service->interface_name}-logs.zip")
                            ->deleteFileAfterSend(true);
                    }),
            ])
            ->emptyStateIcon('heroicon-o-document-magnifying-glass')
            ->emptyStateHeading('No traffic logs')
            ->emptyStateDescription('Rows appear once a user with logging enabled connects and resolves a domain. Clients must be pointed at this service\'s own DNS resolver for their queries to be visible here.')
            ->defaultSort('occurred_at', 'desc')
            ->poll('15s');
    }

    private function summarize(TrafficLog $record): ?string
    {
        $detail = $record->detail ?? [];

        // Let the column's placeholder speak rather than rendering
        // "Query: ? -> ?" out of nothing.
        if ($detail === []) {
            return null;
        }

        return match ($record->kind) {
            TrafficLogKind::Dns => 'Query: '.($detail['query_type'] ?? '?').' -> '.($detail['answer'] ?? '?'),
            TrafficLogKind::Http => ($detail['method'] ?? '?').' '.($detail['path'] ?? '').' ('.($detail['status_code'] ?? '?').')',
        };
    }
}
