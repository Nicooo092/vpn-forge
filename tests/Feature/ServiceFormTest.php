<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The service form hides most of its fields in simple mode and relies on
 * dehydratedWhenHidden() to still persist their computed defaults. That is
 * easy to get subtly wrong -- a hidden field that is not dehydrated writes
 * NULL into a NOT NULL column -- so it is worth asserting on the saved row
 * rather than on the rendered markup.
 */
class ServiceFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The create page dispatches ProvisionService, and the test queue runs
        // synchronously -- without this the job would try to write to
        // /etc/wireguard for real.
        Queue::fake();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_create_page_renders(): void
    {
        Livewire::test(CreateService::class)
            ->assertOk()
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('protocol')
            ->assertFormFieldExists('config.endpoint_host');
    }

    public function test_simple_mode_still_saves_the_fields_it_hides(): void
    {
        Livewire::test(CreateService::class)
            ->fillForm([
                'form_mode' => 'simple',
                'name' => 'Simple tunnel',
                'protocol' => VpnProtocol::WireGuard->value,
                'config' => ['endpoint_host' => 'vpn.example.com'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::firstOrFail();

        $this->assertSame('Simple tunnel', $service->name);
        $this->assertSame(VpnProtocol::WireGuard, $service->protocol);
        $this->assertSame('vpn.example.com', $service->config['endpoint_host']);

        // None of these were on screen, but all are NOT NULL columns or
        // parameters the driver reads with no safe fallback.
        $this->assertSame('wg0', $service->interface_name);
        $this->assertSame('10.8.0.0/24', $service->subnet_cidr);
        $this->assertSame(51820, (int) $service->listen_port);
        $this->assertNotNull($service->transport);
        $this->assertTrue($service->logging_enabled_default);

        // Detected off the default route rather than left to the driver's
        // 'eth0' guess, which sends the MASQUERADE rule to the wrong place on
        // any host that does not happen to use that name.
        $this->assertNotEmpty($service->config['egress_interface']);
    }

    public function test_suggested_network_details_avoid_existing_services(): void
    {
        Service::create([
            'name' => 'First tunnel',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.8.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => ['endpoint_host' => 'vpn.example.com'],
        ]);

        Livewire::test(CreateService::class)
            ->fillForm([
                'form_mode' => 'simple',
                'name' => 'Second tunnel',
                'protocol' => VpnProtocol::WireGuard->value,
                'config' => ['endpoint_host' => 'vpn.example.com'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::where('name', 'Second tunnel')->firstOrFail();

        $this->assertSame('wg1', $service->interface_name);
        $this->assertSame('10.9.0.0/24', $service->subnet_cidr);
        $this->assertSame(51821, (int) $service->listen_port);
    }

    /**
     * Editing in simple mode leaves most of the record's fields off screen.
     * They must survive the save untouched -- silently resetting a tuned MTU
     * or AllowedIPs to the form default would break live clients.
     */
    public function test_editing_in_simple_mode_preserves_hidden_settings(): void
    {
        $service = Service::create([
            'name' => 'Tuned tunnel',
            'interface_name' => 'wg3',
            'subnet_cidr' => '10.44.0.0/24',
            'listen_port' => 51999,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [
                'endpoint_host' => 'vpn.example.com',
                'egress_interface' => 'ens5',
                'mtu' => 1280,
                'keepalive' => 15,
                'dns' => ['10.44.0.1'],
                'client_allowed_ips' => '10.44.0.0/24',
                'server_public_key' => 'kept-as-is',
            ],
        ]);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertOk()
            ->fillForm(['name' => 'Renamed tunnel'])
            ->call('save')
            ->assertHasNoFormErrors();

        $service->refresh();

        $this->assertSame('Renamed tunnel', $service->name);
        $this->assertSame('wg3', $service->interface_name);
        $this->assertSame('10.44.0.0/24', $service->subnet_cidr);
        $this->assertSame(51999, (int) $service->listen_port);
        $this->assertSame(1280, (int) $service->config['mtu']);
        $this->assertSame(15, (int) $service->config['keepalive']);
        $this->assertSame(['10.44.0.1'], $service->config['dns']);
        $this->assertSame('10.44.0.0/24', $service->config['client_allowed_ips']);
        $this->assertSame('ens5', $service->config['egress_interface']);

        // Generated at provisioning time and never shown in the form at all --
        // losing it would orphan every existing client config.
        $this->assertSame('kept-as-is', $service->config['server_public_key']);
    }

    public function test_advanced_mode_exposes_the_protocol_specific_fields(): void
    {
        Livewire::test(CreateService::class)
            ->fillForm([
                'form_mode' => 'advanced',
                'protocol' => VpnProtocol::OpenVpn->value,
            ])
            ->assertFormFieldExists('config.data_ciphers')
            ->assertFormFieldExists('config.auth_digest')
            ->assertFormFieldExists('config.push_dns')
            ->assertFormFieldExists('config.dns_upstreams');
    }
}
