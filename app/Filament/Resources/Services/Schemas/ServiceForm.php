<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\Transport;
use App\Enums\VpnProtocol;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Service')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('General')
                        ->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255),
                            Select::make('protocol')
                                ->options(VpnProtocol::class)
                                ->required()
                                ->live()
                                ->disabledOn('edit')
                                ->helperText('Cannot be changed after creation.'),
                            Select::make('transport')
                                ->options(Transport::class)
                                ->default(Transport::Udp)
                                ->required(),
                            TextInput::make('interface_name')
                                ->label('Interface name')
                                ->required()
                                ->maxLength(64)
                                ->placeholder('wg0, ovpn0, ...')
                                ->helperText('The system network interface / systemd instance name for this service.'),
                            TextInput::make('subnet_cidr')
                                ->label('Subnet (CIDR)')
                                ->required()
                                ->placeholder('10.10.0.0/24'),
                            TextInput::make('listen_port')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(65535)
                                ->helperText('Remember to open this port (UDP or TCP, matching the transport above) in your firewall/cloud security group -- this installer/panel cannot do that for you.'),
                        ])
                        ->columns(2),

                    Tab::make('Protocol settings')
                        ->schema([
                            TextInput::make('config.endpoint_host')
                                ->label('Public endpoint (hostname or IP)')
                                ->required()
                                ->helperText('What clients connect to -- your VPS\'s public IP or domain.'),

                            // WireGuard-only
                            TextInput::make('config.mtu')
                                ->label('MTU')
                                ->numeric()
                                ->default(1420)
                                ->visible(fn ($get) => $get('protocol') === VpnProtocol::WireGuard->value),
                            TextInput::make('config.keepalive')
                                ->label('Persistent keepalive (seconds)')
                                ->numeric()
                                ->default(25)
                                ->visible(fn ($get) => $get('protocol') === VpnProtocol::WireGuard->value),
                            TagsInput::make('config.dns')
                                ->label('DNS servers pushed to clients')
                                ->default(['1.1.1.1', '1.0.0.1'])
                                ->visible(fn ($get) => $get('protocol') === VpnProtocol::WireGuard->value),
                            TextInput::make('config.client_allowed_ips')
                                ->label('Client AllowedIPs')
                                ->default('0.0.0.0/0, ::/0')
                                ->helperText('Use 0.0.0.0/0, ::/0 for a full tunnel.')
                                ->visible(fn ($get) => $get('protocol') === VpnProtocol::WireGuard->value),

                            // OpenVPN-only
                            TextInput::make('config.data_ciphers')
                                ->label('Data ciphers')
                                ->default('AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305')
                                ->visible(fn ($get) => $get('protocol') === VpnProtocol::OpenVpn->value),
                            TextInput::make('config.keepalive')
                                ->label('Keepalive (ping ping-restart)')
                                ->default('10 60')
                                ->visible(fn ($get) => $get('protocol') === VpnProtocol::OpenVpn->value),
                            Toggle::make('config.redirect_gateway')
                                ->label('Redirect all client traffic through this service')
                                ->default(true)
                                ->visible(fn ($get) => $get('protocol') === VpnProtocol::OpenVpn->value),
                            TagsInput::make('config.push_routes')
                                ->label('Additional routes to push')
                                ->visible(fn ($get) => $get('protocol') === VpnProtocol::OpenVpn->value),
                        ])
                        ->columns(2),

                    Tab::make('Logging')
                        ->schema([
                            Toggle::make('logging_enabled_default')
                                ->label('Log activity for users of this service by default')
                                ->helperText('Can be overridden per user. Covers connection metadata, DNS-visible domains, and full plaintext HTTP traffic.'),
                        ]),

                    Tab::make('Advanced')
                        ->schema([
                            TextInput::make('config.egress_interface')
                                ->label('Egress network interface')
                                ->default('eth0')
                                ->helperText('The server\'s internet-facing interface, used for the NAT/MASQUERADE rule. Check with `ip route show default` if unsure.'),
                        ]),
                ]),
        ]);
    }
}
