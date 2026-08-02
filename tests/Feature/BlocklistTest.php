<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Blocklist\RefreshBlocklists;
use App\Models\Blocklist;
use App\Models\Service;
use App\Services\Blocklist\BlocklistCompiler;
use App\Services\Blocklist\BlocklistParser;
use App\Services\Vpn\DnsmasqManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class BlocklistTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $config = []): Service
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
            'config' => $config,
        ]);
    }

    // --- Parser ---------------------------------------------------------

    public function test_it_parses_a_hosts_file(): void
    {
        $content = "# a comment\n0.0.0.0 ads.example.com\n127.0.0.1 track.example.net  # inline\n";

        $this->assertSame(['ads.example.com', 'track.example.net'], BlocklistParser::parse($content));
    }

    public function test_it_parses_a_plain_domain_list_and_adblock_rules(): void
    {
        $content = "plain.example.com\n||advert.example.org^\n! adblock comment\n";

        $domains = BlocklistParser::parse($content);

        $this->assertContains('plain.example.com', $domains);
        $this->assertContains('advert.example.org', $domains);
    }

    public function test_it_drops_junk_ips_localhost_and_duplicates(): void
    {
        $content = "0.0.0.0 0.0.0.0\n127.0.0.1 localhost\n0.0.0.0 dup.example.com\n0.0.0.0 dup.example.com\n1.2.3.4\n";

        $this->assertSame(['dup.example.com'], BlocklistParser::parse($content));
    }

    public function test_it_refuses_injection_and_cosmetic_rules(): void
    {
        // A newline-injection attempt and adblock cosmetic/exception rules.
        $content = "evil\naddress=/mybank.com/6.6.6.6\nexample.com##.ad\n@@||allowed.example.com^\ngood.example.com\n";

        $domains = BlocklistParser::parse($content);

        $this->assertSame(['good.example.com'], $domains);
        $this->assertStringNotContainsString('mybank', implode(' ', $domains));
    }

    // --- Resolver integration ------------------------------------------

    public function test_the_resolver_pulls_in_the_blocklist_file_by_default(): void
    {
        $config = app(DnsmasqManager::class)->buildConfig($this->service());

        $this->assertStringContainsString('addn-hosts='.BlocklistCompiler::FILE, $config);
    }

    public function test_a_service_can_opt_out_of_blocklists(): void
    {
        $config = app(DnsmasqManager::class)->buildConfig($this->service(['use_blocklists' => false]));

        $this->assertStringNotContainsString('addn-hosts=', $config);
    }

    // --- Refresh job ----------------------------------------------------

    public function test_the_refresh_job_fetches_enabled_lists_and_recompiles(): void
    {
        Http::fake([
            'https://good.test/*' => Http::response("0.0.0.0 ads.example.com\n0.0.0.0 track.example.com", 200),
            'https://bad.test/*' => Http::response('nope', 500),
        ]);

        $good = Blocklist::create(['name' => 'good', 'url' => 'https://good.test/hosts', 'enabled' => true]);
        $bad = Blocklist::create(['name' => 'bad', 'url' => 'https://bad.test/hosts', 'enabled' => true]);
        $off = Blocklist::create(['name' => 'off', 'url' => 'https://good.test/hosts', 'enabled' => false]);

        $this->service();

        $compiler = Mockery::mock(BlocklistCompiler::class);
        $compiler->shouldReceive('write')
            ->once()
            ->with(Mockery::on(fn (array $domains) => in_array('ads.example.com', $domains, true)
                && in_array('track.example.com', $domains, true)));

        // The active opted-in service is re-provisioned so its resolver serves
        // the fresh file.
        $dnsmasq = Mockery::mock(DnsmasqManager::class);
        $dnsmasq->shouldReceive('provision')
            ->once()
            ->with(Mockery::on(fn (Service $service) => $service->interface_name === 'wg0'));

        (new RefreshBlocklists)->handle($compiler, $dnsmasq);

        // The good list is counted; the failing one records why; the disabled
        // one is left untouched.
        $this->assertSame(2, $good->refresh()->domain_count);
        $this->assertNotNull($good->last_fetched_at);
        $this->assertNull($good->last_error);

        $this->assertNotNull($bad->refresh()->last_error);
        $this->assertNull($off->refresh()->last_fetched_at);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
