<?php

namespace App\Filament\Resources\Services\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: rows here come only from PollAllServiceStatuses, never from
 * admin input, so no create/edit/delete actions are exposed.
 */
class ConnectionLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'connectionLogs';

    protected static ?string $title = 'Connection history';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serviceUser.name')
                    ->label('User'),
                TextColumn::make('connected_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('disconnected_at')
                    ->dateTime()
                    ->placeholder('still connected')
                    ->sortable(),
                TextColumn::make('peer_ip')
                    ->label('From IP'),
                TextColumn::make('bytes_in')
                    ->label('Received')
                    ->formatStateUsing(fn (?int $state) => $this->formatBytes($state ?? 0)),
                TextColumn::make('bytes_out')
                    ->label('Sent')
                    ->formatStateUsing(fn (?int $state) => $this->formatBytes($state ?? 0)),
            ])
            ->defaultSort('connected_at', 'desc')
            ->poll('10s');
    }

    private function formatBytes(int $bytes): string
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
