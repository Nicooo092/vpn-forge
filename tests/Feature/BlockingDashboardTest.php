<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\TrafficLogKind;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Pages\BlockingDashboard;
use App\Models\Service;
use App\Models\TrafficLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The blocking dashboard counts a DNS row as blocked purely from the
 * traffic_logs.blocked column (set by the capture agent when dnsmasq answered
 * the name itself). These pin that the window and service filters and the
 * blocked-vs-allowed split are computed from exactly that flag.
 */
class BlockingDashboardTest extends TestCase
{
    use RefreshDatabase;

    private int $created = 0;

    private function service(string $name = 'office'): Service
    {
        $n = $this->created++;

        return Service::create([
            'name' => $name,
            'interface_name' => "wg{$n}",
            'subnet_cidr' => '10.0.0.0/24',
            'listen_port' => 51820 + $n,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [],
        ]);
    }

    private function dns(Service $service, bool $blocked, string $host, ?\DateTimeInterface $at = null): void
    {
        TrafficLog::create([
            'service_id' => $service->id,
            'kind' => TrafficLogKind::Dns,
            'occurred_at' => $at ?? now(),
            'source_ip' => '10.0.0.2',
            'host' => $host,
            'blocked' => $blocked,
            'detail' => ['query_type' => 'A', 'cached' => false],
        ]);
    }

    private function page(string $window = '24h', ?int $serviceId = null): BlockingDashboard
    {
        // Each scenario is deterministic; drop any cached rollup between them.
        Cache::flush();

        $page = new BlockingDashboard;
        $page->window = $window;
        $page->serviceId = $serviceId;

        return $page;
    }

    public function test_it_splits_blocked_from_allowed_over_the_window(): void
    {
        $service = $this->service();

        $this->dns($service, true, 'ads.example.com');
        $this->dns($service, true, 'track.example.net');
        $this->dns($service, false, 'example.com');
        $this->dns($service, false, 'wikipedia.org');
        $this->dns($service, false, 'anthropic.com');

        // Outside the 24h window -- counted in 7d only.
        $this->dns($service, true, 'old.example.com', now()->subDays(3));

        $day = $this->page('24h')->stats();
        $this->assertSame(5, $day['total']);
        $this->assertSame(2, $day['blocked']);
        $this->assertSame(3, $day['allowed']);
        $this->assertSame(40.0, $day['rate']);

        $week = $this->page('7d')->stats();
        $this->assertSame(6, $week['total']);
        $this->assertSame(3, $week['blocked']);
    }

    public function test_the_service_filter_scopes_the_counts(): void
    {
        $a = $this->service('a');
        $b = $this->service('b');

        $this->dns($a, true, 'ads.example.com');
        $this->dns($a, false, 'example.com');
        $this->dns($b, true, 'ads.example.net');

        $onlyA = $this->page('24h', $a->id)->stats();
        $this->assertSame(2, $onlyA['total']);
        $this->assertSame(1, $onlyA['blocked']);

        $overall = $this->page('24h')->stats();
        $this->assertSame(3, $overall['total']);
        $this->assertSame(2, $overall['blocked']);
    }

    public function test_top_blocked_ranks_only_blocked_domains(): void
    {
        $service = $this->service();

        foreach (range(1, 3) as $ignored) {
            $this->dns($service, true, 'ads.example.com');
        }
        $this->dns($service, true, 'track.example.net');

        // A busy allowed domain must never surface in the blocked ranking.
        foreach (range(1, 9) as $ignored) {
            $this->dns($service, false, 'example.com');
        }

        $top = $this->page('24h')->topBlocked();

        $this->assertCount(2, $top);
        $this->assertSame('ads.example.com', $top->first()->host);
        $this->assertSame(3, (int) $top->first()->hits);
        $this->assertFalse($top->contains(fn ($row) => $row->host === 'example.com'));
    }
}
