<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel loads a compiled Vite theme instead of Filament's prebuilt CSS, and
 * production deploys with a plain `git pull` -- no Node, no build step. So a
 * missing or unbuilt manifest would not fail loudly at deploy time, it would
 * serve the whole panel unstyled (or throw a Vite manifest exception on every
 * page). These tests are the guard: they fail in CI the moment the committed
 * build output goes missing or stops matching the theme entrypoint.
 */
class PanelThemeTest extends TestCase
{
    use RefreshDatabase;

    private const ENTRY = 'resources/css/filament/admin/theme.css';

    public function test_the_build_manifest_is_committed_and_contains_the_theme_entry(): void
    {
        $manifestPath = public_path('build/manifest.json');

        $this->assertFileExists(
            $manifestPath,
            'public/build/manifest.json is missing -- run `npm run build` and commit the output.'
        );

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey(
            self::ENTRY,
            $manifest,
            'The Filament theme entrypoint is not in the build manifest -- run `npm run build`.'
        );

        // The file the manifest points at has to actually be on disk, otherwise
        // the panel renders a stylesheet link that 404s.
        $file = public_path('build/'.$manifest[self::ENTRY]['file']);
        $this->assertFileExists($file, 'The compiled theme file referenced by the manifest is missing.');
        $this->assertGreaterThan(1024, filesize($file), 'The compiled theme looks empty.');
    }

    public function test_the_panel_renders_and_links_the_compiled_theme(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/admin');

        $response->assertOk();

        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        // The rendered page must reference the built theme, not Filament's
        // default stylesheet -- that is the difference between utility classes
        // working in our custom views and silently doing nothing.
        $response->assertSee($manifest[self::ENTRY]['file'], escape: false);
    }
}
