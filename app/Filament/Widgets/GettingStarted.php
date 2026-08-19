<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use App\Models\ServiceUser;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * A first-run checklist that walks a new operator from an empty install to a
 * working tunnel, then gets out of the way: once the essentials (a service
 * with a real public address, and a user) exist, the whole widget hides
 * itself rather than nagging forever.
 */
class GettingStarted extends Widget
{
    protected string $view = 'filament.widgets.getting-started';

    // Cheap to compute (a couple of exists() queries), so render it inline
    // instead of the widget default of lazy -- no loading skeleton on the very
    // first screen a new operator sees.
    protected static bool $isLazy = false;

    // Above every other dashboard widget.
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return ! self::essentialsDone();
    }

    private static function essentialsDone(): bool
    {
        $service = self::firstService();

        return $service !== null
            && self::endpointIsSet($service)
            && ServiceUser::query()->exists();
    }

    private static function firstService(): ?Service
    {
        return Service::query()->oldest('id')->first();
    }

    private static function endpointIsSet(?Service $service): bool
    {
        $host = $service?->config['endpoint_host'] ?? null;

        return filled($host) && $host !== 'CHANGE-ME.example.com';
    }

    /**
     * English source strings; the view translates them through __(). Icons are
     * shown while a step is pending -- a green check replaces them once done.
     *
     * Every title, description and cta below is a translation key even though
     * no __() appears here: the call site is __($step['title']) in the view. A
     * literal-only extractor cannot see them, so they have to be kept in the
     * lang files by hand or the first-run checklist silently reverts to
     * English.
     *
     * @return array<int, array{title: string, description: string, done: bool, url: string, cta: string, icon: string}>
     */
    public function getSteps(): array
    {
        $service = self::firstService();
        $hasService = $service !== null;

        return [
            [
                'title' => 'Create your first VPN service',
                'description' => 'A service is one tunnel -- WireGuard or OpenVPN -- with its own subnet and users.',
                'done' => $hasService,
                'url' => $hasService
                    ? ServiceResource::getUrl('edit', ['record' => $service])
                    : ServiceResource::getUrl('create'),
                'cta' => $hasService ? 'View service' : 'Create service',
                'icon' => 'heroicon-o-server',
            ],
            [
                'title' => 'Set your server\'s public address',
                'description' => 'The endpoint host clients connect to. Configs point at CHANGE-ME.example.com until you set it.',
                'done' => self::endpointIsSet($service),
                'url' => $hasService
                    ? ServiceResource::getUrl('edit', ['record' => $service])
                    : ServiceResource::getUrl('create'),
                'cta' => 'Set address',
                'icon' => 'heroicon-o-globe-alt',
            ],
            [
                'title' => 'Add your first user',
                'description' => 'Generates their keys and a downloadable config -- and a QR code for WireGuard.',
                'done' => ServiceUser::query()->exists(),
                'url' => $hasService
                    ? ServiceResource::getUrl('users', ['record' => $service])
                    : ServiceResource::getUrl('create'),
                'cta' => 'Add user',
                'icon' => 'heroicon-o-user-plus',
            ],
            [
                'title' => 'Turn on two-factor authentication',
                'description' => 'This panel can read everyone\'s browsing history -- a second factor is worth it.',
                'done' => (Auth::user()?->hasEnabledTwoFactorAuthentication() ?? false)
                    || (Auth::user()?->hasEnabledPasskeyAuthentication() ?? false),
                'url' => Filament::getProfileUrl() ?? '#',
                'cta' => 'Set up 2FA',
                'icon' => 'heroicon-o-lock-closed',
            ],
        ];
    }
}
