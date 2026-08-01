<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Services\Vpn\OpenVpn\OpenVpnDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenVPN refuses to start on a single malformed directive -- there is no
 * partial success, the unit just restart-loops. Every value the panel can put
 * into this file is worth asserting on.
 */
class OpenVpnServerConfigTest extends TestCase
{
    use RefreshDatabase;

    private int $created = 0;

    private function service(array $config = []): Service
    {
        // Interface name and transport+port are both unique, and several of
        // these tests build more than one service to compare configurations.
        $n = $this->created++;

        return Service::create([
            'name' => 'office',
            'interface_name' => "tun{$n}",
            'subnet_cidr' => '10.8.0.0/24',
            'listen_port' => 1194 + $n,
            'protocol' => VpnProtocol::OpenVpn,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => array_merge(['endpoint_host' => '203.0.113.10'], $config),
        ]);
    }

    private function directive(string $contents, string $name): ?string
    {
        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with(trim($line), $name.' ')) {
                return trim($line);
            }
        }

        return null;
    }

    /**
     * WireGuard stores a single number under `keepalive`. An OpenVPN service
     * carrying one produced `keepalive 25`, which OpenVPN rejects outright --
     * the server never started and the client sat on "Trying to connect".
     */
    public function test_a_wireguard_shaped_keepalive_cannot_reach_the_config(): void
    {
        $config = (new OpenVpnDriver)->buildServerConfig($this->service(['keepalive' => 25]));

        $this->assertSame('keepalive 10 60', $this->directive($config, 'keepalive'));
    }

    public function test_a_malformed_keepalive_falls_back(): void
    {
        foreach (['', '   ', 'abc', '10', '10 60 90', '10;rm -rf /'] as $bad) {
            $config = (new OpenVpnDriver)->buildServerConfig($this->service(['openvpn_keepalive' => $bad]));

            $this->assertSame(
                'keepalive 10 60',
                $this->directive($config, 'keepalive'),
                'keepalive should have fallen back for '.var_export($bad, true),
            );
        }
    }

    public function test_a_valid_keepalive_is_kept(): void
    {
        $config = (new OpenVpnDriver)->buildServerConfig($this->service(['openvpn_keepalive' => '5 30']));

        $this->assertSame('keepalive 5 30', $this->directive($config, 'keepalive'));
    }

    public function test_the_defaults_produce_a_config_openvpn_would_accept(): void
    {
        $config = (new OpenVpnDriver)->buildServerConfig($this->service());

        $this->assertSame('keepalive 10 60', $this->directive($config, 'keepalive'));
        $this->assertSame('port 1194', $this->directive($config, 'port'));
        $this->assertSame('proto udp', $this->directive($config, 'proto'));
        $this->assertSame('server 10.8.0.0 255.255.255.0', $this->directive($config, 'server'));
        $this->assertSame('auth SHA256', $this->directive($config, 'auth'));
        $this->assertSame('tls-version-min 1.2', $this->directive($config, 'tls-version-min'));
        $this->assertSame('verb 3', $this->directive($config, 'verb'));

        // The directory this names must exist or the server refuses to start.
        $this->assertStringContainsString('client-config-dir /etc/openvpn/vpnforge/tun0/ccd', $config);

        // Without this the per-service resolver never sees a query and traffic
        // logging captures nothing for OpenVPN.
        $this->assertStringContainsString('push "dhcp-option DNS 10.8.0.1"', $config);
    }

    public function test_optional_directives_are_absent_rather_than_empty(): void
    {
        $config = (new OpenVpnDriver)->buildServerConfig($this->service());

        // An empty `max-clients` or a bare `duplicate-cn` line would be just
        // as fatal as a bad keepalive.
        $this->assertStringNotContainsString('max-clients', $config);
        $this->assertStringNotContainsString('duplicate-cn', $config);

        $withLimits = (new OpenVpnDriver)->buildServerConfig($this->service([
            'max_clients' => 25,
            'duplicate_cn' => true,
            'push_dns' => false,
            'redirect_gateway' => false,
        ]));

        $this->assertSame('max-clients 25', $this->directive($withLimits, 'max-clients'));
        $this->assertStringContainsString("duplicate-cn\n", $withLimits);
        $this->assertStringNotContainsString('dhcp-option DNS', $withLimits);
        $this->assertStringNotContainsString('redirect-gateway', $withLimits);
    }

    public function test_no_directive_is_left_without_its_value(): void
    {
        $config = (new OpenVpnDriver)->buildServerConfig($this->service([
            'max_clients' => '',
            'push_routes' => [],
        ]));

        foreach (explode("\n", $config) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Every directive this file uses takes at least one argument, so a
            // lone word is a value that went missing somewhere.
            $this->assertMatchesRegularExpression(
                '/^[a-z-]+(\s+\S|\s*$)/',
                $line,
                "suspicious line: {$line}",
            );
            $this->assertNotSame('keepalive', $line);
            $this->assertNotSame('max-clients', $line);
        }
    }
}
