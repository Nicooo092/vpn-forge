<?php

namespace App\Filament\Resources\Services\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: rows here come only from PollAllServiceStatuses, never from
 * admin input, so no create/edit/delete actions are exposed.
 */
class ConnectionLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serviceUser.name')
                    ->label(__('User'))
                    ->placeholder(__('deleted user'))
                    ->searchable(),
                TextColumn::make('connected_at')
                    ->label(__('Connected at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('disconnected_at')
                    ->label(__('Disconnected at'))
                    ->dateTime()
                    ->placeholder(__('still connected'))
                    ->sortable(),
                TextColumn::make('peer_ip')
                    ->label(__('From IP'))
                    ->fontFamily('mono')
                    ->placeholder('--'),
                TextColumn::make('bytes_in')
                    ->label(__('Received'))
                    ->formatStateUsing(fn (?int $state) => self::formatBytes($state ?? 0)),
                TextColumn::make('bytes_out')
                    ->label(__('Sent'))
                    ->formatStateUsing(fn (?int $state) => self::formatBytes($state ?? 0)),
            ])
            ->emptyStateIcon('heroicon-o-signal')
            ->emptyStateHeading(__('No connection logs'))
            ->emptyStateDescription(__('A session is recorded when polling finds a peer that handshook within the last few minutes, so a client has to be connected while the service is being polled -- sessions that ended before then are not backfilled.'))
            ->defaultSort('connected_at', 'desc')
            // Sessions are only ever written by the once-a-minute poller
            // (routes/console.php), so anything faster than this refreshes the
            // page six times over for the same rows.
            ->poll('60s');
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = $bytes;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return round($value, 1).' '.$units[$unitIndex];
    }
}
