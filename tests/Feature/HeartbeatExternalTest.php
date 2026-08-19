<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Services\System\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HeartbeatExternalTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_nothing_when_no_url_is_configured(): void
    {
        config(['vpnforge.heartbeat.url' => null]);
        Http::fake();

        $this->artisan('vpnforge:heartbeat')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_pings_the_configured_url_with_a_compact_status_query(): void
    {
        config([
            'vpnforge.heartbeat.url' => 'https://hc-ping.com/abc',
            'vpnforge.heartbeat.include_status' => true,
        ]);

        Service::create([
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

        Http::fake(['hc-ping.com/*' => Http::response('OK', 200)]);

        $this->artisan('vpnforge:heartbeat')->assertSuccessful();

        Http::assertSent(function ($request) {
            // 1 active of 1 total, and no worker heartbeat has been recorded.
            return str_starts_with($request->url(), 'https://hc-ping.com/abc?')
                && str_contains($request->url(), 'services=1%2F1')
                && str_contains($request->url(), 'worker=down');
        });
    }

    public function test_a_failing_ping_still_succeeds_so_the_scheduler_run_is_not_marked_failed(): void
    {
        config(['vpnforge.heartbeat.url' => 'https://hc-ping.com/abc']);
        Http::fake(['hc-ping.com/*' => Http::response('nope', 500)]);

        $this->artisan('vpnforge:heartbeat')->assertSuccessful();
    }

    public function test_the_snapshot_reports_whether_the_heartbeat_is_configured(): void
    {
        config(['vpnforge.heartbeat.url' => 'https://hc-ping.com/abc']);
        $this->assertTrue(app(SystemHealth::class)->snapshot()['heartbeat_external']['configured']);

        config(['vpnforge.heartbeat.url' => null]);
        $this->assertFalse(app(SystemHealth::class)->snapshot()['heartbeat_external']['configured']);
    }
}
