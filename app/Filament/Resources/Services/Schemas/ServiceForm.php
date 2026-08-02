<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Services\Vpn\DnsPresets;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
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
        // One column at the root, deliberately. Filament otherwise imposes a
        // two-column grid on a resource form (EditRecord::
        // getDefaultActionSchemaResolver: `$schema->hasCustomColumns() ?
        // $schema : $schema->columns(2)`), and every top-level component then
        // silently takes half the page with nothing beside it. Declaring the
        // column count here makes that explicit once, instead of needing a
        // columnSpanFull() on each component and losing the page layout again
        // the moment a new one is added -- which is exactly what happened when
        // the section below was wrapped in a Grid.
        return $schema->columns(1)->components([
            ToggleButtons::make('form_mode')
                ->label(__('Configuration mode'))
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
                ->helperText(__('Simple picks the network details for you. Advanced exposes every parameter the drivers actually read.')),

            // Split the row: what you edit on the left, what the service
            // actually is on the right. Previously the editable section took
            // one column of a two-column grid and nothing filled the other,
            // so the page was half empty while the runtime details it could
            // have shown were not visible anywhere at all.
            Grid::make(3)->schema([
                Section::make(__('Service'))
                    ->description(__('What this service is and where clients reach it.'))
                    // Nothing to report on a service that does not exist yet,
                    // so on create this takes the whole row.
                    //
                    // Written as an array rather than a bare closure on
                    // purpose: CanSpanColumns::columnSpan() expands a scalar
                    // into ['default' => 1, 'lg' => $span], but appends a
                    // Closure untouched and skips that expansion -- which
                    // loses the `default: 1` and leaves the section claiming
                    // two or three tracks inside the single-track grid every
                    // viewport below 1024px actually gets.
                    ->columnSpan([
                        'default' => 1,
                        'lg' => fn (string $operation) => $operation === 'create' ? 3 : 2,
                    ])
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('Home tunnel')),

                        Select::make('protocol')
                            ->options(VpnProtocol::class)
                            ->required()
                            ->live()
                            ->default(VpnProtocol::WireGuard)
                            ->disabledOn('edit')
                            ->helperText(__('Cannot be changed after creation.'))
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
                            ->label(__('Public endpoint (hostname or IP)'))
                            ->required()
                            ->default(fn () => self::detectPublicHost())
                            ->helperText(__('What clients connect to -- this server\'s public IP or a domain pointing at it.'))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Provisioned state'))
                    ->description(__('Set when the service was created. Read-only here.'))
                    ->columnSpan(1)
                    ->visibleOn('edit')
                    ->schema([
                        Placeholder::make('status_summary')
                            ->label(__('Status'))
                            ->content(fn (?Service $record) => $record === null
                                ? '--'
                                : $record->status->getLabel().($record->last_error !== null ? ' -- '.$record->last_error : '')),

                        Placeholder::make('interface_summary')
                            ->label(__('Interface'))
                            ->content(fn (?Service $record) => $record?->interface_name ?? '--'),

                        Placeholder::make('network_summary')
                            ->label(__('Network'))
                            ->content(fn (?Service $record) => $record === null
                                ? '--'
                                : $record->subnet_cidr.' on port '.$record->listen_port.'/'.$record->transport->value),

                        // The address clients are pointed at for DNS, and so
                        // the one that has to be reachable for traffic logging
                        // to see anything.
                        Placeholder::make('resolver_summary')
                            ->label(__('Tunnel gateway / resolver'))
                            ->content(fn (?Service $record) => $record === null
                                ? '--'
                                : self::gatewayAddress($record->subnet_cidr)),

                        Placeholder::make('users_summary')
                            ->label(__('Users'))
                            ->content(fn (?Service $record) => $record === null
                                ? '--'
                                : $record->serviceUsers()->count().' configured'),
                    ]),
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
                ->dehydratedWhenHidden()
                ->visible(fn (Get $get) => $get('form_mode') === 'advanced')
                ->tabs([
                    Tab::make(__('Network'))
                        ->icon('heroicon-o-globe-alt')
                        ->columns(2)
                        ->dehydratedWhenHidden()
                        ->schema([
                            // Strict allow-list, not just a length cap: this
                            // value becomes a systemd instance name, a file
                            // path and part of a root-run iptables line, so
                            // anything outside [a-z0-9] would be an injection
                            // or traversal vector. Mirrors NetworkInput, which
                            // enforces the same at the driver.
                            TextInput::make('interface_name')
                                ->label(__('Interface name'))
                                ->required()
                                ->rule('regex:/^[a-z][a-z0-9]{0,14}$/')
                                ->validationMessages(['regex' => 'Lower-case letters and digits only, starting with a letter, e.g. wg0.'])
                                ->default(fn (Get $get) => self::suggestInterfaceName(self::protocolFrom($get('protocol'))))
                                ->dehydratedWhenHidden()
                                ->disabledOn('edit')
                                ->helperText(__('The network interface and systemd instance name. Cannot be changed after creation.')),

                            TextInput::make('subnet_cidr')
                                ->label(__('Subnet (CIDR)'))
                                ->required()
                                ->rule('regex:/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/')
                                ->validationMessages(['regex' => 'An IPv4 CIDR, e.g. 10.8.0.0/24.'])
                                ->default(fn () => self::suggestSubnet())
                                ->dehydratedWhenHidden()
                                ->disabledOn('edit')
                                ->helperText(__('The private range handed out to clients. The .1 address becomes the tunnel gateway and DNS server.')),

                            TextInput::make('listen_port')
                                ->label(__('Listen port'))
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(65535)
                                ->default(fn (Get $get) => self::suggestListenPort(self::protocolFrom($get('protocol'))))
                                ->dehydratedWhenHidden()
                                ->helperText(__('Open this port in your firewall / cloud security group -- the panel cannot do that for you.')),

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
                                    ? __('WireGuard is UDP-only.')
                                    : __('TCP survives restrictive networks better; UDP is faster.'))
                                ->disabled(fn (Get $get) => self::protocolFrom($get('protocol')) === VpnProtocol::WireGuard),

                            TextInput::make('config.egress_interface')
                                ->label(__('Egress network interface'))
                                ->required()
                                ->rule('regex:/^[a-z][a-z0-9]{0,14}$/')
                                ->validationMessages(['regex' => 'Lower-case letters and digits only, e.g. eth0 or ens5.'])
                                ->default(fn () => self::detectEgressInterface())
                                ->dehydratedWhenHidden()
                                ->helperText(__('The server\'s internet-facing interface, used for the NAT/MASQUERADE rule.')),
                        ]),

                    Tab::make(__('WireGuard'))
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
                                ->helperText(__('1420 suits most links. Lower it if large packets stall over the tunnel.')),

                            TextInput::make('config.keepalive')
                                ->label(__('Persistent keepalive (seconds)'))
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3600)
                                ->default(25)
                                ->dehydratedWhenHidden()
                                ->helperText(__('Keeps NAT mappings alive for clients behind a router. 0 disables it.')),

                            TagsInput::make('config.dns')
                                ->label(__('Custom DNS servers for clients'))
                                ->default(['1.1.1.1', '1.0.0.1'])
                                ->dehydratedWhenHidden()
                                ->visible(fn (Get $get) => $get('config.push_dns') === false)
                                ->helperText(__('Only used when clients are not pointed at this service\'s own resolver.')),

                            TextInput::make('config.client_allowed_ips')
                                ->label(__('Client AllowedIPs'))
                                ->default('0.0.0.0/0, ::/0')
                                ->dehydratedWhenHidden()
                                ->helperText(__('0.0.0.0/0, ::/0 is a full tunnel. Narrow it for split tunnelling.')),
                        ]),

                    Tab::make(__('OpenVPN'))
                        ->icon('heroicon-o-lock-closed')
                        ->columns(2)
                        ->visible(fn (Get $get) => self::protocolFrom($get('protocol')) === VpnProtocol::OpenVpn)
                        ->schema([
                            TextInput::make('config.data_ciphers')
                                ->label(__('Data ciphers'))
                                ->default('AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305')
                                ->dehydratedWhenHidden()
                                // Interpolated into the root-run server.conf, so
                                // restrict it to cipher-name characters -- no
                                // newline can reach a command-executing directive.
                                ->rule('regex:/^[A-Za-z0-9:_-]+$/')
                                ->validationMessages(['regex' => 'Cipher names only (letters, digits, dashes), colon-separated.'])
                                ->helperText(__('Colon-separated, in order of preference.')),

                            Select::make('config.auth_digest')
                                ->label(__('Auth digest'))
                                ->options([
                                    'SHA256' => 'SHA256',
                                    'SHA384' => 'SHA384',
                                    'SHA512' => 'SHA512',
                                ])
                                ->default('SHA256')
                                ->dehydratedWhenHidden(),

                            Select::make('config.tls_version_min')
                                ->label(__('Minimum TLS version'))
                                ->options([
                                    '1.2' => 'TLS 1.2',
                                    '1.3' => 'TLS 1.3',
                                ])
                                ->default('1.2')
                                ->dehydratedWhenHidden()
                                ->helperText(__('1.3 is stricter but rejects older clients.')),

                            // Deliberately not `config.keepalive`: WireGuard
                            // uses that key for a single number, and sharing
                            // it meant an OpenVPN service could be created
                            // holding a WireGuard-shaped value, which OpenVPN
                            // rejects outright -- the server then never
                            // starts at all.
                            TextInput::make('config.openvpn_keepalive')
                                ->label(__('Keepalive (ping ping-restart)'))
                                ->default('10 60')
                                ->dehydratedWhenHidden()
                                ->rule('regex:/^\d+\s+\d+$/')
                                ->validationMessages(['regex' => 'Two numbers separated by a space, for example 10 60.'])
                                ->helperText(__('Two numbers: ping interval and restart timeout, in seconds.')),

                            TextInput::make('config.max_clients')
                                ->label(__('Maximum simultaneous clients'))
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(4096)
                                ->dehydratedWhenHidden()
                                ->placeholder(__('unlimited'))
                                ->helperText(__('Leave empty for no limit.')),

                            TextInput::make('config.verb')
                                ->label(__('Log verbosity'))
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(11)
                                ->default(3)
                                ->dehydratedWhenHidden()
                                ->helperText(__('3 is normal. 5 and above is very noisy.')),

                            Toggle::make('config.redirect_gateway')
                                ->label(__('Route all client traffic through this service'))
                                ->default(true)
                                ->dehydratedWhenHidden(),

                            Toggle::make('config.duplicate_cn')
                                ->label(__('Allow one certificate on several devices at once'))
                                ->default(false)
                                ->dehydratedWhenHidden()
                                ->helperText(__('Off means a second device using the same client config kicks the first one off.')),

                            TagsInput::make('config.push_routes')
                                ->label(__('Additional routes to push'))
                                ->dehydratedWhenHidden()
                                ->placeholder('192.168.1.0 255.255.255.0')
                                // Each route is two IPv4s. The driver drops
                                // anything else, and this rejects it at entry so
                                // no element can carry a newline into server.conf.
                                ->nestedRecursiveRules(['regex:/^(\d{1,3}\.){3}\d{1,3}( (\d{1,3}\.){3}\d{1,3})?$/'])
                                ->helperText(__('OpenVPN route syntax: network then netmask.'))
                                ->columnSpanFull(),
                        ]),

                    Tab::make(__('DNS & logging'))
                        ->icon('heroicon-o-document-magnifying-glass')
                        ->columns(2)
                        ->dehydratedWhenHidden()
                        ->schema([
                            Toggle::make('logging_enabled_default')
                                ->label(__('Log activity for users of this service by default'))
                                ->default(true)
                                ->dehydratedWhenHidden()
                                ->helperText(__('Can be overridden per user. Covers connection metadata, DNS-visible domains and plaintext HTTP.'))
                                ->columnSpanFull(),

                            Toggle::make('config.push_dns')
                                ->label(__('Point clients at this service\'s own DNS resolver'))
                                ->default(true)
                                ->live()
                                ->dehydratedWhenHidden()
                                ->helperText(__('Required for DNS traffic logging: queries have to pass through this service\'s resolver for the capture agent to see them. It forwards on to the upstreams below, so name resolution is unaffected. Turning this off leaves the Traffic logs tab permanently empty.'))
                                ->columnSpanFull(),

                            // Not stored: it only decides what goes into the
                            // upstream list below, which is the value the
                            // resolver actually reads.
                            Select::make('dns_preset')
                                ->label(__('Upstream DNS provider'))
                                ->options(DnsPresets::options())
                                ->default(fn (Get $get) => DnsPresets::keyFor($get('config.dns_upstreams') ?? []))
                                ->live()
                                ->dehydrated(false)
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    if ($state === null || $state === DnsPresets::CUSTOM) {
                                        return; // Leave whatever is there to be edited by hand.
                                    }

                                    $set('config.dns_upstreams', DnsPresets::serversFor($state));
                                })
                                ->helperText(fn (Get $get) => DnsPresets::noteFor($get('dns_preset'))
                                    ?? __('Where this service\'s resolver forwards queries it cannot answer itself. Each service can use a different one.'))
                                ->columnSpanFull(),

                            TagsInput::make('config.dns_upstreams')
                                ->label(__('Upstream DNS servers'))
                                ->default(['1.1.1.1', '1.0.0.1'])
                                ->dehydratedWhenHidden()
                                // Shown for a preset too, read-only, so what
                                // was chosen is visible rather than implied.
                                ->disabled(fn (Get $get) => $get('dns_preset') !== DnsPresets::CUSTOM)
                                ->dehydrated()
                                ->helperText(__('Cleared, this falls back to Cloudflare rather than leaving the resolver with nowhere to forward to.'))
                                ->columnSpanFull(),

                            TagsInput::make('config.blocked_domains')
                                ->label(__('Blocked domains'))
                                ->placeholder('ads.example.com')
                                ->dehydratedWhenHidden()
                                ->helperText(__('Blocked for everyone on this service, subdomains included -- entering example.com also covers www.example.com. The lookup is refused by this service\'s own resolver, so it never reaches an upstream. A client using its own DNS server bypasses this entirely.'))
                                ->columnSpanFull(),

                            Toggle::make('config.use_blocklists')
                                ->label(__('Apply subscription blocklists'))
                                ->default(true)
                                ->dehydratedWhenHidden()
                                ->helperText(__('Serve the shared, auto-updated blocklists (managed under Blocklists) on this service too. Turn off for a service that should not be filtered.'))
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    /**
     * The .1 of the subnet: the tunnel's own address, which is both the
     * gateway clients route through and where this service's DNS resolver
     * listens. Mirrors DnsmasqManager's own calculation.
     */
    private static function gatewayAddress(string $subnetCidr): string
    {
        $parts = explode('.', explode('/', $subnetCidr)[0]);
        $parts[3] = '1';

        return implode('.', $parts);
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
