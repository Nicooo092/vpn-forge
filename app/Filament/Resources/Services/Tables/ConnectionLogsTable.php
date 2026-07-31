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
                    ->label('User')
                    ->placeholder('deleted user')
                    ->searchable(),
                TextColumn::make('connected_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('disconnected_at')
                    ->dateTime()
                    ->placeholder('still connected')
                    ->sortable(),
                TextColumn::make('peer_ip')
                    ->label('From IP')
                    ->fontFamily('mono')
                    ->placeholder('--'),
                TextColumn::make('bytes_in')
                    ->label('Received')
                    ->formatStateUsing(fn (?int $state) => self::formatBytes($state ?? 0)),
                TextColumn::make('bytes_out')
                    ->label('Sent')
                    ->formatStateUsing(fn (?int $state) => self::formatBytes($state ?? 0)),
            ])
            ->emptyStateIcon('heroicon-o-signal')
            ->emptyStateHeading('No connection logs')
            ->emptyStateDescription('A session is recorded when polling finds a peer that handshook within the last few minutes, so a client has to be connected while the service is being polled -- sessions that ended before then are not backfilled.')
            ->defaultSort('connected_at', 'desc')
            ->poll('10s');
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
