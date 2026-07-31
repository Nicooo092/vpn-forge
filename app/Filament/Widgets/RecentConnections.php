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
            ->heading('Recent connections')
            ->query(
                ConnectionLog::query()
                    ->with('serviceUser.service')
                    ->latest('connected_at')
                    ->limit(8)
            )
            ->columns([
                TextColumn::make('serviceUser.name')
                    ->label('User')
                    ->weight('bold')
                    ->placeholder('deleted user')
                    ->description(fn (ConnectionLog $record) => $record->serviceUser?->service?->name),
                TextColumn::make('connected_at')
                    ->label('Started')
                    ->since()
                    ->tooltip(fn (ConnectionLog $record) => $record->connected_at?->toDayDateTimeString()),
                TextColumn::make('disconnected_at')
                    ->label('Ended')
                    ->since()
                    ->badge()
                    ->color(fn (ConnectionLog $record) => $record->disconnected_at === null ? 'success' : 'gray')
                    ->placeholder('still connected'),
                TextColumn::make('peer_ip')
                    ->label('From')
                    ->fontFamily('mono')
                    ->placeholder('--'),
            ])
            ->emptyStateIcon('heroicon-o-signal')
            ->emptyStateHeading('No sessions recorded')
            ->emptyStateDescription('A session is logged when polling finds a peer that has handshaken recently.')
            ->paginated(false)
            ->poll('30s');
    }
}
