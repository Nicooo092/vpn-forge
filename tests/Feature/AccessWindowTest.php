<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\EnforceAccessWindows;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\WireGuard\WireGuardDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class AccessWindowTest extends TestCase
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
            'config' => [],
        ]);
    }

    private function user(Service $service, array $attributes): ServiceUser
    {
        return ServiceUser::create(array_merge([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ], $attributes));
    }

    // --- Model window logic (deterministic via explicit instants) -------

    public function test_no_schedule_is_always_within(): void
    {
        $user = $this->user($this->service(), []);

        $this->assertFalse($user->hasAccessSchedule());
        $this->assertTrue($user->isWithinAccessWindow(Carbon::parse('2024-01-01 03:00:00', 'UTC')));
    }

    public function test_a_day_restriction_is_honoured(): void
    {
        // Weekdays only (Mon=1 .. Fri=5).
        $user = $this->user($this->service(), ['access_days' => [1, 2, 3, 4, 5]]);

        // 2024-01-01 is a Monday, 2024-01-06 a Saturday.
        $this->assertTrue($user->isWithinAccessWindow(Carbon::parse('2024-01-01 12:00:00', 'UTC')));
        $this->assertFalse($user->isWithinAccessWindow(Carbon::parse('2024-01-06 12:00:00', 'UTC')));
    }

    public function test_a_same_day_time_window_is_honoured(): void
    {
        // 16:00-21:00.
        $user = $this->user($this->service(), ['access_start_minute' => 16 * 60, 'access_end_minute' => 21 * 60]);

        $this->assertTrue($user->isWithinAccessWindow(Carbon::parse('2024-01-01 17:00:00', 'UTC')));
        $this->assertFalse($user->isWithinAccessWindow(Carbon::parse('2024-01-01 12:00:00', 'UTC')));
        // End is exclusive.
        $this->assertFalse($user->isWithinAccessWindow(Carbon::parse('2024-01-01 21:00:00', 'UTC')));
    }

    public function test_an_overnight_window_wraps_midnight(): void
    {
        // 22:00-06:00.
        $user = $this->user($this->service(), ['access_start_minute' => 22 * 60, 'access_end_minute' => 6 * 60]);

        $this->assertTrue($user->isWithinAccessWindow(Carbon::parse('2024-01-01 23:00:00', 'UTC')));
        $this->assertTrue($user->isWithinAccessWindow(Carbon::parse('2024-01-01 05:00:00', 'UTC')));
        $this->assertFalse($user->isWithinAccessWindow(Carbon::parse('2024-01-01 12:00:00', 'UTC')));
    }

    public function test_equal_time_bounds_are_all_day_not_a_lockout(): void
    {
        // A user who set 00:00-00:00 expecting "no limit" must not be locked out.
        $user = $this->user($this->service(), ['access_start_minute' => 0, 'access_end_minute' => 0]);

        $this->assertTrue($user->isWithinAccessWindow(Carbon::parse('2024-01-01 03:00:00', 'UTC')));
        $this->assertTrue($user->isWithinAccessWindow(Carbon::parse('2024-01-01 15:00:00', 'UTC')));
    }

    public function test_the_schedule_summary_reads_cleanly(): void
    {
        $user = $this->user($this->service(), [
            'access_days' => [1, 2, 3, 4, 5],
            'access_start_minute' => 16 * 60,
            'access_end_minute' => 21 * 60,
        ]);

        $this->assertSame('Mon, Tue, Wed, Thu, Fri · 16:00-21:00', $user->accessScheduleSummary());
    }

    // --- Enforcement job (frozen clock + mocked driver) -----------------

    private function fakeDriver(): Mockery\MockInterface
    {
        $driver = Mockery::mock(WireGuardDriver::class);
        $this->app->instance(WireGuardDriver::class, $driver);

        return $driver;
    }

    public function test_a_user_is_suspended_when_the_window_closes(): void
    {
        $this->travelTo(Carbon::parse('2024-01-01 12:00:00', 'UTC'));
        $this->fakeDriver()->shouldReceive('applyServiceConfig')->once();

        $service = $this->service();
        // Window 16:00-21:00; it is noon, so outside.
        $user = $this->user($service, ['access_start_minute' => 16 * 60, 'access_end_minute' => 21 * 60]);

        (new EnforceAccessWindows)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $user->status);
        $this->assertSame(EnforceAccessWindows::REASON, $user->suspended_reason);
    }

    public function test_a_user_is_resumed_when_the_window_opens(): void
    {
        $this->travelTo(Carbon::parse('2024-01-01 12:00:00', 'UTC'));
        $this->fakeDriver()->shouldReceive('applyServiceConfig')->once();

        $service = $this->service();
        // Window 08:00-20:00 (inside at noon); currently suspended by this job.
        $user = $this->user($service, [
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => EnforceAccessWindows::REASON,
            'access_start_minute' => 8 * 60,
            'access_end_minute' => 20 * 60,
        ]);

        (new EnforceAccessWindows)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Active, $user->status);
        $this->assertNull($user->suspended_reason);
    }

    public function test_it_never_resumes_a_manually_suspended_user(): void
    {
        $this->travelTo(Carbon::parse('2024-01-01 12:00:00', 'UTC'));
        // No transition, so the driver is never asked to re-apply.
        $this->fakeDriver()->shouldReceive('applyServiceConfig')->never();

        $service = $this->service();
        $user = $this->user($service, [
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => 'suspended by hand',
            'access_start_minute' => 8 * 60,
            'access_end_minute' => 20 * 60,
        ]);

        (new EnforceAccessWindows)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $user->status);
        $this->assertSame('suspended by hand', $user->suspended_reason);
    }

    public function test_it_resumes_a_stranded_user_whose_schedule_was_removed(): void
    {
        $this->travelTo(Carbon::parse('2024-01-01 12:00:00', 'UTC'));
        $this->fakeDriver()->shouldReceive('applyServiceConfig')->once();

        $service = $this->service();
        // Suspended by this job earlier, but the schedule has since been
        // cleared -- must not be stranded suspended forever.
        $user = $this->user($service, [
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => EnforceAccessWindows::REASON,
        ]);

        (new EnforceAccessWindows)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Active, $user->status);
        $this->assertNull($user->suspended_reason);
    }

    public function test_when_the_window_opens_but_the_user_is_expired_it_hands_over(): void
    {
        $this->travelTo(Carbon::parse('2024-01-01 12:00:00', 'UTC'));
        // Stays suspended, so no re-apply.
        $this->fakeDriver()->shouldReceive('applyServiceConfig')->never();

        $service = $this->service();
        $user = $this->user($service, [
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => EnforceAccessWindows::REASON,
            'access_start_minute' => 8 * 60,
            'access_end_minute' => 20 * 60,
            'expires_at' => Carbon::parse('2023-12-01 00:00:00', 'UTC'), // already past
        ]);

        (new EnforceAccessWindows)->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $user->status);
        $this->assertSame('expired', $user->suspended_reason);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
