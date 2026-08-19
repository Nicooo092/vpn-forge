<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\QuotaAction;
use App\Enums\ServiceUserStatus;
use App\Enums\VpnProtocol;
use App\Filament\Resources\Services\Schemas\ServiceUserForm;
use App\Filament\Resources\ServiceUsers\ServiceUserResource;
use App\Jobs\Vpn\AddServiceUser;
use App\Jobs\Vpn\ApplyServiceConfig;
use App\Jobs\Vpn\EnforceAccessWindows;
use App\Jobs\Vpn\RemoveServiceUser;
use App\Jobs\Vpn\RotateUserKeys;
use App\Models\ConfigLink;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\ServiceUsers\CsvUserImporter;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ServiceUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->weight('bold')
                    ->url(fn (ServiceUser $record) => ServiceUserResource::getUrl('view', ['record' => $record])),
                TextColumn::make('tunnel_ip')
                    ->label(__('Tunnel IP'))
                    ->fontFamily('mono'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    // suspended_reason is written by the enforcement jobs and by
                    // the suspend action below as a stable English key, so what
                    // is stored never depends on the admin's locale. Translate
                    // it here, at the only place it is read by a human.
                    ->description(fn (ServiceUser $record) => match (true) {
                        // A throttled user stays Active, so surface the throttle
                        // here or it would be invisible in the table.
                        $record->quota_throttled => __('throttled (data limit)'),
                        $record->suspended_reason !== null => __($record->suspended_reason),
                        default => null,
                    }),
                TextColumn::make('labels')
                    ->label(__('Labels'))
                    ->badge()
                    ->separator(',')
                    ->placeholder('--')
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label(__('Expires'))
                    ->since()
                    ->placeholder(__('never'))
                    ->tooltip(fn (ServiceUser $record) => $record->expires_at?->toDayDateTimeString())
                    ->color(fn (ServiceUser $record) => $record->isExpired() ? 'danger' : null)
                    ->toggleable(),
                TextColumn::make('data_limit_bytes')
                    ->label(__('Allowance'))
                    ->state(fn (ServiceUser $record) => $record->data_limit_bytes === null
                        ? null
                        : __(':used of :limit', ['used' => self::formatBytes($record->dataUsedBytes()), 'limit' => self::formatBytes($record->data_limit_bytes)]))
                    // One dataUsageRatio() call per closure. This cell used to
                    // ask for the usage figure three times a row -- twice in the
                    // description and once more through isOverDataLimit() --
                    // and every one of those aggregates over the bandwidth
                    // samples. The ratio already answers both questions:
                    // it is null exactly when there is no allowance, and it is
                    // capped at 1, so 1.0 means the allowance is used up.
                    ->description(function (ServiceUser $record): ?string {
                        $ratio = $record->dataUsageRatio();

                        return $ratio === null
                            ? null
                            : __(':percent% used', ['percent' => round($ratio * 100)]);
                    })
                    // isOverDataLimit() rather than testing the ratio: the two
                    // disagree for a zero-byte allowance, where the ratio is
                    // null (no allowance to be over) but the user genuinely is
                    // over it. The form cannot produce a zero limit today, so
                    // this is only a guard against one arriving another way --
                    // and it is free, because dataUsedBytes() is memoised per
                    // instance, so this asks the same cached figure the
                    // description already used.
                    ->color(fn (ServiceUser $record): ?string => $record->isOverDataLimit() ? 'danger' : null)
                    ->placeholder(__('unlimited'))
                    ->toggleable(),
                IconColumn::make('logging_override')
                    ->label(__('Logging'))
                    ->boolean()
                    ->state(fn (ServiceUser $record) => $record->loggingEffective())
                    // Say what the icon means for capture, not just where the
                    // setting came from -- a red cross here is the single most
                    // common reason the traffic log stays empty.
                    ->tooltip(fn (ServiceUser $record) => ($record->loggingEffective()
                        ? __('Connections, DNS lookups and plaintext HTTP are recorded for this user')
                        : __('Nothing is recorded for this user, and the traffic log stays empty for them'))
                        .($record->logging_override === null ? __(' (inherited from the service default)') : __(' (set on this user)'))),
                // These stay empty until the service has been polled at least
                // once with the peer present, which is a normal state for a
                // user who has never connected -- say so explicitly rather
                // than rendering a blank cell that reads as broken.
                // No ->dateTime() alongside ->since(): the latter overwrites
                // the formatter the former sets. The absolute timestamp lives
                // in the tooltip instead.
                TextColumn::make('last_handshake_at')
                    ->label(__('Last handshake'))
                    ->since()
                    ->placeholder(__('never connected'))
                    ->tooltip(fn (ServiceUser $record) => $record->last_handshake_at?->toDayDateTimeString())
                    ->sortable(),
                // Hidden by default, both of them: last connected only ever
                // differs from last handshake by the length of one session,
                // and the peer address is rarely what you came to look at.
                // Eight columns did not fit and the table scrolled sideways.
                TextColumn::make('last_connected_at')
                    ->label(__('Last connected'))
                    ->since()
                    ->placeholder(__('never connected'))
                    ->tooltip(fn (ServiceUser $record) => $record->last_connected_at?->toDayDateTimeString())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_ip')
                    ->label(__('Last seen from'))
                    ->placeholder('--')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Stacked rather than written on one line: "738.6 MB in /
                // 3.6 GB out" is the widest cell in the table by some margin.
                TextColumn::make('traffic')
                    ->label(__('Traffic'))
                    ->state(fn (ServiceUser $record) => $record->last_cumulative_bytes_in + $record->last_cumulative_bytes_out === 0
                        ? null
                        : __(':bytes in', ['bytes' => self::formatBytes($record->last_cumulative_bytes_in)]))
                    ->description(fn (ServiceUser $record) => $record->last_cumulative_bytes_in + $record->last_cumulative_bytes_out === 0
                        ? null
                        : __(':bytes out', ['bytes' => self::formatBytes($record->last_cumulative_bytes_out)]))
                    ->placeholder(__('no traffic yet'))
                    ->tooltip(__('Cumulative since the tunnel was last brought up')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add user'))
                    ->mutateFormDataUsing(self::normaliseUserData(...))
                    ->after(function (ServiceUser $record): void {
                        // Created during a closed access window: start suspended
                        // so the peer is never briefly added outside permitted
                        // hours (EnforceAccessWindows resumes them when it
                        // opens). AddServiceUser still issues their keys/cert;
                        // for OpenVPN a follow-up apply writes the ccd "disable"
                        // that AddServiceUser alone does not.
                        if ($record->hasAccessSchedule() && ! $record->isWithinAccessWindow()) {
                            $record->forceFill([
                                'status' => ServiceUserStatus::Suspended,
                                'suspended_reason' => EnforceAccessWindows::REASON,
                            ])->save();

                            AddServiceUser::dispatch($record);
                            ApplyServiceConfig::dispatch($record->service);

                            return;
                        }

                        AddServiceUser::dispatch($record);
                    }),
                Action::make('importCsv')
                    ->label(__('Import CSV'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    // Bulk create is a mutation, so gate it the way every
                    // per-user action here is gated: ->visible() hides it from
                    // an Auditor, but Filament's mountAction checks only
                    // isDisabled(), so ->disabled() is the guard that actually
                    // blocks a crafted mount-by-name. The action body abort()s
                    // as a third layer.
                    ->visible(fn () => auth()->user()?->isAdmin() ?? false)
                    ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                    ->modalHeading(__('Import users from CSV'))
                    ->modalDescription(__('One user per line, columns: name (required), then optional label, rate_limit_kbps, data_limit_gb, expires_at, dns. A header row is optional. Blank lines are ignored and each user is given the next free tunnel address automatically.'))
                    ->modalSubmitActionLabel(__('Import'))
                    ->schema([
                        FileUpload::make('csv')
                            ->label(__('CSV file'))
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            // Kept in the temp area and read here; never
                            // persisted to a disk.
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data, ManageRelatedRecords $livewire): void {
                        // Server-side gate, independent of the button state.
                        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

                        /** @var Service $service */
                        $service = $livewire->getRecord();

                        /** @var TemporaryUploadedFile $upload */
                        $upload = $data['csv'];

                        self::importCsv($service, (string) $upload->get());
                    }),
                Action::make('csvTemplate')
                    ->label(__('CSV template'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isAdmin() ?? false)
                    ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                    ->action(fn () => response()->streamDownload(
                        fn () => print (self::csvTemplateContents()),
                        'vpnforge-users-template.csv',
                        ['Content-Type' => 'text/csv'],
                    )),
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
                            // A throttle-rate change matters only while the user
                            // is actively throttled -- otherwise it is a dormant
                            // value and EnforceUserLimits applies it when the
                            // throttle next kicks in. When they ARE throttled the
                            // kernel tc classes must be rebuilt now, or the DB and
                            // the live cap disagree until an unrelated event.
                            $shapingChanged = $record->wasChanged('rate_limit_kbps')
                                || ($record->wasChanged('quota_throttle_kbps') && $record->quota_throttled);

                            if (! $configChanged && ! $shapingChanged) {
                                return;
                            }

                            ApplyServiceConfig::dispatch($record->service);

                            if ($configChanged) {
                                Notification::make()
                                    ->title($record->wasChanged('tunnel_ip') ? __('Tunnel address changed') : __('DNS changed'))
                                    ->body(__('Re-applying in the background. Download this user\'s config again -- the previous one is now out of date.'))
                                    ->warning()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title(__('Speed limit updated'))
                                    ->body(__('Applying on the server in the background. The user keeps their current config.'))
                                    ->success()
                                    ->send();
                            }
                        }),
                    Action::make('download')
                        ->label(__('Download config'))
                        ->icon('heroicon-o-arrow-down-tray')
                        // Server-side gate: the group's ->visible() hides these
                        // from an Auditor, but Filament's mountAction checks only
                        // isDisabled(), not isVisible() -- so ->disabled() is what
                        // actually blocks a crafted mount-by-name here.
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->action(function (ServiceUser $record) {
                            // Read the copy the privileged worker rendered and
                            // cached at addUser()/applyServiceConfig() time --
                            // this web process has no filesystem access to the
                            // actual private keys/certificates, only the
                            // worker does (see ServiceUser::refreshRenderedClientConfig).
                            $file = $record->clientConfigFile();

                            if ($file === null) {
                                Notification::make()
                                    ->title(__('Config not ready yet'))
                                    ->body(__('Still being generated in the background -- try again in a moment.'))
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
                        ->label(__('QR code'))
                        ->icon('heroicon-o-qr-code')
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        // WireGuard only. An .ovpn embeds the CA, the client
                        // certificate and its private key, which runs to several
                        // kilobytes -- well past what a QR code can carry (~2.9 KB
                        // at the absolute theoretical maximum, and much less while
                        // staying scannable by a phone). A WireGuard config is
                        // ~330 bytes.
                        ->visible(fn (ServiceUser $record) => $record->service->protocol === VpnProtocol::WireGuard)
                        ->modalHeading(fn (ServiceUser $record) => __('Scan to import :name', ['name' => $record->name]))
                        // Wide enough that a dense config still renders large
                        // enough to scan -- see the view for the measurements.
                        ->modalWidth(Width::TwoExtraLarge)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close'))
                        ->modalContent(fn (ServiceUser $record) => view(
                            'filament.service-user-qr',
                            self::qrModalData($record),
                        )),
                    // Hand-off without sending key material yourself: mint a
                    // public link the person opens once to collect their own
                    // config. The URL is shown once, here, and never again --
                    // only its hash is stored.
                    Action::make('share')
                        ->label(__('Share link'))
                        ->icon('heroicon-o-link')
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Active)
                        ->modalHeading(fn (ServiceUser $record) => __('One-time link for :name', ['name' => $record->name]))
                        ->modalDescription(__('Creates a public link this person opens to collect their own config, so you never have to send the file. The link is shown once -- copy it before closing.'))
                        ->modalSubmitActionLabel(__('Create link'))
                        ->schema([
                            Select::make('expiry')
                                ->label(__('Link expires'))
                                ->options([
                                    '1' => __('In 1 hour'),
                                    '24' => __('In 24 hours'),
                                    '168' => __('In 7 days'),
                                    '0' => __('No expiry (until used)'),
                                ])
                                ->default('24')
                                ->selectablePlaceholder(false)
                                ->required(),
                            Select::make('max_downloads')
                                ->label(__('Can be opened'))
                                ->options([
                                    '1' => __('Once'),
                                    '3' => __('Up to 3 times'),
                                    '10' => __('Up to 10 times'),
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
                                ->title(__('One-time link ready'))
                                ->body(new HtmlString(
                                    '<p style="margin-bottom:.35rem">'.__('Send this to :name -- it is shown only once:', ['name' => e($record->name)]).'</p>'
                                    .'<code style="word-break:break-all;font-size:12px;line-height:1.4">'.e($url).'</code>'
                                ))
                                ->success()
                                ->persistent()
                                ->send();
                        }),
                    Action::make('cancelLinks')
                        ->label(__('Cancel share links'))
                        ->icon('heroicon-o-link-slash')
                        ->color('gray')
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->requiresConfirmation()
                        ->modalDescription(__('Kills every share link for this user that has not already been used or expired.'))
                        ->visible(fn (ServiceUser $record) => $record->configLinks()->active()->exists())
                        ->action(function (ServiceUser $record): void {
                            $count = $record->configLinks()->active()->update(['revoked_at' => now()]);

                            Notification::make()
                                ->title($count === 1 ? __('1 link cancelled') : __(':count links cancelled', ['count' => $count]))
                                ->success()
                                ->send();
                        }),
                    Action::make('rotate')
                        ->label(__('Regenerate keys'))
                        ->icon('heroicon-o-key')
                        ->color('warning')
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->requiresConfirmation()
                        ->modalHeading(fn (ServiceUser $record) => __('Regenerate keys for :name', ['name' => $record->name]))
                        ->modalDescription(__('Issues new key material and stops the current config working immediately -- what a lost or stolen device calls for. The user keeps their name, address, labels and history. They will need the new config.'))
                        ->modalSubmitActionLabel(__('Regenerate'))
                        ->visible(fn (ServiceUser $record) => $record->status !== ServiceUserStatus::Revoked)
                        ->action(function (ServiceUser $record) {
                            RotateUserKeys::dispatch($record);

                            Notification::make()
                                ->title(__('Regenerating keys'))
                                ->body(__('The old config stops working as soon as this finishes. Download the new one and re-import it on the device.'))
                                ->warning()
                                ->send();
                        }),
                    // Suspend and resume are the reversible pair; revoke below
                    // is the one-way door.
                    Action::make('suspend')
                        ->label(__('Suspend'))
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->requiresConfirmation()
                        ->modalDescription(__('Blocks this user without destroying their keys. You can turn them back on at any time.'))
                        ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Active)
                        ->action(function (ServiceUser $record) {
                            $record->forceFill([
                                'status' => ServiceUserStatus::Suspended,
                                'suspended_reason' => 'suspended by hand',
                            ])->save();

                            ApplyServiceConfig::dispatch($record->service);

                            Notification::make()->title(__('User suspended'))->success()->send();
                        }),
                    Action::make('resume')
                        ->label(__('Resume'))
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Suspended)
                        ->action(function (ServiceUser $record) {
                            // Refuse rather than let them straight back in to
                            // be suspended again by the next enforcement run.
                            if ($record->isExpired()) {
                                Notification::make()
                                    ->title(__('Expiry date has passed'))
                                    ->body(__('Push the expiry date back first, or clear it.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            if ($record->isOverDataLimit()) {
                                Notification::make()
                                    ->title(__('Allowance is still used up'))
                                    ->body(__('Raise the allowance or move its start date forward first.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Same reasoning for the access window: resuming now
                            // would grant out-of-hours access that the
                            // every-minute enforcement job would revoke again
                            // within the minute.
                            if (! $record->isWithinAccessWindow()) {
                                Notification::make()
                                    ->title(__('Outside the access window'))
                                    ->body(__('This user is only allowed on :schedule. They will come back on their own when the window opens.', ['schedule' => $record->accessScheduleSummary()]))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $record->forceFill([
                                'status' => ServiceUserStatus::Active,
                                'suspended_reason' => null,
                            ])->save();

                            ApplyServiceConfig::dispatch($record->service);

                            Notification::make()->title(__('User resumed'))->success()->send();
                        }),
                    Action::make('revoke')
                        ->label(__('Revoke'))
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->requiresConfirmation()
                        ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Active)
                        ->action(function (ServiceUser $record) {
                            $record->forceFill(['status' => ServiceUserStatus::Revoked])->save();
                            RemoveServiceUser::dispatch($record);

                            Notification::make()
                                ->title(__('User revoked'))
                                ->body(__('Applying the change in the background...'))
                                ->success()
                                ->send();
                        }),
                    DeleteAction::make()
                        ->visible(fn (ServiceUser $record) => $record->status === ServiceUserStatus::Revoked),
                ])
                    // Every per-user action either mutates the user or reveals
                    // key material, so the whole group is admin-only. An Auditor
                    // still sees the table (read-only), just no action menu.
                    ->visible(fn () => auth()->user()?->isAdmin()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ServiceUserStatus::class),
                SelectFilter::make('labels')
                    ->label(__('Label'))
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
            ->emptyStateHeading(__('No users yet'))
            ->emptyStateDescription(__('Add a user to generate their keys and a downloadable client config. Telemetry appears here once they connect.'))
            // The telemetry behind these rows is written by the poller once a
            // minute (routes/console.php), so a five-second refresh re-ran the
            // whole table -- allowance aggregates and all -- roughly twelve
            // times per actual change.
            ->poll('30s');
    }

    /**
     * Creates every valid row from the uploaded CSV as a service user -- each
     * given the next free tunnel address by the same suggestion the single-add
     * form uses -- dispatches AddServiceUser for it exactly as the single-add
     * flow's after() hook does, and reports a per-row created/skipped summary.
     * The parsing and validation live in a pure service (CsvUserImporter) so
     * they can be tested without a database; this only turns its result into
     * rows and jobs.
     */
    public static function importCsv(Service $service, string $contents): void
    {
        $max = (int) config('vpnforge.imports.max_rows');
        $result = app(CsvUserImporter::class)->parse($contents, $max > 0 ? $max : null);

        foreach ($result->valid as $row) {
            self::createImportedUser($service, $row);
        }

        $created = $result->createdCount();

        $notification = Notification::make()
            ->title($created === 1
                ? __('1 user imported')
                : __(':count users imported', ['count' => $created]));

        if ($result->skippedCount() > 0) {
            // Show the first handful of reasons so the file can be fixed; a big
            // import with the same fault on every line does not need repeating.
            $lines = collect($result->skipped)
                ->take(10)
                ->map(fn (array $r) => e(__('Line :line: :reason', ['line' => $r['line'], 'reason' => $r['reason']])))
                ->implode('<br>');

            if ($result->skippedCount() > 10) {
                $lines .= '<br>'.e(__(':count more not shown', ['count' => $result->skippedCount() - 10]));
            }

            $notification
                ->body(new HtmlString(
                    '<p style="margin-bottom:.35rem">'.e(__(':count row(s) skipped:', ['count' => $result->skippedCount()])).'</p>'.$lines
                ))
                ->warning()
                ->persistent();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    /**
     * @param  array<string, mixed>  $row  a validated row from CsvUserImporter
     */
    private static function createImportedUser(Service $service, array $row): void
    {
        $attributes = [
            'service_id' => $service->id,
            'name' => $row['name'],
            'status' => ServiceUserStatus::Active,
            // Reuse the single-add form's suggestion. Each created user is
            // persisted before the next is suggested, so sequential rows do not
            // collide on the same address.
            'tunnel_ip' => ServiceUserForm::suggestNextTunnelIp($service),
            'labels' => $row['labels'],
            'dns_override' => $row['dns_override'],
            'rate_limit_kbps' => $row['rate_limit_kbps'],
            'expires_at' => $row['expires_at'],
            'data_limit_bytes' => $row['data_limit_bytes'],
        ];

        // Mirror the create form's quota defaults so an imported allowance is
        // actually enforced: counted from now, suspend once reached.
        if ($row['data_limit_bytes'] !== null) {
            $attributes['quota_started_at'] = now();
            $attributes['quota_action'] = QuotaAction::Suspend;
        }

        $user = ServiceUser::create($attributes);

        // Same as the single-add after() hook: issue keys/cert and render the
        // client config on the privileged worker. Imported users carry no
        // access schedule, so there is no closed-window special case here.
        AddServiceUser::dispatch($user);
    }

    private static function csvTemplateContents(): string
    {
        // Header plus two example rows. rate_limit_kbps is kbit/s, data_limit_gb
        // is GB, expires_at is any understandable date, dns is one or more IPv4
        // resolvers separated by ";".
        return implode("\n", [
            'name,label,rate_limit_kbps,data_limit_gb,expires_at,dns',
            'phone,family,,,,',
            'laptop,work,20000,50,2026-12-31,1.1.1.1;9.9.9.9',
        ])."\n";
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

        // Same for the access-day checkbox list: an empty tick set is "every
        // day", i.e. no restriction, which the column stores as null.
        if (array_key_exists('access_days', $data)) {
            $data['access_days'] = filled($data['access_days'])
                ? array_values(array_map('intval', $data['access_days']))
                : null;
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
                'message' => __('The config is still being generated in the background -- close this and reopen it in a moment.'),
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
                'message' => __('This config is too large to fit in a QR code -- use "Download config" instead.'),
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
