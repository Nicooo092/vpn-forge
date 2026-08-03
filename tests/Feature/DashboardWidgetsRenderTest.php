<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Widgets\BandwidthChart;
use App\Models\BandwidthSample;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every widget the Dashboard discovers has to actually render. Widgets are lazy
 * by default, so a broken one does not fail the page request -- it fails the
 * follow-up /livewire/update call and the operator gets "an error occurred while
 * loading this page" with nothing useful on screen. A widget query that threw
 * once shipped to production this way.
 *
 * The loop is over the panel's own widget list rather than a hardcoded array, so
 * a widget added later is covered without anyone remembering to add it here.
 */
class DashboardWidgetsRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        // Widgets that aggregate over a window need rows on both sides of the
        // boundary, otherwise an empty table hides a broken query behind a
        // trivially-empty result.
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

        $user = ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ]);

        foreach ([5, 60, 26 * 60, 9 * 24 * 60] as $minutesAgo) {
            BandwidthSample::create([
                'service_id' => $service->id,
                'service_user_id' => null,
                'sampled_at' => now()->subMinutes($minutesAgo),
                'bytes_in_delta' => 1024 * $minutesAgo,
                'bytes_out_delta' => 512 * $minutesAgo,
            ]);

            BandwidthSample::create([
                'service_id' => $service->id,
                'service_user_id' => $user->id,
                'sampled_at' => now()->subMinutes($minutesAgo),
                'bytes_in_delta' => 64,
                'bytes_out_delta' => 32,
            ]);
        }
    }

    public function test_every_discovered_dashboard_widget_renders(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertNotEmpty($widgets, 'The admin panel discovered no widgets at all.');

        foreach ($widgets as $widget) {
            Livewire::test($widget)
                ->assertOk();
        }
    }

    /**
     * The bandwidth chart buckets in SQL, and its window filters are the part
     * most likely to break on a driver difference, so each one is exercised.
     */
    public function test_the_bandwidth_chart_renders_for_every_window(): void
    {
        foreach (['24h', '7d', '30d'] as $filter) {
            $widget = new BandwidthChart;
            $widget->filter = $filter;

            // getData() is protected on ChartWidget; it is the method that runs
            // the bucketing query, so it is the one worth exercising directly.
            $method = new ReflectionMethod($widget, 'getData');
            $method->setAccessible(true);
            $data = $method->invoke($widget);

            $this->assertArrayHasKey('datasets', $data);
            $this->assertArrayHasKey('labels', $data);
            $this->assertNotEmpty($data['datasets'], "No datasets for the {$filter} window.");

            // The window must produce as many points as labels, or the series
            // is shifted against its axis.
            foreach ($data['datasets'] as $dataset) {
                $this->assertCount(
                    count($data['labels']),
                    $dataset['data'],
                    "Series length does not match the label count for the {$filter} window."
                );
            }
        }
    }
}
