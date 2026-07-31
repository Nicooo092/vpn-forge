<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Enums\ServiceUserStatus;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\AddServiceUser;
use App\Jobs\Vpn\RemoveServiceUser;
use App\Models\Service;
use App\Models\ServiceUser;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
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
use Throwable;

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
                Action::make('qr')
                    ->label('QR code')
                    ->icon('heroicon-o-qr-code')
                    // WireGuard only. An .ovpn embeds the CA, the client
                    // certificate and its private key, which runs to several
                    // kilobytes -- well past what a QR code can carry (~2.9 KB
                    // at the absolute theoretical maximum, and much less while
                    // staying scannable by a phone). A WireGuard config is
                    // ~330 bytes.
                    ->visible(fn (ServiceUser $record) => $record->service->protocol === VpnProtocol::WireGuard)
                    ->modalHeading(fn (ServiceUser $record) => "Scan to import {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (ServiceUser $record) => view(
                        'filament.service-user-qr',
                        $this->qrModalData($record),
                    )),
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

    /**
     * @return array<string, ?string>
     */
    private function qrModalData(ServiceUser $record): array
    {
        // Same cached copy the download action reads -- the web process can't
        // reach the key material itself, only the privileged worker can.
        $file = $record->clientConfigFile();

        if ($file === null) {
            return [
                'dataUri' => null,
                'message' => 'The config is still being generated in the background -- close this and reopen it in a moment.',
                'tunnelName' => $record->name,
            ];
        }

        try {
            $dataUri = $this->qrDataUri($file->contents);
        } catch (Throwable) {
            // Only reachable if a service config pushes the rendered file past
            // QR capacity (a very long custom AllowedIPs list, say).
            return [
                'dataUri' => null,
                'message' => 'This config is too large to fit in a QR code -- use "Download config" instead.',
                'tunnelName' => $record->name,
            ];
        }

        return [
            'dataUri' => $dataUri,
            'message' => null,
            'tunnelName' => $record->name,
        ];
    }

    private function qrDataUri(string $contents): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(320),
            new SvgImageBackEnd,
        ));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($contents));
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
