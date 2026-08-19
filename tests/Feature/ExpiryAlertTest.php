<?php

namespace Tests\Feature;

use App\Enums\NotificationEvent;
use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\EnforceUserLimits;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * The expiry twin of the quota warning: an operator alert when a user is about
 * to expire, fired once per expiry and re-armed when the date moves.
 */
class ExpiryAlertTest extends TestCase
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

    private function user(Service $service, array $attributes = []): ServiceUser
    {
        return ServiceUser::create(array_merge([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ], $attributes));
    }

    public function test_a_user_expiring_within_the_window_is_warned_once(): void
    {
        config(['vpnforge.expiry.warn_days' => 3]);
        $spy = $this->spyNotifier();
        $service = $this->service();
        $this->user($service, ['expires_at' => now()->addDays(2)]);

        (new EnforceUserLimits)->handle();
        (new EnforceUserLimits)->handle(); // deduped by cache

        $spy->shouldHaveReceived('event')
            ->with(NotificationEvent::ExpiryApproaching, Mockery::type('string'), Mockery::type('string'))
            ->once();
    }

    public function test_a_user_expiring_beyond_the_window_is_not_warned(): void
    {
        config(['vpnforge.expiry.warn_days' => 3]);
        $spy = $this->spyNotifier();
        $service = $this->service();
        $this->user($service, ['expires_at' => now()->addDays(10)]);

        (new EnforceUserLimits)->handle();

        $spy->shouldNotHaveReceived('event');
    }

    public function test_an_already_expired_user_is_suspended_not_warned(): void
    {
        config(['vpnforge.expiry.warn_days' => 3]);
        $spy = $this->spyNotifier();
        // Enforcement re-applies the config on suspension; keep the driver off /etc.
        $driver = Mockery::mock(\App\Services\Vpn\WireGuard\WireGuardDriver::class);
        $driver->shouldReceive('applyServiceConfig')->andReturnNull();
        $this->app->instance(\App\Services\Vpn\WireGuard\WireGuardDriver::class, $driver);
        $service = $this->service();
        $this->user($service, ['expires_at' => now()->subMinute()]);

        (new EnforceUserLimits)->handle();

        // Suspension fires, the expiry heads-up does not.
        $spy->shouldHaveReceived('event')
            ->with(NotificationEvent::UserSuspended, Mockery::type('string'), Mockery::type('string'))
            ->once();
        $spy->shouldNotHaveReceived('event')
            ->with(NotificationEvent::ExpiryApproaching, Mockery::type('string'), Mockery::type('string'));
    }

    public function test_a_new_expiry_date_re_arms_the_warning(): void
    {
        config(['vpnforge.expiry.warn_days' => 3]);
        $spy = $this->spyNotifier();
        $service = $this->service();
        $user = $this->user($service, ['expires_at' => now()->addDays(2)]);

        (new EnforceUserLimits)->handle();

        // Moving the date is a fresh expiry: the cache key changes and re-arms.
        $user->forceFill(['expires_at' => now()->addDays(1)])->save();
        (new EnforceUserLimits)->handle();

        $spy->shouldHaveReceived('event')
            ->with(NotificationEvent::ExpiryApproaching, Mockery::type('string'), Mockery::type('string'))
            ->twice();
    }

    public function test_a_zero_window_disables_expiry_warnings(): void
    {
        config(['vpnforge.expiry.warn_days' => 0]);
        $spy = $this->spyNotifier();
        $service = $this->service();
        $this->user($service, ['expires_at' => now()->addDay()]);

        (new EnforceUserLimits)->handle();

        $spy->shouldNotHaveReceived('event');
    }
}
