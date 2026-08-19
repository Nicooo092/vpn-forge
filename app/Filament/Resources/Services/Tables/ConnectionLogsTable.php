<?php

namespace App\Filament\Resources\Services\Tables;

use App\Models\ConnectionLog;
use App\Services\Geo\GeoLocator;
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
                // Country + ASN of the source IP. Stored on the row when the
                // session opens (so it survives a later database change); rows
                // recorded before the databases existed fall back to a live
                // lookup. Empty when the databases are absent -- read-only and
                // auditor-safe, it never blocks the table.
                TextColumn::make('country_name')
                    ->label(__('Country'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('--')
                    ->state(function (ConnectionLog $record): ?string {
                        $geo = self::geoState($record);

                        if ($geo === null || ($geo['country_name'] === null && $geo['country_code'] === null)) {
                            return null;
                        }

                        $name = $geo['country_name'] ?? $geo['country_code'];

                        return trim(GeoLocator::flagEmoji($geo['country_code']).' '.$name);
                    })
                    ->description(fn (ConnectionLog $record): ?string => self::asnLabel(self::geoState($record)))
                    ->toggleable(),
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

    /**
     * Memoised per row for this request: the country column reads geoState in
     * both its ->state() and its ->description() closure, and for legacy rows
     * (country and asn both null) that falls through to a live GeoLocator
     * lookup -- which would otherwise run twice per row on every 60s poll.
     *
     * @var array<int|string, array{country_code: ?string, country_name: ?string, asn: ?int, as_org: ?string}|null>
     */
    private static array $geoCache = [];

    /**
     * Geo (country + ASN) for a row: prefer what was captured on it when the
     * session opened, else a live lookup for rows predating the databases. Null
     * when nothing is known.
     *
     * @return array{country_code: ?string, country_name: ?string, asn: ?int, as_org: ?string}|null
     */
    private static function geoState(ConnectionLog $record): ?array
    {
        $key = $record->getKey();

        if (array_key_exists($key, self::$geoCache)) {
            return self::$geoCache[$key];
        }

        if ($record->country_code !== null || $record->asn !== null) {
            return self::$geoCache[$key] = [
                'country_code' => $record->country_code,
                'country_name' => $record->country_name,
                'asn' => $record->asn,
                'as_org' => $record->as_org,
            ];
        }

        return self::$geoCache[$key] = app(GeoLocator::class)->locate($record->peer_ip);
    }

    /**
     * @param  array{country_code: ?string, country_name: ?string, asn: ?int, as_org: ?string}|null  $geo
     */
    private static function asnLabel(?array $geo): ?string
    {
        if ($geo === null || $geo['asn'] === null) {
            return null;
        }

        return $geo['as_org'] !== null
            ? 'AS'.$geo['asn'].' '.$geo['as_org']
            : 'AS'.$geo['asn'];
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
