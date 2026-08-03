<?php

namespace App\Filament\Widgets;

use App\Models\ConnectionLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentConnections extends TableWidget
{
    protected static ?int $sort = 4;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent connections'))
            ->query(
                ConnectionLog::query()
                    ->with('serviceUser.service')
                    ->latest('connected_at')
                    ->limit(8)
            )
            ->columns([
                TextColumn::make('serviceUser.name')
                    ->label(__('User'))
                    ->weight('bold')
                    ->placeholder(__('deleted user'))
                    ->description(fn (ConnectionLog $record) => $record->serviceUser?->service?->name),
                TextColumn::make('connected_at')
                    ->label(__('Started'))
                    ->since()
                    ->tooltip(fn (ConnectionLog $record) => $record->connected_at?->toDayDateTimeString()),
                TextColumn::make('disconnected_at')
                    ->label(__('Ended'))
                    ->since()
                    ->badge()
                    ->color(fn (ConnectionLog $record) => $record->disconnected_at === null ? 'success' : 'gray')
                    ->placeholder(__('still connected')),
                TextColumn::make('peer_ip')
                    ->label(__('From'))
                    ->fontFamily('mono')
                    ->placeholder('--'),
            ])
            ->emptyStateIcon('heroicon-o-signal')
            ->emptyStateHeading(__('No sessions recorded'))
            ->emptyStateDescription(__('A session is logged when polling finds a peer that has handshaken recently.'))
            ->paginated(false)
            // Sessions are discovered by the once-a-minute poller, so nothing
            // new can appear between two 30-second refreshes.
            ->poll('60s');
    }
}
