<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\ServiceStatus;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\ProvisionService;
use App\Jobs\Vpn\RemoveService;
use App\Models\Service;
use App\Services\Vpn\ConnectivityCheck;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

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
                // A service that fails to provision stores why in last_error,
                // which nothing used to surface -- the panel just showed a red
                // "Error" badge and left you to go read the queue worker's
                // journal over SSH to find out what happened.
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (Service $record) => $record->status === ServiceStatus::Error
                        ? Str::limit($record->last_error, 60)
                        : null)
                    ->tooltip(fn (Service $record) => $record->status === ServiceStatus::Error
                        ? $record->last_error
                        : null),
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
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('test')
                        ->label('Test connection')
                        ->icon('heroicon-o-signal')
                        ->modalHeading(fn (Service $record) => "Diagnostics for {$record->name}")
                        ->modalWidth(Width::TwoExtraLarge)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (Service $record) => view('filament.connectivity-check', [
                            'checks' => app(ConnectivityCheck::class)->run($record),
                            'port' => $record->listen_port,
                            'transport' => $record->transport->value,
                        ])),
                    // Recovering a service stuck in Error used to mean SSHing
                    // in and re-dispatching the job by hand. provisionService()
                    // skips interface creation when the config file already
                    // exists, so re-running it on a half-provisioned service
                    // finishes the missing steps without disturbing a tunnel
                    // that is already up.
                    Action::make('retry')
                        ->label('Retry provisioning')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Re-runs provisioning for this service. Existing interfaces and connected clients are left alone.')
                        ->visible(fn (Service $record) => $record->status === ServiceStatus::Error)
                        ->action(function (Service $record) {
                            ProvisionService::dispatch($record);

                            Notification::make()
                                ->title('Retrying provisioning')
                                ->body('Running in the background -- the status will update here on its own.')
                                ->success()
                                ->send();
                        }),
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
                ]),
            ])
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading('No VPN services yet')
            ->emptyStateDescription('Create one to provision a WireGuard or OpenVPN server, then add users to it.')
            // Status moves through provisioning -> active/error in a background
            // job, so the row has to refresh itself or it looks stuck.
            ->poll('10s')
            ->defaultSort('name');
    }
}
