<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Enums\ServiceUserStatus;
use App\Enums\TrafficLogKind;
use App\Models\Service;
use App\Models\ServiceUser;
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
                    ->label('Domain')
                    ->searchable()
                    ->placeholder('not resolved')
                    ->limit(50),
                // The two legs beyond the client's request: who the server
                // asked, and what came back.
                TextColumn::make('via')
                    ->label('Resolved via')
                    ->state(fn (TrafficLog $record) => match (true) {
                        ($record->detail['cached'] ?? false) === true => 'cache',
                        isset($record->detail['forwarded_to'][0]) => implode(', ', $record->detail['forwarded_to']),
                        default => null,
                    })
                    ->badge()
                    ->color(fn (?string $state) => $state === 'cache' ? 'gray' : 'info')
                    ->placeholder('--'),
                TextColumn::make('answer')
                    ->label('Answer')
                    ->fontFamily('mono')
                    ->state(fn (TrafficLog $record) => $record->detail['answers'][0] ?? null)
                    ->description(fn (TrafficLog $record) => count($record->detail['answers'] ?? []) > 1
                        ? '+'.(count($record->detail['answers']) - 1).' more'
                        : null)
                    ->placeholder('no answer'),
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
            // Generic advice here is useless: the overwhelmingly common reason
            // this table stays empty is that logging is switched off for the
            // very users being watched, and the panel is the only thing that
            // can see that. Name them.
            ->emptyStateDescription(function (): string {
                /** @var Service $service */
                $service = $this->getOwnerRecord();

                $silenced = $service->serviceUsers()
                    ->where('status', ServiceUserStatus::Active)
                    ->get()
                    ->reject(fn (ServiceUser $user) => $user->loggingEffective());

                if ($silenced->isNotEmpty()) {
                    return 'Logging is switched off for '.$silenced->pluck('name')->join(', ', ' and ')
                        .', so nothing they do is recorded. Change it on the Service Users tab: edit the user and set Logging to "Force on".';
                }

                if ($service->serviceUsers()->where('status', ServiceUserStatus::Active)->doesntExist()) {
                    return 'This service has no active users yet. Add one on the Service Users tab.';
                }

                return 'Rows appear once a user connects and resolves a domain. Their device has to be using the config this panel generates, since that is what points it at this service\'s resolver -- a config downloaded before the resolver was set still names a public one.';
            })
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
            TrafficLogKind::Dns => $this->summarizeDns($detail),
            TrafficLogKind::Http => ($detail['method'] ?? '?').' '.($detail['path'] ?? '').' ('.($detail['status_code'] ?? '?').')',
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function summarizeDns(array $detail): string
    {
        $answers = $detail['answers'] ?? [];
        $chain = $detail['cname_chain'] ?? [];

        $summary = ($detail['query_type'] ?? '?').' query';

        if ($chain !== []) {
            $summary .= ' via '.implode(' -> ', $chain);
        }

        return $summary.', '.($answers === []
            ? 'no answer'
            : count($answers).' '.(count($answers) === 1 ? 'answer' : 'answers'));
    }
}
