<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\TrafficLogKind;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Resources\Services\Pages\ManageTrafficLogs;
use App\Filament\Resources\Services\Pages\ServiceReport;
use App\Jobs\Vpn\ApplyServiceConfig;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\TrafficLog;
use App\Models\User;
use App\Services\Traffic\DomainCategory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class TrafficVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private ServiceUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $this->service = Service::create([
            'name' => 'test',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.0.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => ['blocked_domains' => []],
        ]);

        $this->user = ServiceUser::create([
            'service_id' => $this->service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ]);
    }

    private function logDns(string $host, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            TrafficLog::create([
                'service_id' => $this->service->id,
                'service_user_id' => $this->user->id,
                'kind' => TrafficLogKind::Dns,
                'occurred_at' => now()->subMinutes(5),
                'source_ip' => '10.0.0.2',
                'host' => $host,
                'detail' => ['query_type' => 'A'],
            ]);
        }
    }

    public function test_domains_are_categorised(): void
    {
        $this->assertSame(DomainCategory::Streaming, DomainCategory::for('r1---sn-abc.googlevideo.com'));
        $this->assertSame(DomainCategory::Social, DomainCategory::for('scontent.fcdg1-1.fbcdn.net'));
        $this->assertSame(DomainCategory::Advertising, DomainCategory::for('pagead2.googlesyndication.com'));
        $this->assertSame(DomainCategory::Ai, DomainCategory::for('chatgpt.com'));
        $this->assertSame(DomainCategory::Messaging, DomainCategory::for('media.discordapp.net'));
        $this->assertSame(DomainCategory::Developer, DomainCategory::for('raw.githubusercontent.com'));
        // Keyword fallback.
        $this->assertSame(DomainCategory::Advertising, DomainCategory::for('telemetry.example.org'));
        // Unknown -> Other.
        $this->assertSame(DomainCategory::Other, DomainCategory::for('some-random-host.example'));
        $this->assertSame(DomainCategory::Other, DomainCategory::for(null));
    }

    public function test_the_report_page_renders_with_a_category_breakdown(): void
    {
        $this->logDns('www.youtube.com', 30);
        $this->logDns('facebook.com', 10);
        $this->logDns('pagead2.googlesyndication.com', 60);

        Livewire::test(ServiceReport::class, ['record' => $this->service->getRouteKey()])
            ->assertOk()
            ->assertSee('Streaming')
            ->assertSee('Ads & tracking')
            ->assertSee('youtube.com');
    }

    public function test_the_report_handles_a_service_with_no_traffic(): void
    {
        Livewire::test(ServiceReport::class, ['record' => $this->service->getRouteKey()])
            ->assertOk()
            ->assertSee('No DNS activity');
    }

    public function test_blocking_a_domain_from_the_logs_adds_it_and_reapplies(): void
    {
        Queue::fake();
        $this->logDns('ads.tracker.example', 3);

        $log = TrafficLog::where('host', 'ads.tracker.example')->first();

        Livewire::test(ManageTrafficLogs::class, ['record' => $this->service->getRouteKey()])
            ->callAction(TestAction::make('block')->table($log), data: [])
            ->assertHasNoActionErrors();

        $this->service->refresh();
        $this->assertContains('ads.tracker.example', $this->service->config['blocked_domains']);
        Queue::assertPushed(ApplyServiceConfig::class);
    }

    public function test_the_block_action_is_hidden_for_an_already_blocked_domain(): void
    {
        $this->service->update(['config' => ['blocked_domains' => ['already.blocked.example']]]);
        $this->logDns('already.blocked.example', 1);

        $log = TrafficLog::where('host', 'already.blocked.example')->first();

        Livewire::test(ManageTrafficLogs::class, ['record' => $this->service->getRouteKey()])
            ->assertActionHidden(TestAction::make('block')->table($log));
    }
}
