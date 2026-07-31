<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\EnforceUserLimits;
use App\Models\BandwidthSample;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\DriverFactory;
use App\Services\Vpn\VpnProtocolDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UserLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

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

        // Enforcement re-applies the service config; the real driver would
        // reach for /etc/wireguard.
        $driver = Mockery::mock(VpnProtocolDriver::class);
        $driver->shouldReceive('applyServiceConfig')->andReturnNull();
        $this->mock(DriverFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($driver));
    }

    private function user(array $attributes = []): ServiceUser
    {
        return ServiceUser::create(array_merge([
            'service_id' => $this->service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ], $attributes));
    }

    private function recordTraffic(ServiceUser $user, int $bytes, ?string $at = null): void
    {
        BandwidthSample::create([
            'service_id' => $this->service->id,
            'service_user_id' => $user->id,
            'sampled_at' => $at ?? now(),
            'bytes_in_delta' => $bytes,
            'bytes_out_delta' => 0,
        ]);
    }

    public function test_a_user_past_their_expiry_date_is_suspended(): void
    {
        $user = $this->user(['expires_at' => now()->subMinute()]);

        (new EnforceUserLimits)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $user->status);
        $this->assertSame('expired', $user->suspended_reason);
    }

    public function test_a_user_within_their_expiry_date_is_left_alone(): void
    {
        $user = $this->user(['expires_at' => now()->addDay()]);

        (new EnforceUserLimits)->handle();

        $this->assertSame(ServiceUserStatus::Active, $user->refresh()->status);
    }

    public function test_a_user_over_their_allowance_is_suspended(): void
    {
        $user = $this->user(['data_limit_bytes' => 1_000_000, 'quota_started_at' => now()->subDay()]);
        $this->recordTraffic($user, 1_200_000);

        (new EnforceUserLimits)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $user->status);
        $this->assertSame('data limit reached', $user->suspended_reason);
    }

    /**
     * Moving the window forward is how an allowance is reset, so traffic from
     * before it must stop counting.
     */
    public function test_traffic_before_the_window_does_not_count(): void
    {
        $user = $this->user(['data_limit_bytes' => 1_000_000, 'quota_started_at' => now()->subHour()]);
        $this->recordTraffic($user, 5_000_000, now()->subDays(3)->toDateTimeString());

        (new EnforceUserLimits)->handle();

        $this->assertSame(ServiceUserStatus::Active, $this->service->serviceUsers()->first()->status);
        $this->assertSame(0, $user->refresh()->dataUsedBytes());
    }

    public function test_usage_is_summed_across_samples(): void
    {
        $user = $this->user(['data_limit_bytes' => 10_000_000, 'quota_started_at' => now()->subDay()]);
        $this->recordTraffic($user, 400_000);
        $this->recordTraffic($user, 600_000);

        $this->assertSame(1_000_000, $user->refresh()->dataUsedBytes());
        $this->assertSame(0.1, round($user->dataUsageRatio(), 2));
    }

    public function test_a_user_with_no_limits_is_never_touched(): void
    {
        $user = $this->user();
        $this->recordTraffic($user, 900_000_000_000);

        (new EnforceUserLimits)->handle();

        $this->assertSame(ServiceUserStatus::Active, $user->refresh()->status);
        $this->assertNull($user->dataUsageRatio());
    }

    public function test_an_already_suspended_user_is_not_reprocessed(): void
    {
        $user = $this->user([
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => 'suspended by hand',
            'expires_at' => now()->subDay(),
        ]);

        (new EnforceUserLimits)->handle();

        // The reason must survive: overwriting it with "expired" would lose
        // the fact that someone chose this deliberately.
        $this->assertSame('suspended by hand', $user->refresh()->suspended_reason);
    }
}
