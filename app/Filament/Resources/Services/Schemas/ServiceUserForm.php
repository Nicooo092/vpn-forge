<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\QuotaAction;
use App\Enums\QuotaReset;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Models\ServiceUserTemplate;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class ServiceUserForm
{
    public static function configure(Schema $schema, Service $service): Schema
    {
        // One column, for the same reason ServiceForm declares one -- except
        // here the two-column grid is imposed by
        // InteractsWithRelationshipTable::defaultForm(), which runs before
        // these components are attached. columns() merges into whatever is
        // already set, so this overwrites the imposed value rather than being
        // ignored, and the fields stop laying out two-then-one with an empty
        // half beside the last of them.
        return $schema->columns(1)->components([
            // Pure create-form convenience: pick a saved profile and its
            // defaults drop into the fields below. Stored nowhere on the user
            // (dehydrated(false)) and hidden on edit -- it seeds a new user and
            // nothing more, so a template and the users made from it stay
            // independent. Hidden entirely until at least one template exists.
            Select::make('apply_template')
                ->label(__('Apply template'))
                ->placeholder(__('Start from a saved profile (optional)'))
                ->options(fn () => ServiceUserTemplate::orderBy('name')->pluck('name', 'id'))
                ->dehydrated(false)
                ->live()
                ->visible(fn (string $operation) => $operation === 'create' && ServiceUserTemplate::exists())
                ->afterStateUpdated(function ($state, Set $set): void {
                    $template = filled($state) ? ServiceUserTemplate::find($state) : null;

                    if ($template === null) {
                        return;
                    }

                    foreach (self::formStateFromTemplate($template) as $field => $value) {
                        $set($field, $value);
                    }
                })
                ->helperText(__('Fills the fields below from a saved profile. You can still change anything before saving.')),

            TextInput::make('name')
                ->required()
                ->maxLength(255)
                // No control characters (above all newlines): the name is
                // rendered into the root-loaded WireGuard config. The driver
                // sanitises it at the sink too -- this just fails it earlier.
                ->rule('not_regex:/[\x00-\x1f\x7f]/')
                ->placeholder(__('phone, laptop, ...')),

            TextInput::make('email')
                ->label(__('Email (optional)'))
                ->email()
                ->maxLength(255)
                ->helperText(__('If set, this person is emailed about their own account -- when their access is about to expire, or when it connects from a new network. Requires mail to be configured on the server. Nothing else is ever sent here.')),

            TextInput::make('tunnel_ip')
                ->label(__('Tunnel IP'))
                ->required()
                ->rule('ipv4')
                ->default(fn () => self::suggestNextTunnelIp($service))
                ->helperText(__('Must be inside :subnet and not already assigned.', ['subnet' => $service->subnet_cidr])),

            Select::make('logging_override')
                ->label(__('Logging'))
                ->options([
                    '' => __('Inherit from service (:state)', [
                        'state' => $service->logging_enabled_default ? __('on') : __('off'),
                    ]),
                    '1' => __('Force on'),
                    '0' => __('Force off'),
                ])
                ->default('')
                ->helperText(__('Off means nothing this user does is recorded -- no connections, no DNS lookups.')),

            TagsInput::make('labels')
                ->label(__('Labels'))
                ->placeholder(__('family, work, client-x'))
                ->helperText(__('For finding and filtering people once there are more than a handful.')),

            TagsInput::make('dns_override')
                ->label(__('DNS for this user'))
                ->placeholder('1.1.1.1, 9.9.9.9')
                // Each entry is checked here for the form, and again at the
                // driver sink where it reaches a device/server config.
                ->nestedRecursiveRules(['ipv4'])
                ->helperText($service->protocol === VpnProtocol::WireGuard
                    ? __('Leave empty to use the service resolver. Set, this user\'s device is pointed straight at these resolvers instead -- so their DNS lookups stop being recorded in the traffic log.')
                    : __('Leave empty to use the service resolver. Set, these resolvers are pushed to this user\'s device alongside the service one (their lookups are still recorded).')),

            // Stored as kbit/s so the tc rule can use it directly; shown in
            // Mbit/s because nobody wants to type 5000.
            TextInput::make('rate_limit_kbps')
                ->label(__('Speed limit (Mbit/s)'))
                ->numeric()
                ->minValue(0.1)
                ->step(0.1)
                ->formatStateUsing(fn (?int $state) => $state === null ? null : round($state / 1000, 2))
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? (int) round((float) $state * 1000) : null)
                ->helperText(__('Left empty, no limit. Otherwise caps this user\'s download and upload at this speed. Applied on the server within a few seconds -- no need to re-download the config.')),

            DateTimePicker::make('expires_at')
                ->label(__('Access expires'))
                ->seconds(false)
                ->helperText(__('Left empty, access never ends. Otherwise the user is suspended automatically once this passes -- suspended, not revoked, so pushing the date back turns them straight back on.')),

            // Keeps the column's name so the value round-trips on its own;
            // only the unit is translated, because nobody wants to type
            // 53687091200.
            TextInput::make('data_limit_bytes')
                ->label(__('Data allowance (GB)'))
                ->numeric()
                ->minValue(0.1)
                ->step(0.1)
                ->live(onBlur: true)
                ->formatStateUsing(fn (?int $state) => $state === null ? null : round($state / 1024 ** 3, 2))
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? (int) round((float) $state * 1024 ** 3) : null)
                ->helperText(__('Left empty, traffic is unlimited. Otherwise the user is suspended once they pass it, counted from the date below.')),

            DateTimePicker::make('quota_started_at')
                ->label(__('Allowance counted from'))
                ->seconds(false)
                ->default(now())
                ->helperText(__('Move this forward to reset the allowance without deleting any history.'))
                ->visible(fn (Get $get) => filled($get('data_limit_bytes'))),

            Select::make('quota_reset')
                ->label(__('Automatic reset'))
                ->options(QuotaReset::class)
                ->default(QuotaReset::None->value)
                ->selectablePlaceholder(false)
                ->helperText(__('Moves the allowance start date forward on its own, so usage counts from zero again each period without deleting any history.'))
                ->visible(fn (Get $get) => filled($get('data_limit_bytes'))),

            Select::make('quota_action')
                ->label(__('On reaching the allowance'))
                ->options(QuotaAction::class)
                ->default(QuotaAction::Suspend->value)
                ->selectablePlaceholder(false)
                ->live()
                ->helperText(__('What happens when the allowance is used up. Suspend cuts the user off; throttle keeps them connected but slows them to the speed below.'))
                ->visible(fn (Get $get) => filled($get('data_limit_bytes'))),

            // Stored as kbit/s, shown in Mbit/s -- same convention as the speed
            // limit above. Only relevant, and only shown, when the action is
            // throttle.
            TextInput::make('quota_throttle_kbps')
                ->label(__('Throttle speed (Mbit/s)'))
                ->numeric()
                ->minValue(0.1)
                ->step(0.1)
                ->required(fn (Get $get) => self::isThrottle($get('quota_action')))
                ->formatStateUsing(fn (?int $state) => $state === null ? null : round($state / 1000, 2))
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? (int) round((float) $state * 1000) : null)
                ->helperText(__('The speed the user is capped to once over their allowance. Applied on the server within a few seconds; lifted automatically when the allowance resets.'))
                ->visible(fn (Get $get) => filled($get('data_limit_bytes')) && self::isThrottle($get('quota_action'))),

            CheckboxList::make('access_days')
                ->label(__('Access days'))
                ->options([
                    1 => __('Mon'),
                    2 => __('Tue'),
                    3 => __('Wed'),
                    4 => __('Thu'),
                    5 => __('Fri'),
                    6 => __('Sat'),
                    7 => __('Sun'),
                ])
                ->columns(7)
                ->gridDirection('row')
                ->helperText(__('Leave all unticked for every day. Ticked, access is allowed only on these days. Outside the window the user is suspended automatically and comes back on their own.')),

            // Stored as minutes since midnight (0-1439) so the window is a
            // couple of cheap integer comparisons at enforcement time.
            TimePicker::make('access_start_minute')
                ->label(__('Access from'))
                ->seconds(false)
                // Both or neither: a lone bound would otherwise be stored and
                // then silently ignored at enforcement time.
                ->requiredWith('access_end_minute')
                ->formatStateUsing(fn (?int $state) => self::minutesToTime($state))
                ->dehydrateStateUsing(fn (?string $state) => self::timeToMinutes($state))
                ->helperText(__('Server local time. Leave both times empty for no time limit; set "from" later than "until" for a window that crosses midnight, e.g. 22:00-06:00.')),

            TimePicker::make('access_end_minute')
                ->label(__('Access until'))
                ->seconds(false)
                ->requiredWith('access_start_minute')
                ->formatStateUsing(fn (?int $state) => self::minutesToTime($state))
                ->dehydrateStateUsing(fn (?string $state) => self::timeToMinutes($state)),

            TextInput::make('max_connections')
                ->label(__('Max simultaneous devices'))
                ->numeric()
                ->minValue(1)
                // OpenVPN only: a WireGuard peer is single-device by design, so
                // there is nothing to cap.
                ->visible($service->protocol === VpnProtocol::OpenVpn)
                ->helperText(__('Left empty, no cap. Caps how many devices may use this one config at the same time. Needs "Allow duplicate connections" on the service to permit more than one at all; the excess newest connections are dropped.')),
        ]);
    }

    /**
     * Maps a saved template onto the create form's field state, expressed in
     * the units the fields display (Mbit/s, GB, HH:MM, the logging select's
     * '' / '1' / '0' tri-state) rather than the canonical units the columns
     * store. Pure and public so the mapping can be asserted directly in a test.
     *
     * @return array<string, mixed>
     */
    public static function formStateFromTemplate(ServiceUserTemplate $template): array
    {
        return [
            'logging_override' => match ($template->logging_override) {
                true => '1',
                false => '0',
                default => '',
            },
            'labels' => $template->labels ?? [],
            'dns_override' => $template->dns_override ?? [],
            'rate_limit_kbps' => $template->rate_limit_kbps === null ? null : round($template->rate_limit_kbps / 1000, 2),
            'data_limit_bytes' => $template->data_limit_bytes === null ? null : round($template->data_limit_bytes / 1024 ** 3, 2),
            'access_days' => $template->access_days ?? [],
            'access_start_minute' => self::minutesToTime($template->access_start_minute),
            'access_end_minute' => self::minutesToTime($template->access_end_minute),
        ];
    }

    // Get may hand back either the enum instance (record fill) or its scalar
    // value (live change), so accept both.
    private static function isThrottle(mixed $action): bool
    {
        return $action === QuotaAction::Throttle || $action === QuotaAction::Throttle->value;
    }

    private static function minutesToTime(?int $minute): ?string
    {
        return $minute === null ? null : sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    private static function timeToMinutes(?string $state): ?int
    {
        if (! filled($state)) {
            return null;
        }

        $time = Carbon::parse($state);

        return $time->hour * 60 + $time->minute;
    }

    public static function suggestNextTunnelIp(Service $service): string
    {
        $network = explode('/', $service->subnet_cidr)[0];
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
