<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\ServiceStatus;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\RemoveService;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('protocol')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('interface_name')
                    ->label('Interface')
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('subnet_cidr')
                    ->label('Subnet')
                    ->fontFamily('mono'),
                TextColumn::make('listen_port')
                    ->label('Port')
                    ->formatStateUsing(fn ($record) => "{$record->listen_port}/{$record->transport->value}")
                    ->sortable(),
                TextColumn::make('service_users_count')
                    ->label('Users')
                    ->counts('serviceUsers')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('logging_enabled_default')
                    ->label('Logging (default)')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('protocol')->options(VpnProtocol::class),
                SelectFilter::make('status')->options(ServiceStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
                // Not a plain DeleteAction: tearing down the interface/NAT
                // rules/systemd unit needs CAP_NET_ADMIN, which the web
                // process doesn't have -- this dispatches a privileged job
                // instead, which deletes the record itself once teardown
                // has actually completed.
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This tears down the interface, NAT rules and DNS logging for this service, then deletes it. This cannot be undone.')
                    ->action(function (Service $record) {
                        RemoveService::dispatch($record);

                        Notification::make()
                            ->title('Removing service')
                            ->body('Tearing down in the background -- it will disappear from this list once finished.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
