<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\VpnProtocol;
use App\Models\Service;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Utilities\Get;
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
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                // No control characters (above all newlines): the name is
                // rendered into the root-loaded WireGuard config. The driver
                // sanitises it at the sink too -- this just fails it earlier.
                ->rule('not_regex:/[\x00-\x1f\x7f]/')
                ->placeholder('phone, laptop, ...'),

            TextInput::make('tunnel_ip')
                ->label('Tunnel IP')
                ->required()
                ->rule('ipv4')
                ->default(fn () => self::suggestNextTunnelIp($service))
                ->helperText('Must be inside '.$service->subnet_cidr.' and not already assigned.'),

            Select::make('logging_override')
                ->label('Logging')
                ->options([
                    '' => 'Inherit from service ('.($service->logging_enabled_default ? 'on' : 'off').')',
                    '1' => 'Force on',
                    '0' => 'Force off',
                ])
                ->default('')
                ->helperText('Off means nothing this user does is recorded -- no connections, no DNS lookups.'),

            TagsInput::make('labels')
                ->label('Labels')
                ->placeholder('family, work, client-x')
                ->helperText('For finding and filtering people once there are more than a handful.'),

            TagsInput::make('dns_override')
                ->label('DNS for this user')
                ->placeholder('1.1.1.1, 9.9.9.9')
                // Each entry is checked here for the form, and again at the
                // driver sink where it reaches a device/server config.
                ->nestedRecursiveRules(['ipv4'])
                ->helperText($service->protocol === VpnProtocol::WireGuard
                    ? 'Leave empty to use the service resolver. Set, this user\'s device is pointed straight at these resolvers instead -- so their DNS lookups stop being recorded in the traffic log.'
                    : 'Leave empty to use the service resolver. Set, these resolvers are pushed to this user\'s device alongside the service one (their lookups are still recorded).'),

            // Stored as kbit/s so the tc rule can use it directly; shown in
            // Mbit/s because nobody wants to type 5000.
            TextInput::make('rate_limit_kbps')
                ->label('Speed limit (Mbit/s)')
                ->numeric()
                ->minValue(0.1)
                ->step(0.1)
                ->formatStateUsing(fn (?int $state) => $state === null ? null : round($state / 1000, 2))
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? (int) round((float) $state * 1000) : null)
                ->helperText('Left empty, no limit. Otherwise caps this user\'s download and upload at this speed. Applied on the server within a few seconds -- no need to re-download the config.'),

            DateTimePicker::make('expires_at')
                ->label('Access expires')
                ->seconds(false)
                ->helperText('Left empty, access never ends. Otherwise the user is suspended automatically once this passes -- suspended, not revoked, so pushing the date back turns them straight back on.'),

            // Keeps the column's name so the value round-trips on its own;
            // only the unit is translated, because nobody wants to type
            // 53687091200.
            TextInput::make('data_limit_bytes')
                ->label('Data allowance (GB)')
                ->numeric()
                ->minValue(0.1)
                ->step(0.1)
                ->live(onBlur: true)
                ->formatStateUsing(fn (?int $state) => $state === null ? null : round($state / 1024 ** 3, 2))
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? (int) round((float) $state * 1024 ** 3) : null)
                ->helperText('Left empty, traffic is unlimited. Otherwise the user is suspended once they pass it, counted from the date below.'),

            DateTimePicker::make('quota_started_at')
                ->label('Allowance counted from')
                ->seconds(false)
                ->default(now())
                ->helperText('Move this forward to reset the allowance without deleting any history.')
                ->visible(fn (Get $get) => filled($get('data_limit_bytes'))),

            CheckboxList::make('access_days')
                ->label('Access days')
                ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])
                ->columns(7)
                ->gridDirection('row')
                ->helperText('Leave all unticked for every day. Ticked, access is allowed only on these days. Outside the window the user is suspended automatically and comes back on their own.'),

            // Stored as minutes since midnight (0-1439) so the window is a
            // couple of cheap integer comparisons at enforcement time.
            TimePicker::make('access_start_minute')
                ->label('Access from')
                ->seconds(false)
                // Both or neither: a lone bound would otherwise be stored and
                // then silently ignored at enforcement time.
                ->requiredWith('access_end_minute')
                ->formatStateUsing(fn (?int $state) => self::minutesToTime($state))
                ->dehydrateStateUsing(fn (?string $state) => self::timeToMinutes($state))
                ->helperText('Server local time. Leave both times empty for no time limit; set "from" later than "until" for a window that crosses midnight, e.g. 22:00-06:00.'),

            TimePicker::make('access_end_minute')
                ->label('Access until')
                ->seconds(false)
                ->requiredWith('access_start_minute')
                ->formatStateUsing(fn (?int $state) => self::minutesToTime($state))
                ->dehydrateStateUsing(fn (?string $state) => self::timeToMinutes($state)),

            TextInput::make('max_connections')
                ->label('Max simultaneous devices')
                ->numeric()
                ->minValue(1)
                // OpenVPN only: a WireGuard peer is single-device by design, so
                // there is nothing to cap.
                ->visible($service->protocol === VpnProtocol::OpenVpn)
                ->helperText('Left empty, no cap. Caps how many devices may use this one config at the same time. Needs "Allow duplicate connections" on the service to permit more than one at all; the excess newest connections are dropped.'),
        ]);
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

    private static function suggestNextTunnelIp(Service $service): string
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
