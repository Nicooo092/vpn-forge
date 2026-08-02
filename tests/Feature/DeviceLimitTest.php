<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\EnforceDeviceLimits;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\OpenVpn\OpenVpnDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeviceLimitTest extends TestCase
{
    use RefreshDatabase;

    private function ovpnService(): Service
    {
        return Service::create([
            'name' => 'ovpn',
            'interface_name' => 'tun0',
            'subnet_cidr' => '10.8.0.0/24',
            'listen_port' => 1194,
            'protocol' => VpnProtocol::OpenVpn,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [],
        ]);
    }

    public function test_it_parses_client_list_rows_grouped_by_common_name(): void
    {
        $response = implode("\n", [
            'TITLE,OpenVPN 2.6.0',
            'TIME,2024-01-01 12:00:00,1700000200',
            'HEADER,CLIENT_LIST,Common Name,Real Address,Virtual Address,...',
            'CLIENT_LIST,cn1,1.2.3.4:5555,10.8.0.2,,1000,2000,x,1700000000,UNDEF,3,0,AES',
            'CLIENT_LIST,cn1,5.6.7.8:9999,10.8.0.3,,1000,2000,x,1700000100,UNDEF,4,0,AES',
            'CLIENT_LIST,cn2,9.9.9.9:1111,10.8.0.4,,1,2,x,1700000150,UNDEF,5,0,AES',
            'END',
        ]);

        $parsed = OpenVpnDriver::parseClientList($response);

        $this->assertCount(2, $parsed['cn1']);
        $this->assertSame('1.2.3.4:5555', $parsed['cn1'][0]['real_address']);
        $this->assertSame(1700000000, $parsed['cn1'][0]['connected_since']);
        $this->assertCount(1, $parsed['cn2']);
        // The HEADER,CLIENT_LIST,... line is not a connection.
        $this->assertArrayNotHasKey('Common Name', $parsed);
    }

    public function test_kill_connection_refuses_an_implausible_address(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing');

        // A newline that would otherwise smuggle a second management command.
        (new OpenVpnDriver)->killConnection($this->ovpnService(), "1.2.3.4:5555\nkill 6.6.6.6:1");
    }

    public function test_kill_connection_rejects_a_trailing_newline(): void
    {
        // \z (not $) so even a lone trailing newline is refused.
        $this->expectException(RuntimeException::class);

        (new OpenVpnDriver)->killConnection($this->ovpnService(), "1.2.3.4:5555\n");
    }

    public function test_it_kills_the_newest_connections_over_the_cap(): void
    {
        $service = $this->ovpnService();
        ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'shared',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.8.0.2',
            'openvpn_common_name' => 'cn1',
            'max_connections' => 1,
        ]);

        $driver = Mockery::mock(OpenVpnDriver::class);
        $driver->shouldReceive('connectionsByCommonName')->once()->andReturn([
            'cn1' => [
                ['real_address' => 'A', 'connected_since' => 100], // oldest -> kept
                ['real_address' => 'B', 'connected_since' => 300], // newest -> killed
                ['real_address' => 'C', 'connected_since' => 200], // killed
            ],
        ]);
        // Keep the oldest (A); drop the two newest.
        $driver->shouldReceive('killConnection')->once()->with(Mockery::any(), 'C');
        $driver->shouldReceive('killConnection')->once()->with(Mockery::any(), 'B');
        $this->app->instance(OpenVpnDriver::class, $driver);

        (new EnforceDeviceLimits)->handle();

        // The kill expectations above are verified on tearDown.
        $this->assertTrue(true);
    }

    public function test_it_leaves_connections_within_the_cap_alone(): void
    {
        $service = $this->ovpnService();
        ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'shared',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.8.0.2',
            'openvpn_common_name' => 'cn1',
            'max_connections' => 2,
        ]);

        $driver = Mockery::mock(OpenVpnDriver::class);
        $driver->shouldReceive('connectionsByCommonName')->once()->andReturn([
            'cn1' => [
                ['real_address' => 'A', 'connected_since' => 100],
                ['real_address' => 'B', 'connected_since' => 200],
            ],
        ]);
        $driver->shouldReceive('killConnection')->never();
        $this->app->instance(OpenVpnDriver::class, $driver);

        (new EnforceDeviceLimits)->handle();

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
