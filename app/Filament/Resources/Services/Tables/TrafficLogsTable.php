<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\ServiceUserStatus;
use App\Enums\TrafficLogKind;
use App\Jobs\Vpn\ApplyServiceConfig;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\TrafficLog;
use App\Services\Logs\LogExporter;
use App\Services\Traffic\DomainCategory;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only: rows here come only from the Go capture agent, never from
 * admin input.
 */
class TrafficLogsTable
{
    public static function configure(Table $table, Service $service): Table
    {
        return $table
            ->columns([
                // Eight columns overflowed the page and forced a horizontal
                // scrollbar. These three are still one click away under the
                // column toggle, but none of them earns permanent space: kind
                // repeats on every row and already has a filter, source IP
                // identifies the same peer the User column names, and the
                // summary restates the resolver and answer columns.
                TextColumn::make('kind')
                    ->label(__('Kind'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('serviceUser.name')
                    ->label(__('User'))
                    ->placeholder(__('unknown peer')),
                TextColumn::make('occurred_at')
                    ->label(__('Occurred at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('source_ip')
                    ->label(__('Source IP'))
                    ->fontFamily('mono')
                    ->placeholder('--')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('host')
                    ->label(__('Domain'))
                    ->searchable()
                    ->placeholder(__('not resolved'))
                    ->limit(50),
                TextColumn::make('category')
                    ->label(__('Category'))
                    ->badge()
                    ->state(fn (TrafficLog $record) => DomainCategory::for($record->host))
                    ->toggleable(),
                // The two legs beyond the client's request: who the server
                // asked, and what came back.
                TextColumn::make('via')
                    ->label(__('Resolved via'))
                    ->state(fn (TrafficLog $record) => match (true) {
                        ($record->detail['cached'] ?? false) === true => 'cache',
                        isset($record->detail['forwarded_to'][0]) => implode(', ', $record->detail['forwarded_to']),
                        default => null,
                    })
                    ->badge()
                    ->color(fn (?string $state) => $state === 'cache' ? 'gray' : 'info')
                    // Translated on the way out only: the colour above and the
                    // state itself keep the untranslated marker.
                    ->formatStateUsing(fn (?string $state) => $state === 'cache' ? __('cache') : $state)
                    ->placeholder('--'),
                TextColumn::make('answer')
                    ->label(__('Answer'))
                    ->fontFamily('mono')
                    ->state(fn (TrafficLog $record) => $record->detail['answers'][0] ?? null)
                    ->description(fn (TrafficLog $record) => count($record->detail['answers'] ?? []) > 1
                        ? __('+:count more', ['count' => count($record->detail['answers']) - 1])
                        : null)
                    // The remaining records and the alias chain, without
                    // spending a column on either.
                    ->tooltip(fn (TrafficLog $record) => self::answerTooltip($record))
                    ->placeholder(__('no answer')),
                // ->state(), not ->formatStateUsing(): detail is a json column
                // cast to an array, and Filament runs the formatter once per
                // array element and joins the results with ", " -- so a DNS
                // row (3 keys) printed the same summary three times over, and
                // an HTTP row (5 keys) five times. Worse, a null or empty
                // detail is treated as blank before formatting runs at all, so
                // the formatter never fired and the cell came out empty.
                // Computing the state directly sidesteps both.
                TextColumn::make('summary')
                    ->label(__('Summary'))
                    ->state(fn (TrafficLog $record) => self::summarize($record))
                    ->placeholder(__('no detail recorded'))
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('Kind'))
                    ->options(TrafficLogKind::class),
            ])
            ->recordActions([
                Action::make('view_detail')
                    ->label(__('View'))
                    ->icon('heroicon-o-eye')
                    ->schema(fn (TrafficLog $record) => [
                        TextEntry::make('detail_json')
                            ->label(__('Full detail'))
                            ->state(json_encode($record->detail, JSON_PRETTY_PRINT))
                            ->fontFamily('mono'),
                    ])
                    ->modalSubmitAction(false),
                // Block a domain straight from the log line it appears on --
                // adds it to the service blocklist and re-applies the resolver.
                Action::make('block')
                    ->label(__('Block'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (TrafficLog $record) => filled($record->host)
                        && ! in_array(strtolower($record->host), array_map('strtolower', $service->config['blocked_domains'] ?? []), true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (TrafficLog $record) => __('Block :host?', ['host' => $record->host]))
                    ->modalDescription(__('Adds this domain (and its subdomains) to the blocklist for everyone on this service. Applied within a few seconds.'))
                    ->action(function (TrafficLog $record) use ($service) {
                        $service->refresh();
                        $config = $service->config ?? [];
                        $config['blocked_domains'] = array_values(array_unique([
                            ...($config['blocked_domains'] ?? []),
                            strtolower($record->host),
                        ]));
                        $service->update(['config' => $config]);

                        ApplyServiceConfig::dispatch($service);

                        Notification::make()
                            ->title(__('Blocking :host', ['host' => $record->host]))
                            ->body(__('Applying to the resolver in the background.'))
                            ->success()
                            ->send();
                    }),
            ])
            ->headerActions([
                Action::make('export')
                    ->label(__('Export logs'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function () use ($service) {
                        $zipPath = app(LogExporter::class)->exportZip($service->id);

                        return response()
                            ->download($zipPath, "vpnforge-{$service->interface_name}-logs.zip")
                            ->deleteFileAfterSend(true);
                    }),
            ])
            ->emptyStateIcon('heroicon-o-document-magnifying-glass')
            ->emptyStateHeading(__('No traffic logs'))
            // Generic advice here is useless: the overwhelmingly common reason
            // this table stays empty is that logging is switched off for the
            // very users being watched, and the panel is the only thing that
            // can see that. Name them.
            ->emptyStateDescription(fn () => self::whyEmpty($service))
            ->defaultSort('occurred_at', 'desc')
            // Rows land here in batches from the capture agent, and the empty
            // state runs two extra queries on every refresh, so a fifteen-second
            // poll bought nothing over thirty.
            ->poll('30s');
    }

    private static function whyEmpty(Service $service): string
    {
        $active = $service->serviceUsers()->where('status', ServiceUserStatus::Active)->get();

        if ($active->isEmpty()) {
            return __('This service has no active users yet. Add one on the Users page.');
        }

        $silenced = $active->reject(fn (ServiceUser $user) => $user->loggingEffective());

        if ($silenced->isNotEmpty()) {
            return __('Logging is switched off for :users, so nothing they do is recorded. Change it on the Users page: edit the user and set Logging to "Force on".', ['users' => $silenced->pluck('name')->join(', ', ' and ')]);
        }

        return __('Rows appear once a user connects and resolves a domain. Their device has to be using the config this panel generates, since that is what points it at this service\'s resolver -- a config downloaded before the resolver was set still names a public one.');
    }

    private static function answerTooltip(TrafficLog $record): ?string
    {
        $answers = $record->detail['answers'] ?? [];
        $chain = $record->detail['cname_chain'] ?? [];

        $parts = [];

        if (count($answers) > 1) {
            $parts[] = implode(', ', $answers);
        }

        if ($chain !== []) {
            $parts[] = __('via :chain', ['chain' => implode(' -> ', $chain)]);
        }

        return $parts === [] ? null : implode(' | ', $parts);
    }

    private static function summarize(TrafficLog $record): ?string
    {
        $detail = $record->detail ?? [];

        // Let the column's placeholder speak rather than rendering
        // "Query: ? -> ?" out of nothing.
        if ($detail === []) {
            return null;
        }

        return match ($record->kind) {
            TrafficLogKind::Dns => self::summarizeDns($detail),
            TrafficLogKind::Http => ($detail['method'] ?? '?').' '.($detail['path'] ?? '').' ('.($detail['status_code'] ?? '?').')',
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private static function summarizeDns(array $detail): string
    {
        $answers = $detail['answers'] ?? [];
        $chain = $detail['cname_chain'] ?? [];

        $summary = __(':type query', ['type' => $detail['query_type'] ?? '?']);

        if ($chain !== []) {
            $summary .= ' '.__('via :chain', ['chain' => implode(' -> ', $chain)]);
        }

        return $summary.', '.($answers === []
            ? __('no answer')
            : count($answers).' '.(count($answers) === 1 ? __('answer') : __('answers')));
    }
}
