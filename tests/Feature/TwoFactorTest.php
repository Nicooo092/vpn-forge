<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Tests\TestCase;

/**
 * Two-factor authentication is provided by the filament-two-factor plugin
 * (authenticator-app TOTP + WebAuthn passkeys, both on top of encrypted
 * columns). These pin the wiring and the at-rest secrecy; the browser-side
 * WebAuthn ceremony itself is exercised manually.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_user_is_a_passkey_and_two_factor_subject(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(HasPasskeys::class, $user);
        $this->assertInstanceOf(HasMany::class, $user->passkeys());
        $this->assertTrue(method_exists($user, 'hasEnabledTwoFactorAuthentication'));
        $this->assertTrue(method_exists($user, 'hasEnabledPasskeyAuthentication'));
    }

    public function test_the_backing_columns_and_table_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_secret'));
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_recovery_codes'));
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_confirmed_at'));
        $this->assertTrue(Schema::hasTable('passkeys'));
    }

    public function test_a_fresh_user_has_neither_factor_enabled(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
        $this->assertFalse((bool) $user->hasEnabledPasskeyAuthentication());
    }

    /**
     * The TOTP secret is encrypted at rest (the trait encrypt()s it and
     * decrypt()s to verify a code), so a database dump never exposes a working
     * second factor -- only the APP_KEY the backup archive carries can read it.
     */
    public function test_the_two_factor_secret_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', (string) $raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', decrypt($raw));
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    /**
     * The relying-party id a passkey is bound to must be the panel's own host
     * (derived from APP_URL), or credentials register against the wrong origin.
     */
    public function test_the_relying_party_id_is_the_app_host(): void
    {
        $expected = parse_url(config('app.url'), PHP_URL_HOST);

        $this->assertSame($expected, config('passkeys.relying_party.id'));
    }

    public function test_the_profile_page_is_reachable(): void
    {
        // require_mfa is off under test (phpunit.xml), so an un-enrolled user is
        // not force-redirected to 2FA setup.
        $this->actingAs(User::factory()->create());

        $this->get(Filament::getPanel('admin')->getProfileUrl())->assertOk();
    }
}
