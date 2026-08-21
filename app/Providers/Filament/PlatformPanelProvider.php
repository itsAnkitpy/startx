<?php

namespace App\Providers\Filament;

use App\Filament\Platform\Auth\Login;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * SummerHill's own area. Every serious product keeps its own staff out of the
 * customer's sign-in — Shopify's partners hold a different kind of account and sign in
 * through their own dashboard, and GitLab's hosted product has no administration area
 * in the customer application at all. This is that separate door.
 *
 * What it may hold is limited by a standing rule recorded in module 01: anything our
 * people can do inside a client company, that company's own administrator can already
 * do. So this area manages companies, never what is inside one.
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')

            // The plain address only. A client's address carries a company in scope and
            // everything there belongs to that company; our area belongs to none of them,
            // so `meridian.startx.test/platform` is not a thing that exists.
            ->domain((string) config('tenancy.central_domain'))

            ->login(Login::class)
            ->brandName('StartX Platform')
            ->favicon(asset('favicon.svg'))
            ->darkMode(isForced: fn (): bool => request()->routeIs('filament.platform.auth.login'))
            ->colors([
                'primary' => Color::hex('#6E88FF'),
            ])
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\Filament\Platform\Resources')

            // No dashboard and no widgets: there is one thing to do here, and a landing
            // page counting client companies can wait until there are enough to count.
            ->authGuard('platform')
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
