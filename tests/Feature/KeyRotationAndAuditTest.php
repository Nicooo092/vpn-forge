<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Pages\ChangeHistory;
use App\Filament\Resources\Services\Pages\ManageServiceUsers;
use App\Jobs\Vpn\RotateUserKeys;
use App\Models\AuditEvent;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\User;
use App\Services\Vpn\DnsPresets;
use App\Services\Vpn\OpenVpn\OpenVpnDriver;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class KeyRotationAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['name' => 'Operator']);
        $this->actingAs($this->operator);

        $this->service = Service::create([
            'name' => 'test',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.0.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [],
        ]);
    }

    private function user(array $attributes = []): ServiceUser
    {
        return ServiceUser::create(array_merge([
            'service_id' => $this->service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.0.0.2',
        ], $attributes));
    }

    /**
     * easy-rsa refuses to build a certificate for a common name it has
     * already revoked, so a reissue has to ask for a different one -- but the
     * first certificate must keep its unsuffixed name or it stops matching
     * the files already on disk.
     */
    public function test_the_openvpn_common_name_changes_only_after_a_rotation(): void
    {
        $method = new ReflectionMethod(OpenVpnDriver::class, 'safeCommonName');

        $user = $this->user(['key_rotations' => 0]);
        $this->assertSame("svc{$this->service->id}-user{$user->id}", $method->invoke(new OpenVpnDriver, $this->service, $user));

        $user->key_rotations = 1;
        $this->assertSame("svc{$this->service->id}-user{$user->id}-r1", $method->invoke(new OpenVpnDriver, $this->service, $user));

        $user->key_rotations = 2;
        $this->assertSame("svc{$this->service->id}-user{$user->id}-r2", $method->invoke(new OpenVpnDriver, $this->service, $user));
    }

    public function test_regenerating_keys_dispatches_the_job_without_touching_identity(): void
    {
        Queue::fake();

        $user = $this->user(['labels' => ['family'], 'expires_at' => now()->addWeek()]);

        Livewire::test(ManageServiceUsers::class, ['record' => $this->service->getRouteKey()])
            ->callAction(TestAction::make('rotate')->table($user))
            ->assertHasNoActionErrors();

        Queue::assertPushed(RotateUserKeys::class);

        $user->refresh();
        $this->assertSame('phone', $user->name);
        $this->assertSame('10.0.0.2', $user->tunnel_ip);
        $this->assertSame(['family'], $user->labels);
        $this->assertSame(ServiceUserStatus::Active, $user->status);
    }

    public function test_a_revoked_user_cannot_have_keys_regenerated(): void
    {
        $user = $this->user(['status' => ServiceUserStatus::Revoked]);

        Livewire::test(ManageServiceUsers::class, ['record' => $this->service->getRouteKey()])
            ->assertActionHidden(TestAction::make('rotate')->table($user));
    }

    public function test_an_edit_is_recorded_against_the_person_who_made_it(): void
    {
        $user = $this->user();
        $user->update(['name' => 'laptop']);

        $event = AuditEvent::where('auditable_type', ServiceUser::class)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($this->operator->id, $event->user_id);
        $this->assertSame('phone', $event->change_set['name']['from']);
        $this->assertSame('laptop', $event->change_set['name']['to']);
        $this->assertStringContainsString('phone -> laptop', $event->summary());
    }

    /**
     * The poller rewrites these for every connected user every minute. One
     * line each would bury every real change within a day.
     */
    public function test_telemetry_updates_are_not_recorded(): void
    {
        $user = $this->user();
        $before = AuditEvent::count();

        $user->forceFill([
            'last_handshake_at' => now(),
            'last_seen_ip' => '203.0.113.9',
            'last_cumulative_bytes_in' => 999,
            'last_cumulative_bytes_out' => 999,
        ])->save();

        $this->assertSame($before, AuditEvent::count());
    }

    public function test_key_material_never_reaches_the_audit_log(): void
    {
        $user = $this->user();

        $user->forceFill([
            'name' => 'renamed',
            'wg_private_key' => 'SECRET-PRIVATE-KEY',
            'wg_preshared_key' => 'SECRET-PRESHARED-KEY',
            'rendered_client_config' => 'SECRET-CONFIG',
        ])->save();

        $recorded = AuditEvent::all()->toJson();

        $this->assertStringNotContainsString('SECRET-PRIVATE-KEY', $recorded);
        $this->assertStringNotContainsString('SECRET-PRESHARED-KEY', $recorded);
        $this->assertStringNotContainsString('SECRET-CONFIG', $recorded);
        // The rename beside them still made it through.
        $this->assertStringContainsString('renamed', $recorded);
    }

    public function test_a_background_change_is_recorded_as_automatic(): void
    {
        auth()->logout();

        $user = $this->user();
        $user->update(['status' => ServiceUserStatus::Suspended]);

        $event = AuditEvent::where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertNull($event->user_id);
    }

    public function test_the_change_history_page_renders(): void
    {
        $this->user()->update(['name' => 'laptop']);

        Livewire::test(ChangeHistory::class)
            ->assertOk()
            ->assertSee('laptop');
    }

    /**
     * A service's whole `config` array is audited as one field whose before/
     * after values are themselves nested arrays. Summarising that with implode
     * threw "Array to string conversion" and 500'd the page in production --
     * the scalar-only test above never reached it.
     */
    public function test_a_nested_config_change_is_summarised_without_crashing(): void
    {
        $this->service->update([
            'config' => [
                'endpoint_host' => 'vpn.example.com',
                'dns_upstreams' => ['1.1.1.1', '1.0.0.1'],
                'push_routes' => [],
            ],
        ]);

        $event = AuditEvent::where('auditable_type', Service::class)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        // Must produce a string, not throw.
        $summary = $event->summary();
        $this->assertIsString($summary);
        $this->assertStringContainsString('1.1.1.1', $summary);

        // And the page that renders it must load.
        Livewire::test(ChangeHistory::class)->assertOk();
    }

    public function test_dns_presets_round_trip(): void
    {
        $this->assertSame(['94.140.14.14', '94.140.15.15'], DnsPresets::serversFor('adguard'));
        $this->assertSame('adguard', DnsPresets::keyFor(['94.140.14.14', '94.140.15.15']));
        $this->assertSame('quad9', DnsPresets::keyFor(['9.9.9.9', '149.112.112.112']));

        // Anything not matching a preset is the custom case, including an
        // empty list on a service that has never been configured.
        $this->assertSame(DnsPresets::CUSTOM, DnsPresets::keyFor(['10.0.0.53']));
        $this->assertSame(DnsPresets::CUSTOM, DnsPresets::keyFor([]));

        $this->assertArrayHasKey(DnsPresets::CUSTOM, DnsPresets::options());
        $this->assertNotNull(DnsPresets::noteFor('adguard'));
    }
}
