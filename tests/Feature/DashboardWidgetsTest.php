<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\TrafficLogKind;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Widgets\BandwidthChart;
use App\Filament\Widgets\RecentConnections;
use App\Filament\Widgets\ServicesOverview;
use App\Filament\Widgets\ServiceStatsOverview;
use App\Filament\Widgets\TopDomains;
use App\Models\ConnectionLog;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\TrafficLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private ServiceUser $user;

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

        $this->user = ServiceUser::create([
            'service_id' => $this->service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.60.0.2',
            'last_handshake_at' => now()->subMinute(),
        ]);
    }

    public static function widgetProvider(): array
    {
        return [
            'stats' => [ServiceStatsOverview::class],
            'services' => [ServicesOverview::class],
            'bandwidth' => [BandwidthChart::class],
            'connections' => [RecentConnections::class],
            'domains' => [TopDomains::class],
        ];
    }

    #[DataProvider('widgetProvider')]
    public function test_the_widget_renders_with_data(string $widget): void
    {
        ConnectionLog::create([
            'service_user_id' => $this->user->id,
            'connected_at' => now()->subHours(2),
            'peer_ip' => '203.0.113.7',
            'bytes_in' => 1024,
            'bytes_out' => 2048,
        ]);

        foreach (['example.com', 'example.com', 'anthropic.com'] as $host) {
            TrafficLog::create([
                'service_id' => $this->service->id,
                'service_user_id' => $this->user->id,
                'kind' => TrafficLogKind::Dns,
                'occurred_at' => now()->subMinutes(5),
                'source_ip' => '10.60.0.2',
                'host' => $host,
                'detail' => ['query_type' => 'A', 'answer' => '93.184.216.34'],
            ]);
        }

        Livewire::test($widget)->assertOk();
    }

    #[DataProvider('widgetProvider')]
    public function test_the_widget_renders_with_nothing_at_all(string $widget): void
    {
        ServiceUser::query()->delete();
        Service::query()->delete();

        Livewire::test($widget)->assertOk();
    }

    public function test_top_domains_counts_and_orders_by_query_volume(): void
    {
        foreach (['a.example', 'a.example', 'a.example', 'b.example'] as $host) {
            TrafficLog::create([
                'service_id' => $this->service->id,
                'service_user_id' => $this->user->id,
                'kind' => TrafficLogKind::Dns,
                'occurred_at' => now()->subMinutes(5),
                'source_ip' => '10.60.0.2',
                'host' => $host,
                'detail' => ['query_type' => 'A'],
            ]);
        }

        $rows = Livewire::test(TopDomains::class)->instance()->getTable()->getRecords();

        $this->assertSame('a.example', $rows->first()->host);
        $this->assertSame(3, (int) $rows->first()->hits);
        $this->assertCount(2, $rows);
    }
}
