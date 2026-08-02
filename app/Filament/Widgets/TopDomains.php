<?php

namespace App\Filament\Widgets;

use App\Enums\TrafficLogKind;
use App\Models\TrafficLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

/**
 * DNS visibility is the point of the capture agent, so what it is actually
 * seeing belongs on the front page rather than three clicks into a service.
 */
class TopDomains extends TableWidget
{
    protected static ?int $sort = 5;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Top domains (24h)'))
            ->query(
                TrafficLog::query()
                    ->where('kind', TrafficLogKind::Dns)
                    ->where('occurred_at', '>=', now()->subDay())
                    ->whereNotNull('host')
                    // MIN(id) only so each grouped row still has a primary key
                    // for the table to identify it by.
                    ->selectRaw('MIN(id) as id, host, COUNT(*) as hits, MAX(occurred_at) as last_seen')
                    ->groupBy('host')
                    ->orderByDesc('hits')
                    ->limit(8)
            )
            ->columns([
                TextColumn::make('host')
                    ->label(__('Domain'))
                    ->weight('bold')
                    ->limit(40),
                TextColumn::make('hits')
                    ->label(__('Queries'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('last_seen')
                    ->label(__('Last seen'))
                    ->since()
                    ->state(fn ($record) => Carbon::parse($record->last_seen)),
            ])
            ->emptyStateIcon('heroicon-o-globe-alt')
            ->emptyStateHeading(__('No DNS activity yet'))
            ->emptyStateDescription(__('Queries appear once a user with logging enabled connects, provided clients are pointed at this service\'s own resolver.'))
            ->paginated(false)
            ->poll('30s');
    }
}
