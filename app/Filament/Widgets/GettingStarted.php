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
     * @return array<int, array{title: string, description: string, done: bool, url: string, cta: string}>
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
            ],
            [
                'title' => 'Set your server\'s public address',
                'description' => 'The endpoint host clients connect to. Configs point at CHANGE-ME.example.com until you set it.',
                'done' => self::endpointIsSet($service),
                'url' => $hasService
                    ? ServiceResource::getUrl('edit', ['record' => $service])
                    : ServiceResource::getUrl('create'),
                'cta' => 'Set address',
            ],
            [
                'title' => 'Add your first user',
                'description' => 'Generates their keys and a downloadable config -- and a QR code for WireGuard.',
                'done' => ServiceUser::query()->exists(),
                'url' => $hasService
                    ? ServiceResource::getUrl('users', ['record' => $service])
                    : ServiceResource::getUrl('create'),
                'cta' => 'Add user',
            ],
            [
                'title' => 'Turn on two-factor authentication',
                'description' => 'This panel can read everyone\'s browsing history -- a second factor is worth it.',
                'done' => filled(Auth::user()?->app_authentication_secret),
                'url' => Filament::getProfileUrl() ?? '#',
                'cta' => 'Set up 2FA',
            ],
        ];
    }
}
