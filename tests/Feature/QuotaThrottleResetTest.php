<?php

namespace Tests\Feature;

use App\Enums\QuotaAction;
use App\Enums\QuotaReset;
use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\EnforceUserLimits;
use App\Jobs\Vpn\ResetUserQuotas;
use App\Models\BandwidthSample;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\DriverFactory;
use App\Services\Vpn\TrafficShaper;
use App\Services\Vpn\VpnProtocolDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class QuotaThrottleResetTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // tc/HTB never actually runs under test -- every apply is faked so the
        // shaping pass a throttle/reset triggers cannot shell out.
        Process::fake();

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

        // Enforcement re-applies the service config; the real driver would reach
        // for /etc/wireguard.
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

    // --- (1) Throttle instead of cut-off -------------------------------------

    public function test_an_over_allowance_user_set_to_throttle_is_not_suspended(): void
    {
        $user = $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => now()->subDay(),
            'quota_action' => QuotaAction::Throttle,
            'quota_throttle_kbps' => 500,
        ]);
        $this->recordTraffic($user, 1_200_000);

        (new EnforceUserLimits)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Active, $user->status);
        $this->assertTrue($user->quota_throttled);
        $this->assertNull($user->suspended_reason);
    }

    public function test_a_throttled_user_is_capped_to_the_throttle_rate_by_the_shaper(): void
    {
        $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => now()->subDay(),
            'quota_action' => QuotaAction::Throttle,
            'quota_throttle_kbps' => 500,
            'quota_throttled' => true,
        ]);

        $commands = collect((new TrafficShaper)->plan($this->service))
            ->map(fn ($step) => implode(' ', $step['argv']));

        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'classid 1:100 htb rate 500kbit ceil 500kbit')));
        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'match ip dst 10.0.0.2/32 flowid 1:100')));
    }

    public function test_the_lower_of_the_speed_limit_and_the_throttle_wins(): void
    {
        // Throttle (500) below the standing limit (5000): throttle wins.
        $user = $this->user([
            'rate_limit_kbps' => 5000,
            'quota_throttle_kbps' => 500,
            'quota_throttled' => true,
        ]);
        $this->assertSame(500, $user->effectiveRateLimitKbps());

        // Not throttled: the standing limit stands.
        $user->forceFill(['quota_throttled' => false]);
        $this->assertSame(5000, $user->effectiveRateLimitKbps());
    }

    public function test_a_throttle_is_lifted_once_back_under_the_allowance(): void
    {
        // Marked throttled, but no traffic in the current window -> under again.
        $user = $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => now()->subDay(),
            'quota_action' => QuotaAction::Throttle,
            'quota_throttle_kbps' => 500,
            'quota_throttled' => true,
        ]);

        (new EnforceUserLimits)->handle();

        $this->assertFalse($user->refresh()->quota_throttled);
    }

    public function test_over_allowance_still_suspends_when_the_action_is_suspend(): void
    {
        $user = $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => now()->subDay(),
            'quota_action' => QuotaAction::Suspend,
        ]);
        $this->recordTraffic($user, 1_200_000);

        (new EnforceUserLimits)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $user->status);
        $this->assertSame('data limit reached', $user->suspended_reason);
        $this->assertFalse($user->quota_throttled);
    }

    // --- (2) Automatic reset maths -------------------------------------------

    public function test_a_weekly_reset_is_not_due_before_the_week_elapses(): void
    {
        $user = $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => now()->subDays(3),
            'quota_reset' => QuotaReset::Weekly,
        ]);

        $this->assertNull($user->dueQuotaReset());
    }

    public function test_a_weekly_reset_rolls_forward_one_week(): void
    {
        $user = $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => Carbon::parse('2026-08-01 00:00:00'),
            'quota_reset' => QuotaReset::Weekly,
        ]);

        $due = $user->dueQuotaReset(Carbon::parse('2026-08-10 12:00:00'));

        $this->assertNotNull($due);
        $this->assertSame('2026-08-08 00:00:00', $due->toDateTimeString());
    }

    public function test_missed_periods_collapse_to_the_most_recent_boundary(): void
    {
        // Several weeks elapsed while nothing ran: the new start is the latest
        // boundary at or before now, never a future date.
        $user = $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => Carbon::parse('2026-08-01 00:00:00'),
            'quota_reset' => QuotaReset::Weekly,
        ]);

        $now = Carbon::parse('2026-08-26 09:00:00');
        $due = $user->dueQuotaReset($now);

        // 08-01 -> 08-08 -> 08-15 -> 08-22 (08-29 would be in the future).
        $this->assertSame('2026-08-22 00:00:00', $due->toDateTimeString());
        $this->assertTrue($due->lessThanOrEqualTo($now));
    }

    public function test_a_monthly_reset_does_not_overflow_a_short_month(): void
    {
        $user = $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => Carbon::parse('2026-01-31 00:00:00'),
            'quota_reset' => QuotaReset::Monthly,
        ]);

        $due = $user->dueQuotaReset(Carbon::parse('2026-03-05 00:00:00'));

        // 01-31 + 1 month lands on 02-28, not spilled into March.
        $this->assertSame('2026-02-28 00:00:00', $due->toDateTimeString());
    }

    public function test_no_reset_is_due_without_a_cadence(): void
    {
        $user = $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => now()->subYear(),
            'quota_reset' => QuotaReset::None,
        ]);

        $this->assertNull($user->dueQuotaReset());
    }

    public function test_the_reset_job_moves_the_window_and_clears_a_throttle(): void
    {
        $user = $this->user([
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => now()->subDays(8),
            'quota_reset' => QuotaReset::Weekly,
            'quota_action' => QuotaAction::Throttle,
            'quota_throttle_kbps' => 500,
            'quota_throttled' => true,
        ]);

        (new ResetUserQuotas)->handle();

        $user->refresh();
        $this->assertFalse($user->quota_throttled);
        // 8 days ago + 1 week = ~1 day ago: the window advanced by a period.
        $this->assertTrue($user->quota_started_at->greaterThan(now()->subDays(2)));
    }

    public function test_the_reset_job_resumes_a_quota_suspended_user(): void
    {
        $user = $this->user([
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => 'data limit reached',
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => now()->subDays(8),
            'quota_reset' => QuotaReset::Weekly,
        ]);

        (new ResetUserQuotas)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Active, $user->status);
        $this->assertNull($user->suspended_reason);
    }

    public function test_the_reset_job_leaves_a_hand_suspended_user_alone(): void
    {
        // Only a data-limit suspension is cleared -- a manual one must survive.
        $user = $this->user([
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => 'suspended by hand',
            'data_limit_bytes' => 1_000_000,
            'quota_started_at' => now()->subDays(8),
            'quota_reset' => QuotaReset::Weekly,
        ]);

        (new ResetUserQuotas)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $user->status);
        $this->assertSame('suspended by hand', $user->suspended_reason);
    }
}
