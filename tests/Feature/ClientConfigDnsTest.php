<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\WireGuard\WireGuardDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DNS traffic logging only works if client queries actually traverse the
 * per-service dnsmasq instance. Handing clients a public resolver instead
 * leaves the capture agent with nothing to read while everything else looks
 * healthy, so the DNS line in a generated config is worth asserting on
 * directly.
 */
class ClientConfigDnsTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(array $config = []): Service
    {
        return Service::create([
            'name' => 'Logged tunnel',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.60.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => array_merge([
                'endpoint_host' => 'vpn.example.com',
                'server_public_key' => 'server-public-key',
            ], $config),
        ]);
    }

    private function makeUser(Service $service): ServiceUser
    {
        return ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.60.0.2',
            'wg_private_key' => 'client-private-key',
            'wg_public_key' => 'client-public-key',
            'wg_preshared_key' => 'client-preshared-key',
        ]);
    }

    public function test_clients_are_pointed_at_the_services_own_resolver_by_default(): void
    {
        $service = $this->makeService();
        $file = (new WireGuardDriver)->buildClientConfig($service, $this->makeUser($service));

        // The .1 of the subnet, which is where DnsmasqManager binds.
        $this->assertStringContainsString('DNS = 10.60.0.1', $file->contents);
        $this->assertStringNotContainsString('1.1.1.1', $file->contents);
    }

    public function test_a_full_tunnel_still_routes_that_resolver(): void
    {
        $service = $this->makeService();
        $file = (new WireGuardDriver)->buildClientConfig($service, $this->makeUser($service));

        // A DNS server inside the tunnel is only reachable if AllowedIPs
        // covers it -- otherwise the client resolves nothing at all.
        $this->assertStringContainsString('AllowedIPs = 0.0.0.0/0, ::/0', $file->contents);
    }

    public function test_custom_resolvers_are_honoured_when_push_dns_is_off(): void
    {
        $service = $this->makeService([
            'push_dns' => false,
            'dns' => ['9.9.9.9', '149.112.112.112'],
        ]);

        $file = (new WireGuardDriver)->buildClientConfig($service, $this->makeUser($service));

        $this->assertStringContainsString('DNS = 9.9.9.9, 149.112.112.112', $file->contents);
        $this->assertStringNotContainsString('DNS = 10.60.0.1', $file->contents);
    }
}
