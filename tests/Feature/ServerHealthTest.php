<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Pages\ServerHealth;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\User;
use App\Services\System\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServerHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_snapshot_has_the_expected_shape_and_counts(): void
    {
        $service = Service::create([
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
        ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ]);

        $snapshot = app(SystemHealth::class)->snapshot();

        $this->assertArrayHasKey('cpu', $snapshot);
        $this->assertArrayHasKey('memory', $snapshot);
        $this->assertArrayHasKey('disk', $snapshot);
        $this->assertArrayHasKey('uptime_seconds', $snapshot);

        // Counts come from the database, so they are deterministic.
        $this->assertSame(1, $snapshot['counts']['services_active']);
        $this->assertSame(1, $snapshot['counts']['users_active']);
        $this->assertSame(0, $snapshot['counts']['connected_now']);

        // Disk is readable on any platform; percent is a sane 0-100 or null.
        $percent = $snapshot['disk']['percent'];
        $this->assertTrue($percent === null || ($percent >= 0 && $percent <= 100));

        $this->assertIsInt($snapshot['bandwidth_24h']);
    }

    public function test_the_page_renders(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ServerHealth::class)
            ->assertOk()
            ->assertSee('CPU load')
            ->assertSee('Disk');
    }
}
