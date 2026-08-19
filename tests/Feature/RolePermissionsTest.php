<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\UserRole;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two-role model: an Admin can change anything (Gate::before), an Auditor
 * can see everything but mutate nothing. This pins the authorisation boundary
 * so a later change to a policy or the Gate::before cannot silently hand an
 * auditor write access to the panel that reads everyone's traffic.
 */
class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): Service
    {
        return Service::create([
            'name' => 'office',
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

    public function test_an_admin_can_mutate_everything(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $service = $this->service();

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->can('create', Service::class));
        $this->assertTrue($admin->can('update', $service));
        $this->assertTrue($admin->can('delete', $service));
        $this->assertTrue($admin->can('create', User::class));
    }

    public function test_an_auditor_can_read_but_never_write(): void
    {
        $auditor = User::factory()->create(['role' => UserRole::Auditor]);
        $service = $this->service();

        $this->assertTrue($auditor->isAuditor());

        // Read is open.
        $this->assertTrue($auditor->can('viewAny', Service::class));
        $this->assertTrue($auditor->can('view', $service));

        // Every mutation is refused, on every governed model.
        $this->assertFalse($auditor->can('create', Service::class));
        $this->assertFalse($auditor->can('update', $service));
        $this->assertFalse($auditor->can('delete', $service));
        $this->assertFalse($auditor->can('create', User::class));
        $this->assertFalse($auditor->can('update', $auditor));
    }

    public function test_the_last_admin_is_protected(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['role' => UserRole::Auditor]);

        $this->assertSame(1, User::adminCount());
        $this->assertTrue($admin->isLastAdmin());

        $second = User::factory()->create(['role' => UserRole::Admin]);

        $this->assertSame(2, User::adminCount());
        $this->assertFalse($admin->fresh()->isLastAdmin());
        $this->assertFalse($second->isLastAdmin());
    }
}
