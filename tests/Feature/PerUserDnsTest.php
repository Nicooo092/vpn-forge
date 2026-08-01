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

class PerUserDnsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): Service
    {
        return Service::create([
            'name' => 'home',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.0.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => ['push_dns' => true, 'server_public_key' => 'PUBKEY', 'endpoint_host' => 'vpn.example.com'],
        ]);
    }

    private function user(Service $service, ?array $dnsOverride): ServiceUser
    {
        $user = ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
            'dns_override' => $dnsOverride,
        ]);

        $user->forceFill([
            'wg_private_key' => 'PRIVKEY',
            'wg_preshared_key' => 'PSK',
        ])->save();

        return $user->refresh();
    }

    private function dnsLine(Service $service, ServiceUser $user): string
    {
        foreach (explode("\n", (new WireGuardDriver)->buildClientConfig($service, $user)->contents) as $line) {
            if (str_starts_with(trim($line), 'DNS =')) {
                return trim($line);
            }
        }

        return '';
    }

    public function test_without_an_override_the_client_points_at_the_service_gateway(): void
    {
        $service = $this->service();
        $user = $this->user($service, null);

        // 10.0.0.1 is the gateway = this service's dnsmasq = where logging happens.
        $this->assertSame('DNS = 10.0.0.1', $this->dnsLine($service, $user));
    }

    public function test_an_override_replaces_the_service_resolver(): void
    {
        $service = $this->service();
        $user = $this->user($service, ['1.1.1.1', '9.9.9.9']);

        $line = $this->dnsLine($service, $user);

        $this->assertSame('DNS = 1.1.1.1, 9.9.9.9', $line);
        // The whole point of the trade-off: the gateway is no longer used, so
        // this user's lookups stop reaching the panel resolver.
        $this->assertStringNotContainsString('10.0.0.1', $line);
    }

    public function test_an_empty_override_falls_back_to_the_service_gateway(): void
    {
        $service = $this->service();
        $user = $this->user($service, []);

        $this->assertSame('DNS = 10.0.0.1', $this->dnsLine($service, $user));
    }

    public function test_junk_and_injection_attempts_in_the_override_are_dropped(): void
    {
        $service = $this->service();
        // A hostname, a shell-ish string and a newline payload -- none are bare
        // IPs, so the sink filter drops them, leaving only the real resolver.
        $user = $this->user($service, ['1.1.1.1', 'not-a-dns', "8.8.8.8\nPostUp = touch /tmp/x"]);

        $line = $this->dnsLine($service, $user);

        $this->assertSame('DNS = 1.1.1.1', $line);
        $this->assertStringNotContainsString('PostUp', $this->buildFull($service, $user));
    }

    private function buildFull(Service $service, ServiceUser $user): string
    {
        return (new WireGuardDriver)->buildClientConfig($service, $user)->contents;
    }
}
