<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Models\Service;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

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
                ->placeholder('phone, laptop, ...'),

            TextInput::make('tunnel_ip')
                ->label('Tunnel IP')
                ->required()
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
        ]);
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
