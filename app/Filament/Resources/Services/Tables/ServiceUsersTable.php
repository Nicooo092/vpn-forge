<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\ServiceUserStatus;
use App\Enums\VpnProtocol;
use App\Filament\Resources\ServiceUsers\ServiceUserResource;
use App\Jobs\Vpn\AddServiceUser;
use App\Jobs\Vpn\ApplyServiceConfig;
use App\Jobs\Vpn\RemoveServiceUser;
use App\Jobs\Vpn\RotateUserKeys;
use App\Models\ConfigLink;
use App\Models\ServiceUser;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

class ServiceUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->url(fn (ServiceUser $record) => ServiceUserResource::getUrl('view', ['record' => $record])),
                TextColumn::make('tunnel_ip')
                    ->label('Tunnel IP')
                    ->fontFamily('mono'),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (ServiceUser $record) => $record->suspended_reason),
                TextColumn::make('labels')
                    ->badge()
                    ->separator(',')
                    ->placeholder('--')
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->since()
                    ->placeholder('never')
                    ->tooltip(fn (ServiceUser $record) => $record->expires_at?->toDayDateTimeString())
                    ->color(fn (ServiceUser $record) => $record->isExpired() ? 'danger' : null)
                    ->toggleable(),
                TextColumn::make('data_limit_bytes')
                    ->label('Allowance')
                    ->state(fn (ServiceUser $record) => $record->data_limit_bytes === null
                        ? null
                        : self::formatBytes($record->dataUsedBytes()).' of '.self::formatBytes($record->data_limit_bytes))
                    ->description(fn (ServiceUser $record) => $record->dataUsageRatio() === null
                        ? null
                        : round($record->dataUsageRatio() * 100).'% used')
                    ->color(fn (ServiceUser $record) => $record->isOverDataLimit() ? 'danger' : null)
                    ->placeholder('unlimited')
                    ->toggleable(),
                IconColumn::make('logging_override')
                    ->label('Logging')
                    ->boolean()
                    ->state(fn (ServiceUser $record) => $record->loggingEffective())
                    // Say what the icon means for capture, not just where the
                    // setting came from -- a red cross here is the single most
                    // common reason the traffic log stays empty.
                    ->tooltip(fn (ServiceUser $record) => ($record->loggingEffective()
                        ? 'Connections, DNS lookups and plaintext HTTP are recorded for this user'
                        : 'Nothing is recorded for this user, and the traffic log stays empty for them')
                        .($record->logging_override === null ? ' (inherited from the service default)' : ' (set on this user)')),
                // These stay empty until the service has been polled at least
                // once with the peer present, which is a normal state for a
                // user who has never connected -- say so explicitly rather
                // than rendering a blank cell that reads as broken.
                // No ->dateTime() alongside ->since(): the latter overwrites
                // the formatter the former sets. The absolute timestamp lives
                // in the tooltip instead.
                TextColumn::make('last_handshake_at')
                    ->label('Last handshake')
                    ->since()
                    ->placeholder('never connected')
                    ->tooltip(fn (ServiceUser $record) => $record->last_handshake_at?->toDayDateTimeString())
                    ->sortable(),
                // Hidden by default, both of them: last connected only ever
                // differs from last handshake by the length of one session,
                // and the peer address is rarely what you came to look at.
                // Eight columns did not fit and the table scrolled sideways.
                TextColumn::make('last_connected_at')
                    ->label('Last connected')
                    ->since()
                    ->placeholder('never connected')
                    ->tooltip(fn (ServiceUser $record) => $record->last_connected_at?->toDayDateTimeString())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_ip')
                    ->label('Last seen from')
                    ->placeholder('--')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Stacked rather than written on one line: "738.6 MB in /
                // 3.6 GB out" is the widest cell in the table by some margin.
                TextColumn::make('traffic')
                    ->label('Traffic')
                    ->state(fn (ServiceUser $record) => $record->last_cumulative_bytes_in + $record->last_cumulative_bytes_out === 0
                        ? null
                        : self::formatBytes($record->last_cumulative_bytes_in).' in')
                    ->description(fn (ServiceUser $record) => $record->last_cumulative_bytes_in + $record->last_cumulative_bytes_out === 0
                        ? null
                        : self::formatBytes($record->last_cumulative_bytes_out).' out')
                    ->placeholder('no traffic yet')
                    ->tooltip('Cumulative since the tunnel was last brought up'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add user')
                    ->mutateFormDataUsing(self::normaliseUserData(...))
                    ->after(fn (ServiceUser $record) => AddServiceUser::dispatch($record)),
            ])
            // Rendered inline these overflow the row and push the telemetry
            // columns out of view -- collapse them into one dropdown.
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        // Transform the record's own attributes rather than
                        // supplying a hand-picked subset: fillForm() with a
                        // partial array *replaces* the default full-record
                        // fill, which silently blanked labels, expiry and the
                        // allowance on every edit and wrote those nulls back on
                        // save. mutateRecordDataUsing() keeps every column and
                        // only translates the one field the form re-shapes.
                        ->mutateRecordDataUsing(function (array $data): array {
                            // The select trades in strings so it can offer
                            // "inherit" alongside the two booleans.
                            $data['logging_override'] = match ($data['logging_override'] ?? null) {
                                true => '1',
                                false => '0',
                                default => '',
                            };

                            return $data;
                        })
                        ->mutateFormDataUsing(self::normaliseUserData(...))
                        ->after(function (ServiceUser $record): void {
                            // Tunnel address and DNS change what a client config
                            // should contain, so those need re-applying AND a
                            // fresh download. A speed limit is enforced entirely
                            // server-side, so it re-applies but the config the
                            // user already has stays valid. A logging change
                            // needs nothing: the capture agent re-reads the user
                            // table on its own.
                            $configChanged = $record->wasChanged('tunnel_ip') || $record->wasChanged('dns_override');
                            $shapingChanged = $record->wasChanged('rate_limit_kbps');

                            if (! $configChanged && ! $shapingChanged) {
                                return;
                            }

                            ApplyServiceConfig::dispatch($record->service);

                            if ($configChanged) {
                                Notification::make()
                                    ->title($record->wasChanged('tunnel_ip') ? 'Tunnel address changed' : 'DNS changed')
                                    ->body('Re-applying in the background. Download this user\'s config again -- the previous one is now out of date.')
                                    ->warning()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Speed limit updated')
                                    ->body('Applying on the server in the background. The user keeps their current config.')
                                    ->success()
                                    ->send();
                            }
                        }),
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
                        // Wide enough that a dense config still renders large
                        // enough to scan -- see the view for the measurements.
                        ->modalWidth(Width::TwoExtraLarge)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (ServiceUser $record) => view(
                            'filament.service-user-qr',
                            self::qrModalData($record),
                        )),
                    // Hand-off without sending key material yourself: mint a
                    // public link the person opens once to collect their own
                    // config. The URL is shown once, here, and never again --
                    // only its hash is stored.
                    Action::make('share')
                        ->label('Share link')
                        ->icon('heroicon-o-link')
                        ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Active)
                        ->modalHeading(fn (ServiceUser $record) => "One-time link for {$record->name}")
                        ->modalDescription('Creates a public link this person opens to collect their own config, so you never have to send the file. The link is shown once -- copy it before closing.')
                        ->modalSubmitActionLabel('Create link')
                        ->schema([
                            Select::make('expiry')
                                ->label('Link expires')
                                ->options([
                                    '1' => 'In 1 hour',
                                    '24' => 'In 24 hours',
                                    '168' => 'In 7 days',
                                    '0' => 'No expiry (until used)',
                                ])
                                ->default('24')
                                ->selectablePlaceholder(false)
                                ->required(),
                            Select::make('max_downloads')
                                ->label('Can be opened')
                                ->options([
                                    '1' => 'Once',
                                    '3' => 'Up to 3 times',
                                    '10' => 'Up to 10 times',
                                ])
                                ->default('1')
                                ->selectablePlaceholder(false)
                                ->required(),
                        ])
                        ->action(function (ServiceUser $record, array $data): void {
                            $hours = (int) $data['expiry'];

                            [, $token] = ConfigLink::mintFor(
                                $record,
                                $hours > 0 ? now()->addHours($hours) : null,
                                (int) $data['max_downloads'],
                                Auth::id(),
                            );

                            $url = route('config-link.show', ['token' => $token]);

                            Notification::make()
                                ->title('One-time link ready')
                                ->body(new HtmlString(
                                    '<p style="margin-bottom:.35rem">Send this to '.e($record->name).' -- it is shown only once:</p>'
                                    .'<code style="word-break:break-all;font-size:12px;line-height:1.4">'.e($url).'</code>'
                                ))
                                ->success()
                                ->persistent()
                                ->send();
                        }),
                    Action::make('cancelLinks')
                        ->label('Cancel share links')
                        ->icon('heroicon-o-link-slash')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('Kills every share link for this user that has not already been used or expired.')
                        ->visible(fn (ServiceUser $record) => $record->configLinks()->active()->exists())
                        ->action(function (ServiceUser $record): void {
                            $count = $record->configLinks()->active()->update(['revoked_at' => now()]);

                            Notification::make()
                                ->title($count === 1 ? '1 link cancelled' : "{$count} links cancelled")
                                ->success()
                                ->send();
                        }),
                    Action::make('rotate')
                        ->label('Regenerate keys')
                        ->icon('heroicon-o-key')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(fn (ServiceUser $record) => "Regenerate keys for {$record->name}")
                        ->modalDescription('Issues new key material and stops the current config working immediately -- what a lost or stolen device calls for. The user keeps their name, address, labels and history. They will need the new config.')
                        ->modalSubmitActionLabel('Regenerate')
                        ->visible(fn (ServiceUser $record) => $record->status !== ServiceUserStatus::Revoked)
                        ->action(function (ServiceUser $record) {
                            RotateUserKeys::dispatch($record);

                            Notification::make()
                                ->title('Regenerating keys')
                                ->body('The old config stops working as soon as this finishes. Download the new one and re-import it on the device.')
                                ->warning()
                                ->send();
                        }),
                    // Suspend and resume are the reversible pair; revoke below
                    // is the one-way door.
                    Action::make('suspend')
                        ->label('Suspend')
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Blocks this user without destroying their keys. You can turn them back on at any time.')
                        ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Active)
                        ->action(function (ServiceUser $record) {
                            $record->forceFill([
                                'status' => ServiceUserStatus::Suspended,
                                'suspended_reason' => 'suspended by hand',
                            ])->save();

                            ApplyServiceConfig::dispatch($record->service);

                            Notification::make()->title('User suspended')->success()->send();
                        }),
                    Action::make('resume')
                        ->label('Resume')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Suspended)
                        ->action(function (ServiceUser $record) {
                            // Refuse rather than let them straight back in to
                            // be suspended again by the next enforcement run.
                            if ($record->isExpired()) {
                                Notification::make()
                                    ->title('Expiry date has passed')
                                    ->body('Push the expiry date back first, or clear it.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            if ($record->isOverDataLimit()) {
                                Notification::make()
                                    ->title('Allowance is still used up')
                                    ->body('Raise the allowance or move its start date forward first.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $record->forceFill([
                                'status' => ServiceUserStatus::Active,
                                'suspended_reason' => null,
                            ])->save();

                            ApplyServiceConfig::dispatch($record->service);

                            Notification::make()->title('User resumed')->success()->send();
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
                ]),
            ])
            ->filters([
                SelectFilter::make('status')->options(ServiceUserStatus::class),
                SelectFilter::make('labels')
                    ->label('Label')
                    // labels is a json array, so match the quoted value inside
                    // it rather than comparing the column.
                    ->options(fn () => ServiceUser::query()
                        ->whereNotNull('labels')
                        ->pluck('labels')
                        ->flatten()
                        ->unique()
                        ->sort()
                        ->mapWithKeys(fn (string $label) => [$label => $label])
                        ->all())
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('labels', $data['value'])
                        : $query),
            ])
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading('No users yet')
            ->emptyStateDescription('Add a user to generate their keys and a downloadable client config. Telemetry appears here once they connect.')
            ->poll('5s');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normaliseUserData(array $data): array
    {
        $data['logging_override'] = $data['logging_override'] === '' ? null : (bool) $data['logging_override'];

        // An empty TagsInput submits [], but the column's absent state is null.
        // Store null so a user who never had a DNS override does not register a
        // spurious change on an unrelated edit (which would needlessly
        // re-apply the whole service config).
        if (array_key_exists('dns_override', $data)) {
            $data['dns_override'] = filled($data['dns_override']) ? array_values($data['dns_override']) : null;
        }

        return $data;
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

    /**
     * @return array<string, ?string>
     */
    private static function qrModalData(ServiceUser $record): array
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
            $dataUri = self::qrDataUri($file->contents);
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

    private static function qrDataUri(string $contents): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(320),
            new SvgImageBackEnd,
        ));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($contents));
    }
}
