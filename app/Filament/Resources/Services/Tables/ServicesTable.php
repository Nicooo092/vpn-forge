<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\ServiceStatus;
use App\Enums\VpnProtocol;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\ServiceResource;
use App\Jobs\Vpn\ProvisionService;
use App\Jobs\Vpn\RemoveService;
use App\Models\Service;
use App\Services\Vpn\ConnectivityCheck;
use App\Services\Vpn\ServiceDefinition;
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
                    ->label(__('Name'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('protocol')
                    ->label(__('Protocol'))
                    ->badge(),
                // A service that fails to provision stores why in last_error,
                // which nothing used to surface -- the panel just showed a red
                // "Error" badge and left you to go read the queue worker's
                // journal over SSH to find out what happened.
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->description(fn (Service $record) => $record->status === ServiceStatus::Error
                        ? Str::limit($record->last_error, 60)
                        : null)
                    ->tooltip(fn (Service $record) => $record->status === ServiceStatus::Error
                        ? $record->last_error
                        : null)
                    // Provisioning is the one status that is actively in
                    // progress rather than settled, so it is the one badge worth
                    // animating: the slow breath says the worker is still on it
                    // and the row will change on its own. Every other status is
                    // a resting state and stays still.
                    ->extraAttributes(fn (Service $record) => $record->status === ServiceStatus::Provisioning
                        ? ['class' => 'vf-live']
                        : []),
                TextColumn::make('interface_name')
                    ->label(__('Interface'))
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('subnet_cidr')
                    ->label(__('Subnet'))
                    ->fontFamily('mono'),
                TextColumn::make('listen_port')
                    ->label(__('Port'))
                    ->formatStateUsing(fn ($record) => "{$record->listen_port}/{$record->transport->value}")
                    ->sortable(),
                TextColumn::make('service_users_count')
                    ->label(__('Users'))
                    ->counts('serviceUsers')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('logging_enabled_default')
                    ->label(__('Logging (default)'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('protocol')
                    ->label(__('Protocol'))
                    ->options(VpnProtocol::class),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ServiceStatus::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    // Duplicate a service into the create form, pre-filled with
                    // fresh, non-colliding network details and the source's
                    // non-secret settings. Server keys/PKI are never copied --
                    // the driver mints new ones at provisioning time, keyed off
                    // the new interface_name.
                    Action::make('clone')
                        ->label(__('Clone'))
                        ->icon('heroicon-o-document-duplicate')
                        // Server-side gate: the group's ->visible() hides these
                        // from an Auditor, but Filament's mountAction checks only
                        // isDisabled(), not isVisible() -- so a crafted mount-by-
                        // name would otherwise reach the action. ->disabled()
                        // blocks that at the framework's own mount guard.
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->action(function (Service $record) {
                            session()->put(
                                CreateService::CLONE_SESSION_KEY,
                                app(ServiceDefinition::class)->cloneFormState($record),
                            );

                            return redirect(ServiceResource::getUrl('create'));
                        }),
                    // Download the service's non-secret definition as JSON.
                    // Server key material never leaves the panel -- see
                    // ServiceDefinition::secretConfig().
                    Action::make('export')
                        ->label(__('Export'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->action(fn (Service $record) => response()->streamDownload(
                            fn () => print (app(ServiceDefinition::class)->exportJson($record)),
                            "vpnforge-service-{$record->interface_name}.json",
                        )),
                    Action::make('test')
                        ->label(__('Test connection'))
                        ->icon('heroicon-o-signal')
                        ->modalHeading(fn (Service $record) => __('Diagnostics for :name', ['name' => $record->name]))
                        ->modalWidth(Width::TwoExtraLarge)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close'))
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
                        ->label(__('Retry provisioning'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription(__('Re-runs provisioning for this service. Existing interfaces and connected clients are left alone.'))
                        ->visible(fn (Service $record) => $record->status === ServiceStatus::Error)
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->action(function (Service $record) {
                            ProvisionService::dispatch($record);

                            Notification::make()
                                ->title(__('Retrying provisioning'))
                                ->body(__('Running in the background -- the status will update here on its own.'))
                                ->success()
                                ->send();
                        }),
                    // Not a plain DeleteAction: tearing down the interface/NAT
                    // rules/systemd unit needs CAP_NET_ADMIN, which the web
                    // process doesn't have -- this dispatches a privileged job
                    // instead, which deletes the record itself once teardown
                    // has actually completed.
                    Action::make('remove')
                        ->label(__('Remove'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription(__('This tears down the interface, NAT rules and DNS logging for this service, then deletes it. This cannot be undone.'))
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->action(function (Service $record) {
                            RemoveService::dispatch($record);

                            Notification::make()
                                ->title(__('Removing service'))
                                ->body(__('Tearing down in the background -- it will disappear from this list once finished.'))
                                ->success()
                                ->send();
                        }),
                ])
                    // Edit/retry/remove all change the service; admin-only. An
                    // Auditor sees the services list read-only.
                    ->visible(fn () => auth()->user()?->isAdmin()),
            ])
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(__('No VPN services yet'))
            ->emptyStateDescription(__('Create one to provision a WireGuard or OpenVPN server, then add users to it.'))
            // Status moves through provisioning -> active/error in a background
            // job, so the row has to refresh itself or it looks stuck.
            ->poll('10s')
            ->defaultSort('name');
    }
}
