<?php

namespace App\Providers\Filament;

use App\Filament\Auth\SvgAppAuthentication;
use App\Http\Middleware\SecurityHeaders;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // A panel that can read everyone's browsing history is worth a
            // second factor. Optional rather than enforced: turning the
            // required flag on locks out anyone who has not set it up yet,
            // which on a running install means locking out the only operator.
            // Add `isRequired: true` below once every account has one.
            ->multiFactorAuthentication(
                SvgAppAuthentication::make()
                    // Without recovery codes, losing the phone means losing
                    // the panel, and the only way back is editing the
                    // database by hand over SSH.
                    ->recoverable(),
            )
            // Where an operator sets it up, and the only page that exposes
            // those actions.
            ->profile()
            ->brandName('vpn-forge')
            // Filament otherwise caps page content at 7xl (80rem / 1280px)
            // -- see resources/views/components/layout/index.blade.php. On a
            // wider screen that leaves a dead strip down the right while the
            // log tables, which have far more to show than 1280px holds,
            // scroll sideways inside the cap.
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Only this app's own widgets. Filament's starter pair is
            // deliberately absent: AccountWidget repeats the user menu that is
            // already in the top bar, and FilamentInfoWidget is a framework
            // version/links card that belongs in a fresh install, not on an
            // operator's dashboard.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // The panel runs its own middleware stack rather than the web
                // group, so the response-hardening headers are attached here.
                SecurityHeaders::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
