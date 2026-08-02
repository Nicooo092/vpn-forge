<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Widgets\GettingStarted;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GettingStartedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function service(bool $endpointSet): Service
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
            'config' => $endpointSet ? ['endpoint_host' => 'vpn.example.com'] : ['endpoint_host' => 'CHANGE-ME.example.com'],
        ]);
    }

    private function user(Service $service): ServiceUser
    {
        return ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ]);
    }

    public function test_the_checklist_shows_on_a_fresh_install(): void
    {
        $this->assertTrue(GettingStarted::canView());
    }

    public function test_it_still_shows_while_the_endpoint_is_the_placeholder(): void
    {
        $service = $this->service(endpointSet: false);
        $this->user($service);

        $this->assertTrue(GettingStarted::canView());
    }

    public function test_it_hides_once_the_essentials_are_done(): void
    {
        $service = $this->service(endpointSet: true);
        $this->user($service);

        $this->assertFalse(GettingStarted::canView());
    }

    public function test_the_steps_reflect_progress(): void
    {
        $service = $this->service(endpointSet: true);
        $steps = (new GettingStarted)->getSteps();

        // Service created + endpoint set, but no user yet.
        $this->assertTrue($steps[0]['done']);  // service
        $this->assertTrue($steps[1]['done']);  // endpoint
        $this->assertFalse($steps[2]['done']); // user
    }

    public function test_the_widget_renders_with_native_translatable_labels(): void
    {
        Livewire::test(GettingStarted::class)
            ->assertOk()
            ->assertSee('Getting started')
            ->assertSee('Create service')
            ->assertSee('Set up 2FA');
    }
}
