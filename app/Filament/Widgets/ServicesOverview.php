<?php

namespace App\Filament\Widgets;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

/**
 * The dashboard previously said nothing about the services themselves -- you
 * had to leave it to find out whether anything was actually up.
 */
class ServicesOverview extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Services'))
            ->query(
                Service::query()
                    ->withCount([
                        'serviceUsers as users_count' => fn ($query) => $query
                            ->where('status', ServiceUserStatus::Active),
                        'serviceUsers as connected_count' => fn ($query) => $query
                            ->where('status', ServiceUserStatus::Active)
                            ->where('last_handshake_at', '>=', now()->subMinutes(3)),
                    ])
                    ->orderBy('name')
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->weight('bold'),
                TextColumn::make('protocol')
                    ->label(__('Protocol'))
                    ->badge(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->description(fn (Service $record) => $record->status === ServiceStatus::Error
                        ? Str::limit($record->last_error, 50)
                        : null)
                    ->tooltip(fn (Service $record) => $record->last_error),
                TextColumn::make('connected_count')
                    ->label(__('Connected'))
                    ->badge()
                    ->color(fn (Service $record) => $record->connected_count > 0 ? 'success' : 'gray')
                    ->formatStateUsing(fn (Service $record) => "{$record->connected_count} / {$record->users_count}"),
                TextColumn::make('endpoint')
                    ->label(__('Endpoint'))
                    ->fontFamily('mono')
                    ->placeholder(__('not set'))
                    ->state(fn (Service $record) => isset($record->config['endpoint_host'])
                        ? $record->config['endpoint_host'].':'.$record->listen_port
                        : null)
                    ->copyable(),
                TextColumn::make('subnet_cidr')
                    ->label(__('Subnet'))
                    ->fontFamily('mono'),
            ])
            ->recordUrl(fn (Service $record) => ServiceResource::getUrl('edit', ['record' => $record]))
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(__('No services yet'))
            ->emptyStateDescription(__('Create one from the Services page to provision a WireGuard or OpenVPN server.'))
            ->paginated(false)
            // Status and handshake counts are written by the once-a-minute
            // poller, so a 30-second refresh re-queried unchanged rows.
            ->poll('60s');
    }
}
