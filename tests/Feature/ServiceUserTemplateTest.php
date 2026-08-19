<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Services\Schemas\ServiceUserForm;
use App\Models\ServiceUserTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Templates are a create-form convenience: the only behaviour to pin is the
 * mapping from a saved template to the form's field state (in display units)
 * and the admin-only write boundary on the resource.
 */
class ServiceUserTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_template_maps_to_the_create_form_state_in_display_units(): void
    {
        $template = ServiceUserTemplate::create([
            'name' => 'child',
            'logging_override' => true,
            'labels' => ['family'],
            'dns_override' => ['1.1.1.1', '9.9.9.9'],
            'rate_limit_kbps' => 5000,           // 5 Mbit/s
            'data_limit_bytes' => 5 * 1024 ** 3, // 5 GB
            'access_days' => [1, 2, 3, 4, 5],
            'access_start_minute' => 16 * 60,    // 16:00
            'access_end_minute' => 21 * 60,      // 21:00
        ]);

        $state = ServiceUserForm::formStateFromTemplate($template);

        // Logging is the select's string tri-state, not the stored boolean.
        $this->assertSame('1', $state['logging_override']);
        $this->assertSame(['family'], $state['labels']);
        $this->assertSame(['1.1.1.1', '9.9.9.9'], $state['dns_override']);
        // kbit/s -> Mbit/s and bytes -> GB, the units the fields display.
        $this->assertSame(5.0, $state['rate_limit_kbps']);
        $this->assertSame(5.0, $state['data_limit_bytes']);
        $this->assertSame([1, 2, 3, 4, 5], $state['access_days']);
        // Minutes-of-day -> the HH:MM the TimePicker expects.
        $this->assertSame('16:00', $state['access_start_minute']);
        $this->assertSame('21:00', $state['access_end_minute']);
    }

    public function test_unset_template_fields_map_to_empty_form_state(): void
    {
        $template = ServiceUserTemplate::create(['name' => 'blank']);

        $state = ServiceUserForm::formStateFromTemplate($template);

        // Empty, not forced-off: '' is the logging select's "inherit" option.
        $this->assertSame('', $state['logging_override']);
        $this->assertSame([], $state['labels']);
        $this->assertSame([], $state['dns_override']);
        $this->assertNull($state['rate_limit_kbps']);
        $this->assertNull($state['data_limit_bytes']);
        $this->assertSame([], $state['access_days']);
        $this->assertNull($state['access_start_minute']);
        $this->assertNull($state['access_end_minute']);
    }

    public function test_only_admins_may_write_templates(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $auditor = User::factory()->create(['role' => UserRole::Auditor]);
        $template = ServiceUserTemplate::create(['name' => 'guest']);

        $this->assertTrue($admin->can('create', ServiceUserTemplate::class));
        $this->assertTrue($admin->can('update', $template));
        $this->assertTrue($admin->can('delete', $template));

        // An auditor reads but never writes.
        $this->assertTrue($auditor->can('view', $template));
        $this->assertFalse($auditor->can('create', ServiceUserTemplate::class));
        $this->assertFalse($auditor->can('update', $template));
        $this->assertFalse($auditor->can('delete', $template));
    }
}
