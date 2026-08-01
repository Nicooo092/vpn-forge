<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\TrafficLogKind;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Pages\Exports;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\TrafficLog;
use App\Models\User;
use App\Services\Reports\ExportArchive;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ServiceReportData;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    private ExportArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->archive = app(ExportArchive::class);
        File::deleteDirectory($this->archive->directory());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->archive->directory());
        parent::tearDown();
    }

    private function makeService(string $iface = 'wg0'): Service
    {
        return Service::create([
            'name' => $iface === 'wg0' ? 'home' : $iface,
            'interface_name' => $iface,
            'subnet_cidr' => '10.0.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [],
        ]);
    }

    private function withTraffic(Service $service): Service
    {
        $user = ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ]);

        foreach ([['www.youtube.com', 30], ['pagead2.googlesyndication.com', 50], ['facebook.com', 10]] as [$host, $n]) {
            for ($i = 0; $i < $n; $i++) {
                TrafficLog::create([
                    'service_id' => $service->id,
                    'service_user_id' => $user->id,
                    'kind' => TrafficLogKind::Dns,
                    'occurred_at' => now()->subMinutes(5),
                    'source_ip' => '10.0.0.2',
                    'host' => $host,
                    'detail' => ['query_type' => 'A'],
                ]);
            }
        }

        return $service;
    }

    public function test_the_report_data_aggregates_the_dns_log(): void
    {
        $service = $this->withTraffic($this->makeService());
        $data = new ServiceReportData($service, now()->subDays(7));

        $this->assertTrue($data->hasData());
        $this->assertSame(90, $data->totalLookups());
        $this->assertSame(3, $data->distinctDomains());

        // Ordered by hits: the ad domain (50) is the busiest single host.
        $this->assertSame('pagead2.googlesyndication.com', $data->topDomains()[0]->host);

        $this->assertSame('phone', $data->topUsers()[0]['name']);
        $this->assertSame(90, $data->topUsers()[0]['hits']);
    }

    public function test_report_pdf_renders_a_pdf_document(): void
    {
        $service = $this->withTraffic($this->makeService());

        $bytes = app(ReportPdf::class)->render($service, now()->subDays(7), 'Last 7 days');

        // Every PDF file begins with this signature.
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_the_command_writes_a_pdf_for_active_services_with_data(): void
    {
        $this->withTraffic($this->makeService('wg0'));

        Artisan::call('vpnforge:export-report');

        $files = $this->archive->list();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('report-wg0-', $files[0]['name']);
        $this->assertStringEndsWith('.pdf', $files[0]['name']);
    }

    public function test_the_command_skips_services_without_activity(): void
    {
        // Active service, but no traffic logged.
        $this->makeService('wg0');

        Artisan::call('vpnforge:export-report');

        $this->assertSame([], $this->archive->list());
    }

    public function test_old_exports_are_pruned(): void
    {
        $path = $this->archive->put('report-old.pdf', '%PDF-1.4 stub');
        touch($path, Carbon::now()->subDays(90)->getTimestamp());
        $this->archive->put('report-fresh.pdf', '%PDF-1.4 stub');

        $deleted = $this->archive->prune(60);

        $this->assertSame(1, $deleted);
        $names = array_column($this->archive->list(), 'name');
        $this->assertContains('report-fresh.pdf', $names);
        $this->assertNotContains('report-old.pdf', $names);
    }

    public function test_resolve_rejects_a_traversal_name(): void
    {
        $this->archive->put('report-real.pdf', '%PDF stub');

        $this->assertNull($this->archive->resolve('../../../etc/passwd'));
        $this->assertNotNull($this->archive->resolve('report-real.pdf'));
    }

    public function test_the_exports_page_lists_generated_files(): void
    {
        $this->withTraffic($this->makeService('wg0'));
        Artisan::call('vpnforge:export-report');

        Livewire::test(Exports::class)
            ->assertOk()
            ->assertSee('report-wg0-');
    }

    public function test_the_generate_action_creates_a_report(): void
    {
        $this->withTraffic($this->makeService('wg0'));

        Livewire::test(Exports::class)
            ->callAction(TestAction::make('generateReports'))
            ->assertHasNoActionErrors();

        $this->assertNotSame([], $this->archive->list());
    }

    public function test_the_export_logs_action_creates_a_zip(): void
    {
        $this->withTraffic($this->makeService('wg0'));

        Livewire::test(Exports::class)
            ->callAction(TestAction::make('exportLogs'))
            ->assertHasNoActionErrors();

        $zips = array_filter($this->archive->list(), fn ($f) => str_ends_with($f['name'], '.zip'));
        $this->assertNotSame([], $zips);
    }
}
