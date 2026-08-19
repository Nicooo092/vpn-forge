<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\ApplyLockdown;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\User;
use App\Services\Lockdown\LockdownManager;
use App\Services\Vpn\WireGuard\WireGuardDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The panel lockdown: a global switch that suspends every user at once and
 * restores only the ones it suspended. This pins the suspend/restore contract
 * and the reason isolation that keeps it from colliding with the other
 * enforcement jobs.
 */
class LockdownTest extends TestCase
{
    use RefreshDatabase;

    private int $ipSeed = 1;

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

    private function user(Service $service, array $attributes = []): ServiceUser
    {
        return ServiceUser::create(array_merge([
            'service_id' => $service->id,
            'name' => 'device-'.$this->ipSeed,
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.'.(++$this->ipSeed),
        ], $attributes));
    }

    private function fakeDriver(): Mockery\MockInterface
    {
        $driver = Mockery::mock(WireGuardDriver::class);
        $this->app->instance(WireGuardDriver::class, $driver);

        return $driver;
    }

    public function test_engaging_suspends_every_active_user_and_re_applies(): void
    {
        $this->fakeDriver()->shouldReceive('applyServiceConfig')->once();

        $service = $this->service();
        $a = $this->user($service);
        $b = $this->user($service);

        (new ApplyLockdown(true))->handle();

        foreach ([$a, $b] as $user) {
            $user->refresh();
            $this->assertSame(ServiceUserStatus::Suspended, $user->status);
            $this->assertSame(ApplyLockdown::REASON, $user->suspended_reason);
        }
    }

    public function test_a_hand_suspended_user_keeps_its_reason_through_engage_and_lift(): void
    {
        // Engage never touches an already-suspended user (only Active ones), and
        // lifting only restores lockdown's own reason -- a manual suspension
        // survives both untouched.
        $this->fakeDriver()->shouldReceive('applyServiceConfig');

        $service = $this->service();
        $manual = $this->user($service, [
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => 'suspended by hand',
        ]);

        (new ApplyLockdown(true))->handle();
        (new ApplyLockdown(false))->handle();

        $manual->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $manual->status);
        $this->assertSame('suspended by hand', $manual->suspended_reason);
    }

    public function test_lifting_restores_only_the_users_lockdown_suspended(): void
    {
        $this->fakeDriver()->shouldReceive('applyServiceConfig');

        $service = $this->service();
        $user = $this->user($service);

        (new ApplyLockdown(true))->handle();
        $user->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $user->status);

        (new ApplyLockdown(false))->handle();
        $user->refresh();
        $this->assertSame(ServiceUserStatus::Active, $user->status);
        $this->assertNull($user->suspended_reason);
    }

    public function test_lifting_hands_over_a_user_that_expired_during_lockdown(): void
    {
        // Stays suspended, so no re-apply is triggered for it.
        $this->fakeDriver()->shouldReceive('applyServiceConfig')->never();

        $service = $this->service();
        // Suspended by lockdown, but the expiry has since passed: it must stay
        // suspended with the reason handed over, not be let back in.
        $user = $this->user($service, [
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => ApplyLockdown::REASON,
            'expires_at' => now()->subDay(),
        ]);

        (new ApplyLockdown(false))->handle();

        $user->refresh();
        $this->assertSame(ServiceUserStatus::Suspended, $user->status);
        $this->assertSame('expired', $user->suspended_reason);
    }

    public function test_the_manager_records_and_clears_the_flag(): void
    {
        $admin = User::factory()->create();
        $manager = app(LockdownManager::class);

        $this->assertFalse($manager->isEngaged());

        $manager->engage($admin);
        $this->assertTrue($manager->isEngaged());
        $this->assertSame($admin->email, $manager->state()['engaged_by_email']);

        $manager->lift();
        $this->assertFalse($manager->isEngaged());
        $this->assertNull($manager->state());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
