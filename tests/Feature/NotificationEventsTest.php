<?php

namespace Tests\Feature;

use App\Enums\NotificationEvent;
use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\EnforceUserLimits;
use App\Jobs\Vpn\PollAllServiceStatuses;
use App\Models\BandwidthSample;
use App\Models\ConnectionLog;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Notifications\Notifier;
use App\Services\Vpn\PeerStatus;
use App\Services\Vpn\WireGuard\WireGuardDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class NotificationEventsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private function spyNotifier(): Mockery\MockInterface
    {
        $spy = Mockery::spy(Notifier::class);
        $this->app->instance(Notifier::class, $spy);

        return $spy;
    }

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
            'config' => [],
        ]);
    }

    private function fakeWgDriver(): void
    {
        $driver = Mockery::mock(WireGuardDriver::class);
        $driver->shouldReceive('applyServiceConfig')->andReturnNull();
        $this->app->instance(WireGuardDriver::class, $driver);
    }

    public function test_a_service_entering_error_fires_the_event(): void
    {
        $spy = $this->spyNotifier();
        $service = $this->service();

        $service->forceFill(['status' => ServiceStatus::Error, 'last_error' => 'boom'])->save();

        $spy->shouldHaveReceived('event')
            ->with(NotificationEvent::ServiceError, Mockery::type('string'), Mockery::type('string'))
            ->once();
    }

    public function test_a_service_staying_in_error_does_not_re_fire(): void
    {
        $service = $this->service();
        $service->forceFill(['status' => ServiceStatus::Error, 'last_error' => 'boom'])->save();

        // Spy installed AFTER it is already in error; a save that does not move
        // status must not notify.
        $spy = $this->spyNotifier();
        $service->forceFill(['last_error' => 'still boom'])->save();

        $spy->shouldNotHaveReceived('event');
    }

    public function test_a_user_near_the_allowance_is_warned_once(): void
    {
        $spy = $this->spyNotifier();
        $service = $this->service();
        $user = ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
            'data_limit_bytes' => 1000,
            'quota_started_at' => now()->subDay(),
        ]);
        BandwidthSample::create([
            'service_id' => $service->id,
            'service_user_id' => $user->id,
            'sampled_at' => now(),
            'bytes_in_delta' => 950, // 95% of 1000
            'bytes_out_delta' => 0,
        ]);

        (new EnforceUserLimits)->handle();
        (new EnforceUserLimits)->handle(); // deduped by cache

        $spy->shouldHaveReceived('event')
            ->with(NotificationEvent::QuotaWarning, Mockery::type('string'), Mockery::type('string'))
            ->once();
    }

    public function test_suspending_a_user_fires_the_event(): void
    {
        $spy = $this->spyNotifier();
        $this->fakeWgDriver();
        $service = $this->service();
        ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
            'expires_at' => now()->subDay(), // already expired
        ]);

        (new EnforceUserLimits)->handle();

        $spy->shouldHaveReceived('event')
            ->with(NotificationEvent::UserSuspended, Mockery::type('string'), Mockery::type('string'))
            ->once();
    }

    public function test_a_connection_from_a_new_network_fires_new_location(): void
    {
        $spy = $this->spyNotifier();
        $service = $this->service();
        $user = ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ]);
        // Prior history from a different /24.
        ConnectionLog::create([
            'service_user_id' => $user->id,
            'connected_at' => now()->subDay(),
            'disconnected_at' => now()->subDay(),
            'peer_ip' => '8.8.8.8',
        ]);

        $this->pollReturning('1.2.3.4');

        (new PollAllServiceStatuses)->handle();

        $spy->shouldHaveReceived('event')
            ->with(NotificationEvent::NewLocation, Mockery::type('string'), Mockery::type('string'))
            ->once();
    }

    public function test_a_connection_from_a_known_network_does_not_fire(): void
    {
        $spy = $this->spyNotifier();
        $service = $this->service();
        $user = ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ]);
        // Same /24 as the incoming connection.
        ConnectionLog::create([
            'service_user_id' => $user->id,
            'connected_at' => now()->subDay(),
            'disconnected_at' => now()->subDay(),
            'peer_ip' => '1.2.3.9',
        ]);

        $this->pollReturning('1.2.3.4');

        (new PollAllServiceStatuses)->handle();

        $spy->shouldNotHaveReceived('event');
    }

    private function pollReturning(string $peerIp): void
    {
        $driver = Mockery::mock(WireGuardDriver::class);
        $driver->shouldReceive('pollStatus')->andReturn([
            '10.0.0.2' => new PeerStatus(
                tunnelIp: '10.0.0.2',
                connected: true,
                lastHandshakeAt: CarbonImmutable::now(),
                peerIp: $peerIp,
                bytesIn: 0,
                bytesOut: 0,
            ),
        ]);
        $this->app->instance(WireGuardDriver::class, $driver);
    }
}
