<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Resources\Services\Pages\ManageServiceUsers;
use App\Models\ConfigLink;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConfigLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(VpnProtocol $protocol = VpnProtocol::WireGuard, bool $withConfig = true): ServiceUser
    {
        $service = Service::create([
            'name' => 'home',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.0.0.0/24',
            'listen_port' => 51820,
            'protocol' => $protocol,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [],
        ]);

        $user = ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ]);

        if ($withConfig) {
            $user->forceFill([
                'rendered_client_config' => json_encode([
                    'filename' => 'wg0-phone.conf',
                    'contents' => "[Interface]\nPrivateKey = SECRETKEY123\n",
                ]),
            ])->save();
        }

        return $user;
    }

    /**
     * @return array{0: ConfigLink, 1: string}
     */
    private function mint(ServiceUser $user, array $overrides = []): array
    {
        [$link, $token] = ConfigLink::mintFor(
            $user,
            $overrides['expiresAt'] ?? now()->addDay(),
            $overrides['maxDownloads'] ?? 1,
        );

        if (isset($overrides['after'])) {
            $overrides['after']($link);
        }

        return [$link, $token];
    }

    public function test_the_token_is_stored_only_as_a_hash(): void
    {
        [$link, $token] = $this->mint($this->makeUser());

        $this->assertNotSame($token, $link->token_hash);
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertDatabaseMissing('config_links', ['token_hash' => $token]);
    }

    public function test_the_landing_page_shows_but_does_not_consume(): void
    {
        [$link, $token] = $this->mint($this->makeUser());

        $this->get("/c/{$token}")
            ->assertOk()
            ->assertSee('Show my configuration')
            // The secret must not be on the landing page.
            ->assertDontSee('SECRETKEY123');

        $this->assertSame(0, $link->refresh()->downloads);
    }

    public function test_a_chat_preview_fetching_the_url_repeatedly_never_burns_it(): void
    {
        [$link, $token] = $this->mint($this->makeUser());

        // Several GETs, as a link-unfurling bot would do.
        $this->get("/c/{$token}")->assertOk();
        $this->get("/c/{$token}")->assertOk();
        $this->get("/c/{$token}")->assertOk();

        $this->assertSame(0, $link->refresh()->downloads);
    }

    public function test_revealing_consumes_a_use_and_shows_the_config(): void
    {
        [$link, $token] = $this->mint($this->makeUser());

        $response = $this->post("/c/{$token}");

        $response->assertOk()
            ->assertSee('wg0-phone.conf')
            ->assertSee('SECRETKEY123');

        $link->refresh();
        $this->assertSame(1, $link->downloads);
        $this->assertNotNull($link->first_used_at);
    }

    public function test_a_used_up_single_use_link_is_then_dead(): void
    {
        [, $token] = $this->mint($this->makeUser());

        $this->post("/c/{$token}")->assertOk();

        $this->get("/c/{$token}")
            ->assertStatus(410)
            ->assertSee('already been used');
        $this->post("/c/{$token}")->assertStatus(410);
    }

    public function test_a_multi_use_link_survives_until_its_uses_run_out(): void
    {
        [, $token] = $this->mint($this->makeUser(), ['maxDownloads' => 3]);

        $this->post("/c/{$token}")->assertOk();
        $this->post("/c/{$token}")->assertOk();
        $this->post("/c/{$token}")->assertOk();
        $this->post("/c/{$token}")->assertStatus(410);
    }

    public function test_an_expired_link_is_refused(): void
    {
        [, $token] = $this->mint($this->makeUser(), ['expiresAt' => now()->subMinute()]);

        $this->get("/c/{$token}")->assertStatus(410)->assertSee('expired');
    }

    public function test_a_cancelled_link_is_refused(): void
    {
        [, $token] = $this->mint($this->makeUser(), ['after' => fn (ConfigLink $l) => $l->forceFill(['revoked_at' => now()])->save()]);

        $this->get("/c/{$token}")->assertStatus(410)->assertSee('cancelled');
    }

    public function test_a_link_for_a_suspended_user_is_refused(): void
    {
        $user = $this->makeUser();
        [, $token] = $this->mint($user);
        $user->forceFill(['status' => ServiceUserStatus::Suspended])->save();

        $this->get("/c/{$token}")->assertStatus(410);
        $this->post("/c/{$token}")->assertStatus(410);
    }

    public function test_an_unknown_token_gives_nothing_away(): void
    {
        $this->get('/c/'.str_repeat('a', 48))
            ->assertStatus(410)
            ->assertSee('not valid');
    }

    public function test_revealing_before_the_config_is_rendered_does_not_consume(): void
    {
        [$link, $token] = $this->mint($this->makeUser(withConfig: false));

        $this->post("/c/{$token}")
            ->assertOk()
            ->assertSee('Almost ready');

        $this->assertSame(0, $link->refresh()->downloads);
    }

    public function test_a_wireguard_reveal_includes_a_qr_code(): void
    {
        [, $token] = $this->mint($this->makeUser(VpnProtocol::WireGuard));

        $this->post("/c/{$token}")
            ->assertOk()
            ->assertSee('Scan from QR code');
    }

    public function test_an_openvpn_reveal_has_no_qr_code(): void
    {
        [, $token] = $this->mint($this->makeUser(VpnProtocol::OpenVpn));

        $this->post("/c/{$token}")
            ->assertOk()
            ->assertDontSee('Scan from QR code');
    }

    public function test_the_reveal_response_is_not_cacheable(): void
    {
        [, $token] = $this->mint($this->makeUser());

        $response = $this->post("/c/{$token}")->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_admin_share_action_mints_a_link(): void
    {
        $this->actingAs(User::factory()->create());
        $user = $this->makeUser();

        Livewire::test(ManageServiceUsers::class, ['record' => $user->service->getRouteKey()])
            ->callAction(TestAction::make('share')->table($user), data: ['expiry' => '24', 'max_downloads' => '1'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseCount('config_links', 1);

        $link = ConfigLink::first();
        $this->assertSame($user->id, $link->service_user_id);
        $this->assertSame(1, $link->max_downloads);
        $this->assertNotNull($link->expires_at);
    }

    public function test_the_admin_cancel_links_action_revokes_active_links(): void
    {
        $this->actingAs(User::factory()->create());
        $user = $this->makeUser();
        [$link] = $this->mint($user);

        Livewire::test(ManageServiceUsers::class, ['record' => $user->service->getRouteKey()])
            ->callAction(TestAction::make('cancelLinks')->table($user))
            ->assertHasNoActionErrors();

        $this->assertNotNull($link->refresh()->revoked_at);
    }
}
