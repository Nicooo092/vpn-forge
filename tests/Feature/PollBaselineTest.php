<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\PollAllServiceStatuses;
use App\Models\BandwidthSample;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\ClientConfigFile;
use App\Services\Vpn\DriverFactory;
use App\Services\Vpn\PeerStatus;
use App\Services\Vpn\VpnProtocolDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PollBaselineTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private ServiceUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Service::create([
            'name' => 'test',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.0.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [],
        ]);

        $this->user = ServiceUser::create([
            'service_id' => $this->service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ]);
    }

    private function pollReporting(int $bytesIn, int $bytesOut): void
    {
        $driver = new class($bytesIn, $bytesOut) implements VpnProtocolDriver
        {
            public function __construct(private int $in, private int $out) {}

            public function pollStatus(Service $service): array
            {
                return ['10.0.0.2' => new PeerStatus(
                    tunnelIp: '10.0.0.2',
                    connected: true,
                    lastHandshakeAt: CarbonImmutable::now(),
                    peerIp: '203.0.113.7',
                    bytesIn: $this->in,
                    bytesOut: $this->out,
                )];
            }

            public function provisionService(Service $service): void {}

            public function applyServiceConfig(Service $service): void {}

            public function addUser(Service $service, ServiceUser $user): void {}

            public function removeUser(Service $service, ServiceUser $user): void {}

            public function rotateUserKeys(Service $service, ServiceUser $user): void {}

            public function buildClientConfig(Service $service, ServiceUser $user): ClientConfigFile
            {
                return new ClientConfigFile('x.conf', '');
            }

            public function removeService(Service $service): void {}
        };

        $this->mock(DriverFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($driver));

        (new PollAllServiceStatuses)->handle();
    }

    /**
     * Recovering a service that has been up and passing traffic while the
     * poller was blind used to book the entire accumulated counter as one
     * delta -- a multi-gigabyte spike in a single bucket that flattened every
     * real reading on the chart.
     */
    public function test_the_first_sighting_of_a_peer_seeds_the_baseline_instead_of_charting_it(): void
    {
        $this->pollReporting(bytesIn: 3_000_000_000, bytesOut: 2_000_000_000);

        $this->assertSame(0, BandwidthSample::count());

        $this->user->refresh();
        $this->assertSame(3_000_000_000, $this->user->last_cumulative_bytes_in);
        $this->assertNotNull($this->user->last_handshake_at);
    }

    public function test_traffic_after_the_baseline_is_charted_normally(): void
    {
        $this->pollReporting(bytesIn: 3_000_000_000, bytesOut: 2_000_000_000);
        $this->pollReporting(bytesIn: 3_000_500_000, bytesOut: 2_000_250_000);

        $userSample = BandwidthSample::whereNotNull('service_user_id')->firstOrFail();

        $this->assertSame(500_000, $userSample->bytes_in_delta);
        $this->assertSame(250_000, $userSample->bytes_out_delta);
    }
}
