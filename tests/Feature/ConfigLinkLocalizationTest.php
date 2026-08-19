<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\ConfigLink;
use App\Models\Service;
use App\Models\ServiceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public config-link portal is the only page a non-operator recipient sees.
 * These pin that it renders in the panel's configured locale rather than always
 * in English: the document language, the body copy, and the dead-link reasons.
 */
class ConfigLinkLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function mintFor(VpnProtocol $protocol = VpnProtocol::WireGuard): string
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

        $user->forceFill([
            'rendered_client_config' => json_encode([
                'filename' => 'wg0-phone.conf',
                'contents' => "[Interface]\nPrivateKey = SECRETKEY123\n",
            ]),
        ])->save();

        [, $token] = ConfigLink::mintFor($user, now()->addDay(), 1);

        return $token;
    }

    public function test_the_landing_page_renders_in_the_configured_locale(): void
    {
        app()->setLocale('fr');
        $token = $this->mintFor();

        $this->get("/c/{$token}")
            ->assertOk()
            ->assertSee('lang="fr"', false)
            ->assertSee('Afficher ma configuration');
    }

    public function test_the_openvpn_reveal_shows_localised_install_guidance_naming_the_app(): void
    {
        app()->setLocale('fr');
        $token = $this->mintFor(VpnProtocol::OpenVpn);

        $this->post("/c/{$token}")
            ->assertOk()
            ->assertSee('Enregistrez votre configuration')
            // The client app is named for the recipient, and there is no QR branch.
            ->assertSee('OpenVPN Connect')
            ->assertDontSee('Scan from QR code');
    }

    public function test_a_dead_link_reason_is_localised(): void
    {
        app()->setLocale('fr');

        // Unknown token -> 'This link is not valid.' localised to French. Both the
        // rendered output and the assertion needle escape the apostrophe, so they match.
        $this->get('/c/'.str_repeat('a', 48))
            ->assertStatus(410)
            ->assertSee("n'est pas valide");
    }
}
