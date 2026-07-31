<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\ConnectionLogsRelationManager;
use App\Filament\Resources\Services\RelationManagers\ServiceUsersRelationManager;
use App\Filament\Resources\Services\RelationManagers\TrafficLogsRelationManager;
use App\Jobs\Vpn\ApplyServiceConfig;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class RelationManagersTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->service = Service::create([
            'name' => 'Home tunnel',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.60.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => ['endpoint_host' => 'vpn.example.com'],
        ]);
    }

    private function trafficTab(): Testable
    {
        return Livewire::test(TrafficLogsRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ]);
    }

    private function addUser(string $name, ?bool $loggingOverride): ServiceUser
    {
        return ServiceUser::create([
            'service_id' => $this->service->id,
            'name' => $name,
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.60.0.'.rand(2, 250),
            'logging_override' => $loggingOverride,
        ]);
    }

    public function test_the_empty_traffic_tab_names_the_users_whose_logging_is_off(): void
    {
        $this->addUser('phone', loggingOverride: false);
        $this->addUser('laptop', loggingOverride: true);

        $this->trafficTab()
            ->assertOk()
            ->assertSee('phone')
            ->assertDontSee('laptop');
    }

    public function test_the_empty_traffic_tab_says_so_when_there_are_no_users_at_all(): void
    {
        $this->trafficTab()
            ->assertOk()
            ->assertSee('no active users');
    }

    public function test_the_empty_traffic_tab_explains_the_client_config_when_logging_is_on(): void
    {
        $this->addUser('phone', loggingOverride: null); // Inherits the service default, which is on.

        $this->trafficTab()
            ->assertOk()
            ->assertSee('resolver');
    }

    /**
     * The users table shipped with no edit action at all, so logging could be
     * chosen when the user was created and never changed again -- and it is
     * the switch that decides whether anything is captured for them.
     */
    public function test_logging_can_be_turned_on_for_an_existing_user(): void
    {
        Queue::fake();

        $user = $this->addUser('phone', loggingOverride: false);
        $this->assertFalse($user->loggingEffective());

        Livewire::test(ServiceUsersRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ])
            ->callAction(TestAction::make('edit')->table($user), data: [
                'name' => $user->name,
                'tunnel_ip' => $user->tunnel_ip,
                'logging_override' => '1',
            ])
            ->assertHasNoActionErrors();

        $user->refresh();

        $this->assertTrue($user->logging_override);
        $this->assertTrue($user->loggingEffective());

        // Logging alone must not disturb the running interface.
        Queue::assertNotPushed(ApplyServiceConfig::class);
    }

    public function test_moving_a_user_to_another_address_re_applies_the_service(): void
    {
        Queue::fake();

        $user = $this->addUser('phone', loggingOverride: null);

        Livewire::test(ServiceUsersRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ])
            ->callAction(TestAction::make('edit')->table($user), data: [
                'name' => $user->name,
                'tunnel_ip' => '10.60.0.251',
                'logging_override' => '',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('10.60.0.251', $user->refresh()->tunnel_ip);
        Queue::assertPushed(ApplyServiceConfig::class);
    }

    public function test_the_other_relation_managers_render(): void
    {
        $this->addUser('phone', loggingOverride: null);

        foreach ([ServiceUsersRelationManager::class, ConnectionLogsRelationManager::class] as $manager) {
            Livewire::test($manager, [
                'ownerRecord' => $this->service,
                'pageClass' => EditService::class,
            ])->assertOk();
        }
    }
}
