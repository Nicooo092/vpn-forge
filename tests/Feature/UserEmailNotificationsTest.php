<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\UserMailType;
use App\Enums\VpnProtocol;
use App\Jobs\Notifications\SendUserMail;
use App\Jobs\Vpn\EnforceUserLimits;
use App\Mail\AccessExpiringMail;
use App\Models\Service;
use App\Models\ServiceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * End-user account emails only go out when the user actually has an address AND
 * a real mailer is configured, and they are de-duplicated so a repeated
 * enforcement pass cannot re-send. This pins that gating so a later change
 * cannot start silently emailing users -- or spamming them.
 */
class UserEmailNotificationsTest extends TestCase
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

    private function expiringUser(Service $service, ?string $email): ServiceUser
    {
        return ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'email' => $email,
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
            'expires_at' => now()->addDay(), // inside the default 3-day warn window
        ]);
    }

    public function test_an_approaching_expiry_emails_a_user_with_an_address(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $user = $this->expiringUser($this->service(), 'user@example.com');

        (new EnforceUserLimits)->handle();
        (new EnforceUserLimits)->handle(); // deduped -- must not re-send

        Mail::assertSent(AccessExpiringMail::class, 1);
        Mail::assertSent(
            AccessExpiringMail::class,
            fn (AccessExpiringMail $mail) => $mail->hasTo('user@example.com') && $mail->user->is($user),
        );
    }

    public function test_a_user_without_an_address_is_not_emailed(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->expiringUser($this->service(), null);

        (new EnforceUserLimits)->handle();

        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_when_no_real_mailer_is_configured(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();

        $this->expiringUser($this->service(), 'user@example.com');

        (new EnforceUserLimits)->handle();

        Mail::assertNothingSent();
    }

    public function test_the_channel_can_be_disabled(): void
    {
        config(['mail.default' => 'smtp', 'vpnforge.user_mail.enabled' => false]);
        Mail::fake();

        $this->expiringUser($this->service(), 'user@example.com');

        (new EnforceUserLimits)->handle();

        Mail::assertNothingSent();
    }

    public function test_the_same_event_window_is_only_sent_once(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $user = $this->expiringUser($this->service(), 'user@example.com');
        $key = 'user-mail:expiry:'.$user->id.':fixed-window';

        SendUserMail::dispatchSync($user, UserMailType::AccessExpiring, $key);
        SendUserMail::dispatchSync($user, UserMailType::AccessExpiring, $key);

        Mail::assertSent(AccessExpiringMail::class, 1);
    }
}
