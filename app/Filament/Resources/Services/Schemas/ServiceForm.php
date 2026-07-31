<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Process;
use Throwable;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            ToggleButtons::make('form_mode')
                ->label('Configuration mode')
                ->options([
                    'simple' => 'Simple',
                    'advanced' => 'Advanced',
                ])
                ->icons([
                    'simple' => 'heroicon-o-sparkles',
                    'advanced' => 'heroicon-o-adjustments-horizontal',
                ])
                ->default('simple')
                ->inline()
                ->live()
                // Not a column on services -- it only drives which fields are
                // on screen, so it must never reach the model.
                ->dehydrated(false)
                ->helperText('Simple picks the network details for you. Advanced exposes every parameter the drivers actually read.')
                ->columnSpanFull(),

            Section::make('Service')
                ->description('What this service is and where clients reach it.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Home tunnel'),

                    Select::make('protocol')
                        ->options(VpnProtocol::class)
                        ->required()
                        ->live()
                        ->default(VpnProtocol::WireGuard)
                        ->disabledOn('edit')
                        ->helperText('Cannot be changed after creation.')
                        // Re-derive the network defaults whenever the protocol
                        // changes, so simple mode never leaves a WireGuard
                        // interface name on an OpenVPN service (or a port that
                        // belongs to the other protocol).
                        ->afterStateUpdated(function (Set $set, $state): void {
                            $protocol = $state instanceof VpnProtocol
                                ? $state
                                : VpnProtocol::tryFrom((string) $state);

                            if ($protocol === null) {
                                return;
                            }

                            $set('interface_name', self::suggestInterfaceName($protocol));
                            $set('listen_port', self::suggestListenPort($protocol));
                            $set('transport', Transport::Udp->value);
                        }),

                    TextInput::make('config.endpoint_host')
                        ->label('Public endpoint (hostname or IP)')
                        ->required()
                        ->default(fn () => self::detectPublicHost())
                        ->helperText('What clients connect to -- this server\'s public IP or a domain pointing at it.')
                        ->columnSpanFull(),
                ]),

            // Everything below is advanced-only.
            //
            // dehydratedWhenHidden() has to sit on the *containers*, not on the
            // fields: Filament decides whether a hidden component still gets
            // saved by asking its parent (see CanBeHidden::
            // isHiddenAndNotDehydratedWhenHidden), so a field carrying the flag
            // while its Tab does not is silently dropped -- which in simple
            // mode meant inserting a service with no interface_name, subnet or
            // port at all.
            //
            // Only the tabs holding real columns or values that must not fall
            // back to a guess are marked. The two protocol tabs deliberately
            // are not: every key in them has a driver-side default identical to
            // the form default, so leaving them out keeps a WireGuard service
            // free of stray OpenVPN keys.
            Tabs::make('Advanced settings')
                ->columnSpanFull()
                ->dehydratedWhenHidden()
                ->visible(fn (Get $get) => $get('form_mode') === 'advanced')
                ->tabs([
                    Tab::make('Network')
                        ->icon('heroicon-o-globe-alt')
                        ->columns(2)
                        ->dehydratedWhenHidden()
                        ->schema([
                            TextInput::make('interface_name')
                                ->label('Interface name')
                                ->required()
                                ->maxLength(64)
                                ->default(fn (Get $get) => self::suggestInterfaceName(self::protocolFrom($get('protocol'))))
                                ->dehydratedWhenHidden()
                                ->disabledOn('edit')
                                ->helperText('The network interface and systemd instance name. Cannot be changed after creation.'),

                            TextInput::make('subnet_cidr')
                                ->label('Subnet (CIDR)')
                                ->required()
                                ->default(fn () => self::suggestSubnet())
                                ->dehydratedWhenHidden()
                                ->disabledOn('edit')
                                ->helperText('The private range handed out to clients. The .1 address becomes the tunnel gateway and DNS server.'),

                            TextInput::make('listen_port')
                                ->label('Listen port')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(65535)
                                ->default(fn (Get $get) => self::suggestListenPort(self::protocolFrom($get('protocol'))))
                                ->dehydratedWhenHidden()
                                ->helperText('Open this port in your firewall / cloud security group -- the panel cannot do that for you.'),

                            Select::make('transport')
                                ->options(Transport::class)
                                ->required()
                                ->default(Transport::Udp)
                                ->dehydratedWhenHidden()
                                // Disabled fields are dropped on save by
                                // default, and transport is NOT NULL -- forcing
                                // it keeps the greyed-out UDP value for
                                // WireGuard from inserting a null.
                                ->dehydrated()
                                ->helperText(fn (Get $get) => self::protocolFrom($get('protocol')) === VpnProtocol::WireGuard
                                    ? 'WireGuard is UDP-only.'
                                    : 'TCP survives restrictive networks better; UDP is faster.')
                                ->disabled(fn (Get $get) => self::protocolFrom($get('protocol')) === VpnProtocol::WireGuard),

                            TextInput::make('config.egress_interface')
                                ->label('Egress network interface')
                                ->required()
                                ->default(fn () => self::detectEgressInterface())
                                ->dehydratedWhenHidden()
                                ->helperText('The server\'s internet-facing interface, used for the NAT/MASQUERADE rule.'),
                        ]),

                    Tab::make('WireGuard')
                        ->icon('heroicon-o-bolt')
                        ->columns(2)
                        ->visible(fn (Get $get) => self::protocolFrom($get('protocol')) === VpnProtocol::WireGuard)
                        ->schema([
                            TextInput::make('config.mtu')
                                ->label('MTU')
                                ->numeric()
                                ->minValue(576)
                                ->maxValue(9000)
                                ->default(1420)
                                ->dehydratedWhenHidden()
                                ->helperText('1420 suits most links. Lower it if large packets stall over the tunnel.'),

                            TextInput::make('config.keepalive')
                                ->label('Persistent keepalive (seconds)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3600)
                                ->default(25)
                                ->dehydratedWhenHidden()
                                ->helperText('Keeps NAT mappings alive for clients behind a router. 0 disables it.'),

                            TagsInput::make('config.dns')
                                ->label('DNS servers pushed to clients')
                                ->default(['1.1.1.1', '1.0.0.1'])
                                ->dehydratedWhenHidden()
                                ->helperText('Set this to the tunnel gateway (the .1 of the subnet) if you want DNS-based traffic logging to see this service\'s queries.'),

                            TextInput::make('config.client_allowed_ips')
                                ->label('Client AllowedIPs')
                                ->default('0.0.0.0/0, ::/0')
                                ->dehydratedWhenHidden()
                                ->helperText('0.0.0.0/0, ::/0 is a full tunnel. Narrow it for split tunnelling.'),
                        ]),

                    Tab::make('OpenVPN')
                        ->icon('heroicon-o-lock-closed')
                        ->columns(2)
                        ->visible(fn (Get $get) => self::protocolFrom($get('protocol')) === VpnProtocol::OpenVpn)
                        ->schema([
                            TextInput::make('config.data_ciphers')
                                ->label('Data ciphers')
                                ->default('AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305')
                                ->dehydratedWhenHidden()
                                ->helperText('Colon-separated, in order of preference.'),

                            Select::make('config.auth_digest')
                                ->label('Auth digest')
                                ->options([
                                    'SHA256' => 'SHA256',
                                    'SHA384' => 'SHA384',
                                    'SHA512' => 'SHA512',
                                ])
                                ->default('SHA256')
                                ->dehydratedWhenHidden(),

                            Select::make('config.tls_version_min')
                                ->label('Minimum TLS version')
                                ->options([
                                    '1.2' => 'TLS 1.2',
                                    '1.3' => 'TLS 1.3',
                                ])
                                ->default('1.2')
                                ->dehydratedWhenHidden()
                                ->helperText('1.3 is stricter but rejects older clients.'),

                            TextInput::make('config.keepalive')
                                ->label('Keepalive (ping ping-restart)')
                                ->default('10 60')
                                ->dehydratedWhenHidden()
                                ->helperText('Two numbers: ping interval and restart timeout, in seconds.'),

                            TextInput::make('config.max_clients')
                                ->label('Maximum simultaneous clients')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(4096)
                                ->dehydratedWhenHidden()
                                ->placeholder('unlimited')
                                ->helperText('Leave empty for no limit.'),

                            TextInput::make('config.verb')
                                ->label('Log verbosity')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(11)
                                ->default(3)
                                ->dehydratedWhenHidden()
                                ->helperText('3 is normal. 5 and above is very noisy.'),

                            Toggle::make('config.redirect_gateway')
                                ->label('Route all client traffic through this service')
                                ->default(true)
                                ->dehydratedWhenHidden(),

                            Toggle::make('config.duplicate_cn')
                                ->label('Allow one certificate on several devices at once')
                                ->default(false)
                                ->dehydratedWhenHidden()
                                ->helperText('Off means a second device using the same client config kicks the first one off.'),

                            TagsInput::make('config.push_routes')
                                ->label('Additional routes to push')
                                ->dehydratedWhenHidden()
                                ->placeholder('192.168.1.0 255.255.255.0')
                                ->helperText('OpenVPN route syntax: network then netmask.')
                                ->columnSpanFull(),
                        ]),

                    Tab::make('DNS & logging')
                        ->icon('heroicon-o-document-magnifying-glass')
                        ->columns(2)
                        ->dehydratedWhenHidden()
                        ->schema([
                            Toggle::make('logging_enabled_default')
                                ->label('Log activity for users of this service by default')
                                ->default(true)
                                ->dehydratedWhenHidden()
                                ->helperText('Can be overridden per user. Covers connection metadata, DNS-visible domains and plaintext HTTP.')
                                ->columnSpanFull(),

                            Toggle::make('config.push_dns')
                                ->label('Point clients at this service\'s own DNS resolver')
                                ->default(true)
                                ->dehydratedWhenHidden()
                                ->visible(fn (Get $get) => self::protocolFrom($get('protocol')) === VpnProtocol::OpenVpn)
                                ->helperText('Required for DNS-based traffic logging on OpenVPN -- without it clients keep using their own resolver and the capture agent sees nothing.')
                                ->columnSpanFull(),

                            TagsInput::make('config.dns_upstreams')
                                ->label('Upstream DNS servers')
                                ->default(['1.1.1.1', '1.0.0.1'])
                                ->dehydratedWhenHidden()
                                ->helperText('Where this service\'s resolver forwards queries it cannot answer itself.')
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    private static function protocolFrom(mixed $state): ?VpnProtocol
    {
        if ($state instanceof VpnProtocol) {
            return $state;
        }

        return $state === null ? null : VpnProtocol::tryFrom((string) $state);
    }

    /**
     * Lowest interface index not already taken by another service, so simple
     * mode never proposes a name that collides with a live interface.
     */
    private static function suggestInterfaceName(?VpnProtocol $protocol): string
    {
        $prefix = $protocol === VpnProtocol::OpenVpn ? 'tun' : 'wg';
        $taken = Service::pluck('interface_name')->all();

        for ($i = 0; $i < 100; $i++) {
            if (! in_array("{$prefix}{$i}", $taken, true)) {
                return "{$prefix}{$i}";
            }
        }

        return "{$prefix}0";
    }

    private static function suggestSubnet(): string
    {
        $taken = Service::pluck('subnet_cidr')->all();

        for ($i = 8; $i < 250; $i++) {
            if (! in_array("10.{$i}.0.0/24", $taken, true)) {
                return "10.{$i}.0.0/24";
            }
        }

        return '10.8.0.0/24';
    }

    private static function suggestListenPort(?VpnProtocol $protocol): int
    {
        $base = $protocol === VpnProtocol::OpenVpn ? 1194 : 51820;
        $taken = Service::pluck('listen_port')->map(fn ($p) => (int) $p)->all();

        for ($port = $base; $port < $base + 100; $port++) {
            if (! in_array($port, $taken, true)) {
                return $port;
            }
        }

        return $base;
    }

    /**
     * Read straight off the default route rather than assuming eth0 -- getting
     * this wrong means the MASQUERADE rule lands on the wrong interface and
     * clients connect to the tunnel but reach nothing beyond it.
     */
    private static function detectEgressInterface(): string
    {
        try {
            $output = Process::run(['ip', '-o', 'route', 'show', 'default'])->output();

            if (preg_match('/\bdev\s+(\S+)/', $output, $matches) === 1) {
                return $matches[1];
            }
        } catch (Throwable) {
            // Falls through to the conventional default below.
        }

        return 'eth0';
    }

    private static function detectPublicHost(): ?string
    {
        return request()->getHost() ?: null;
    }
}
