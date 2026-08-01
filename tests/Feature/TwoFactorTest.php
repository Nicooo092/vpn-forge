<?php

namespace Tests\Feature;

use App\Filament\Auth\SvgAppAuthentication;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_offers_app_authentication(): void
    {
        $providers = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders();

        $this->assertNotEmpty($providers, 'the panel should have a multi-factor provider');

        $app = collect($providers)->first(fn ($provider) => $provider instanceof AppAuthentication);

        $this->assertNotNull($app, 'app-based authentication should be one of them');
    }

    public function test_a_user_can_hold_a_secret_and_recovery_codes(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(HasAppAuthentication::class, $user);
        $this->assertInstanceOf(HasAppAuthenticationRecovery::class, $user);

        $this->assertNull($user->getAppAuthenticationSecret());
        $this->assertNull($user->getAppAuthenticationRecoveryCodes());

        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['code-one', 'code-two']);

        $fresh = $user->fresh();
        $this->assertSame('JBSWY3DPEHPK3PXP', $fresh->getAppAuthenticationSecret());
        $this->assertSame(['code-one', 'code-two'], $fresh->getAppAuthenticationRecoveryCodes());
    }

    /**
     * The secret is not hashed -- verifying a code needs the original back --
     * so encryption at rest is the only thing standing between a database
     * dump and a working second factor.
     */
    public function test_neither_the_secret_nor_the_codes_are_stored_in_the_clear(): void
    {
        $user = User::factory()->create();
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['recovery-code-value']);

        $raw = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw->app_authentication_secret);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $raw->app_authentication_secret);
        $this->assertStringNotContainsString('recovery-code-value', $raw->app_authentication_recovery_codes);
    }

    public function test_the_secret_never_leaves_through_a_serialised_user(): void
    {
        $user = User::factory()->create();
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['recovery-code-value']);

        $serialised = $user->fresh()->toJson();

        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $serialised);
        $this->assertStringNotContainsString('recovery-code-value', $serialised);
        $this->assertStringNotContainsString('app_authentication_secret', $serialised);
    }

    public function test_the_authenticator_app_is_told_which_account_this_is(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.com']);

        $this->assertSame('operator@example.com', $user->getAppAuthenticationHolderName());
    }

    /**
     * Filament's own implementation double-encodes this on any server without
     * the imagick extension: google2fa-qrcode already returns a finished data
     * URI, and Filament's fallback base64-encodes it again. The browser then
     * decodes the image back to the literal text "data:image/svg+xml;base64,"
     * and shows a broken image, with no error anywhere to explain it.
     */
    public function test_the_qr_code_decodes_to_an_image_rather_than_another_data_uri(): void
    {
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('admin');

        $uri = SvgAppAuthentication::make()->generateQrCodeDataUri('JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);

        $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));

        $this->assertStringNotContainsString('data:image', $decoded, 'the payload was encoded twice');
        $this->assertStringContainsString('<svg', $decoded);
        $this->assertStringContainsString('<path', $decoded);
    }

    public function test_the_profile_page_is_reachable(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(Filament::getPanel('admin')->getProfileUrl())->assertOk();
    }
}
