<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Enums\ServiceUserStatus;
use App\Jobs\Vpn\AddServiceUser;
use App\Jobs\Vpn\RemoveServiceUser;
use App\Models\Service;
use App\Models\ServiceUser;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceUsers';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        /** @var Service $service */
        $service = $this->getOwnerRecord();

        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('tunnel_ip')
                ->label('Tunnel IP')
                ->required()
                ->default(fn () => $this->suggestNextTunnelIp($service))
                ->helperText('Must be inside '.$service->subnet_cidr.' and not already assigned.'),
            Select::make('logging_override')
                ->label('Logging')
                ->options([
                    '' => 'Inherit from service ('.($service->logging_enabled_default ? 'on' : 'off').')',
                    '1' => 'Force on',
                    '0' => 'Force off',
                ])
                ->default(''),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('tunnel_ip')
                    ->label('Tunnel IP')
                    ->fontFamily('mono'),
                TextColumn::make('status')
                    ->badge(),
                IconColumn::make('logging_override')
                    ->label('Logging')
                    ->boolean()
                    ->state(fn (ServiceUser $record) => $record->loggingEffective())
                    ->tooltip(fn (ServiceUser $record) => $record->logging_override === null ? 'Inherited from service default' : 'Overridden for this user'),
                TextColumn::make('last_handshake_at')
                    ->label('Last handshake')
                    ->dateTime()
                    ->since(),
                TextColumn::make('last_connected_at')
                    ->label('Last connected')
                    ->dateTime()
                    ->since(),
                TextColumn::make('last_seen_ip')
                    ->label('Last seen from'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['logging_override'] = $data['logging_override'] === '' ? null : (bool) $data['logging_override'];

                        return $data;
                    })
                    ->after(fn (ServiceUser $record) => AddServiceUser::dispatch($record)),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download config')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (ServiceUser $record) {
                        // Read the copy the privileged worker rendered and
                        // cached at addUser()/applyServiceConfig() time --
                        // this web process has no filesystem access to the
                        // actual private keys/certificates, only the
                        // worker does (see ServiceUser::refreshRenderedClientConfig).
                        $file = $record->clientConfigFile();

                        if ($file === null) {
                            Notification::make()
                                ->title('Config not ready yet')
                                ->body('Still being generated in the background -- try again in a moment.')
                                ->warning()
                                ->send();

                            return null;
                        }

                        return response()->streamDownload(
                            fn () => print ($file->contents),
                            $file->filename,
                        );
                    }),
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Active)
                    ->action(function (ServiceUser $record) {
                        $record->forceFill(['status' => ServiceUserStatus::Revoked])->save();
                        RemoveServiceUser::dispatch($record);

                        Notification::make()
                            ->title('User revoked')
                            ->body('Applying the change in the background...')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Revoked),
            ])
            ->poll('5s');
    }

    private function suggestNextTunnelIp(Service $service): string
    {
        [$network, $prefix] = array_pad(explode('/', $service->subnet_cidr), 2, '24');
        $parts = array_map('intval', explode('.', $network));

        $used = $service->serviceUsers()->pluck('tunnel_ip')->map(function (string $ip) {
            return (int) explode('.', $ip)[3];
        })->all();

        for ($host = 2; $host < 255; $host++) {
            if (! in_array($host, $used, true)) {
                return "{$parts[0]}.{$parts[1]}.{$parts[2]}.{$host}";
            }
        }

        return "{$parts[0]}.{$parts[1]}.{$parts[2]}.254";
    }
}
