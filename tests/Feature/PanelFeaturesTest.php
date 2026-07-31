<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Pages\Backups;
use App\Filament\Resources\ServiceUsers\Pages\ViewServiceUser;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\User;
use App\Services\Vpn\ConnectivityCheck;
use App\Services\Vpn\DnsmasqManager;
use App\Services\Vpn\WireGuard\WireGuardDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PanelFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

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
            'config' => ['endpoint_host' => '203.0.113.10'],
        ]);
    }

    public function test_the_per_user_page_renders(): void
    {
        $user = ServiceUser::create([
            'service_id' => $this->service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
            'labels' => ['family', 'mobile'],
            'data_limit_bytes' => 5_000_000,
            'quota_started_at' => now()->subDay(),
        ]);

        Livewire::test(ViewServiceUser::class, ['record' => $user->getKey()])
            ->assertOk()
            ->assertSee('phone')
            ->assertSee('family')
            ->assertSee('10.0.0.2');
    }

    /**
     * Nothing is checkable from a Windows test runner, so what matters is
     * that every check reports rather than throwing, and that an
     * undeterminable one comes back null instead of a false failure.
     */
    public function test_the_connectivity_check_reports_every_line(): void
    {
        $checks = app(ConnectivityCheck::class)->run($this->service);

        $this->assertNotEmpty($checks);

        foreach ($checks as $check) {
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('detail', $check);
            $this->assertTrue($check['ok'] === null || is_bool($check['ok']));
            $this->assertNotSame('', $check['detail']);
        }

        // A literal IP endpoint has nothing to resolve, so that check is
        // omitted rather than reported as failed.
        $labels = array_column($checks, 'label');
        $this->assertNotContains('Endpoint hostname resolves', $labels);
    }

    public function test_blocked_domains_reach_the_resolver_config(): void
    {
        $this->service->update([
            'config' => array_merge($this->service->config, [
                'blocked_domains' => ['ads.example.com', 'tracker.test.', ''],
                'dns_upstreams' => ['9.9.9.9'],
            ]),
        ]);

        $config = app(DnsmasqManager::class)->buildConfig($this->service->fresh());

        $this->assertStringContainsString('address=/ads.example.com/', $config);
        // The trailing dot is trimmed, and the empty entry dropped entirely.
        $this->assertStringContainsString('address=/tracker.test/', $config);
        $this->assertStringNotContainsString('address=//', $config);
        $this->assertStringContainsString('server=9.9.9.9', $config);
        $this->assertStringNotContainsString('server=1.1.1.1', $config);

        // The resolver still has to answer on the tunnel gateway, or blocking
        // a domain would be the least of the problems.
        $this->assertStringContainsString('listen-address=10.0.0.1', $config);
    }

    /**
     * Clearing the upstream tag field stores [], which `?? default` does not
     * catch. With no-resolv and no server= line, dnsmasq answers every lookup
     * with a failure -- the tunnel stays up and nothing resolves, which is
     * indistinguishable from the VPN being down. This took the live server
     * off the air once; it must not again.
     */
    public function test_an_empty_upstream_list_falls_back_rather_than_leaving_no_resolver(): void
    {
        $this->service->update([
            'config' => array_merge($this->service->config, ['dns_upstreams' => []]),
        ]);

        $config = app(DnsmasqManager::class)->buildConfig($this->service->fresh());

        $this->assertStringContainsString('server=1.1.1.1', $config);
        $this->assertStringContainsString('no-resolv', $config);
    }

    public function test_blank_entries_in_the_upstream_list_are_ignored(): void
    {
        $this->service->update([
            'config' => array_merge($this->service->config, ['dns_upstreams' => ['', '  ', '9.9.9.9']]),
        ]);

        $config = app(DnsmasqManager::class)->buildConfig($this->service->fresh());

        $this->assertStringContainsString('server=9.9.9.9', $config);
        $this->assertStringNotContainsString("server=\n", $config);
        $this->assertStringNotContainsString('server= ', $config);
    }

    public function test_a_client_config_never_ships_an_empty_dns_line(): void
    {
        $this->service->update([
            'config' => array_merge($this->service->config, [
                'push_dns' => false,
                'dns' => [],
                'server_public_key' => 'server-key',
            ]),
        ]);

        $user = ServiceUser::create([
            'service_id' => $this->service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
            'wg_private_key' => 'k',
            'wg_public_key' => 'k',
            'wg_preshared_key' => 'k',
        ]);

        $contents = (new WireGuardDriver)
            ->buildClientConfig($this->service->fresh(), $user)
            ->contents;

        $this->assertStringNotContainsString("DNS = \n", $contents);
        $this->assertStringContainsString('DNS = 1.1.1.1, 1.0.0.1', $contents);
    }

    public function test_a_service_with_no_blocklist_produces_no_address_directives(): void
    {
        $config = app(DnsmasqManager::class)->buildConfig($this->service);

        $this->assertStringNotContainsString('address=/', $config);
        $this->assertStringContainsString('server=1.1.1.1', $config);
    }

    public function test_an_admin_account_can_be_created_from_the_panel(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction('create', data: [
                'name' => 'Second operator',
                'email' => 'second@example.com',
                'password' => 'a-long-enough-password',
                'password_confirmation' => 'a-long-enough-password',
            ])
            ->assertHasNoActionErrors();

        $created = User::where('email', 'second@example.com')->firstOrFail();

        $this->assertNotSame('a-long-enough-password', $created->password);
        $this->assertTrue(Hash::check('a-long-enough-password', $created->password));
    }

    public function test_a_short_password_is_rejected(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction('create', data: [
                'name' => 'Too easy',
                'email' => 'weak@example.com',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertHasActionErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    public function test_the_admin_account_list_renders(): void
    {
        $this->get(UserResource::getUrl('index'))->assertOk();
    }

    public function test_the_backups_page_renders_with_none(): void
    {
        Livewire::test(Backups::class)
            ->assertOk()
            ->assertSee('No backups yet');
    }
}
