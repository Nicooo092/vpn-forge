<?php

namespace Tests\Feature;

use App\Filament\Pages\Updates;
use App\Models\User;
use App\Services\Update\UpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class UpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('vpnforge.update.repo', 'Nicooo092/vpn-forge');
    }

    public function test_it_reports_up_to_date_when_github_says_identical(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['status' => 'identical', 'ahead_by' => 0, 'commits' => []], 200)]);

        $status = app(UpdateChecker::class)->check(fresh: true);

        $this->assertSame('up-to-date', $status['status']);
        $this->assertSame(0, $status['behind']);
    }

    public function test_it_reports_behind_with_the_new_commit_messages(): void
    {
        Http::fake(['api.github.com/*' => Http::response([
            'status' => 'ahead',
            'ahead_by' => 3,
            // GitHub returns these oldest-first.
            'commits' => [
                ['commit' => ['message' => "Oldest change\n\nbody"]],
                ['commit' => ['message' => 'Middle change']],
                ['commit' => ['message' => 'Newest change']],
            ],
        ], 200)]);

        $status = app(UpdateChecker::class)->check(fresh: true);

        $this->assertSame('behind', $status['status']);
        $this->assertSame(3, $status['behind']);
        // Newest first, and only the subject line of each.
        $this->assertSame('Newest change', $status['commits'][0]);
        $this->assertContains('Oldest change', $status['commits']);
    }

    public function test_it_reports_unknown_when_github_is_unreachable(): void
    {
        Http::fake(['api.github.com/*' => Http::response('nope', 503)]);

        $status = app(UpdateChecker::class)->check(fresh: true);

        $this->assertSame('unknown', $status['status']);
        $this->assertNotNull($status['message']);
    }

    public function test_the_upgrade_command_is_the_canonical_deploy_sequence(): void
    {
        $command = app(UpdateChecker::class)->upgradeCommand();

        $this->assertStringContainsString('git pull --ff-only', $command);
        $this->assertStringContainsString('composer install --no-dev', $command);
        $this->assertStringContainsString('artisan migrate --force', $command);
        $this->assertStringContainsString('systemctl restart vpnforge-worker', $command);
    }

    public function test_the_updates_page_shows_the_command_when_behind(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['api.github.com/*' => Http::response([
            'status' => 'ahead', 'ahead_by' => 1, 'commits' => [['commit' => ['message' => 'A new feature']]],
        ], 200)]);

        Livewire::test(Updates::class)
            ->assertOk()
            ->assertSee('Update available')
            ->assertSee('A new feature')
            ->assertSee('git pull --ff-only');
    }
}
