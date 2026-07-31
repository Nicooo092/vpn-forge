<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Models\Service;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ServiceUserForm
{
    public static function configure(Schema $schema, Service $service): Schema
    {
        return $schema->components([
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
