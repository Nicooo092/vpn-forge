<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\OpenVpn\OpenVpnDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenVPN hands out virtual addresses from its own dynamic pool in connection
 * order. Unless each client is pinned to the tunnel IP the panel recorded, that
 * stored address never matches the one the client actually gets -- which
 * silently breaks status polling, bandwidth/quota accounting, per-user tc
 * shaping and the capture agent's DNS/HTTP attribution, all of which key on
 * service_users.tunnel_ip. The pin is an `ifconfig-push` line in the client's
 * client-config-dir entry.
 */
class OpenVpnClientPinningTest extends TestCase
{
    use RefreshDatabase;

    private function service(): Service
    {
        return Service::create([
            'name' => 'office',
            'interface_name' => 'tun0',
            'subnet_cidr' => '10.8.0.0/24',
            'listen_port' => 1194,
            'protocol' => VpnProtocol::OpenVpn,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => ['endpoint_host' => '203.0.113.10'],
        ]);
    }

    private function user(Service $service, array $attributes = []): ServiceUser
    {
        return ServiceUser::create(array_merge([
            'service_id' => $service->id,
            'name' => 'laptop',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.8.0.7',
            'openvpn_common_name' => 'svc1-user1',
        ], $attributes));
    }

    public function test_an_active_user_is_pinned_to_their_recorded_tunnel_ip(): void
    {
        $service = $this->service();
        $lines = (new OpenVpnDriver)->clientConfigDirLines($service, $this->user($service));

        $this->assertContains('ifconfig-push 10.8.0.7 255.255.255.0', $lines);
    }

    public function test_the_pin_carries_the_subnet_mask_of_the_service(): void
    {
        $service = $this->service();
        $service->subnet_cidr = '10.9.0.0/16';
        $service->save();

        $lines = (new OpenVpnDriver)->clientConfigDirLines(
            $service,
            $this->user($service, ['tunnel_ip' => '10.9.0.42']),
        );

        $this->assertContains('ifconfig-push 10.9.0.42 255.255.0.0', $lines);
    }

    public function test_a_suspended_user_is_disabled_and_never_pinned(): void
    {
        $service = $this->service();
        $lines = (new OpenVpnDriver)->clientConfigDirLines(
            $service,
            $this->user($service, ['status' => ServiceUserStatus::Suspended]),
        );

        $this->assertSame(['disable'], $lines);
    }

    public function test_a_dns_override_is_pushed_alongside_the_pin(): void
    {
        $service = $this->service();
        $lines = (new OpenVpnDriver)->clientConfigDirLines(
            $service,
            $this->user($service, ['dns_override' => ['9.9.9.9']]),
        );

        $this->assertContains('ifconfig-push 10.8.0.7 255.255.255.0', $lines);
        $this->assertContains('push "dhcp-option DNS 9.9.9.9"', $lines);
    }
}
