<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(Login::class)
            ->brandName('StartX')
            ->favicon(asset('favicon.svg'))

            // The sign-in page is a dark design, and it is the one page in the panel
            // nobody has chosen a theme for yet. Forcing dark there puts the class on the
            // document from the server, so there is no pale flash before Filament's own
            // scripts run. Everywhere else the person's own choice still decides.
            ->darkMode(isForced: fn (): bool => request()->routeIs('filament.admin.auth.login'))

            // Filament's own documentation is explicit that a missing policy, or a
            // missing method on one, *grants* access — it assumes authorization has not
            // been set up yet. For a product whose whole claim is an attributable trail
            // over salary and settlement figures, a forgotten policy has to fail loudly
            // instead of quietly opening a screen.
            ->strictAuthorization()
            // The brand blue, the same #6E88FF the sign-in and welcome pages are built
            // from. Amber was the scaffold's default and matched nothing.
            ->colors([
                'primary' => Color::hex('#6E88FF'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Two groups, because this is about to be ten screens rather than four. The
            // order is fixed here rather than left to whichever page is discovered first.
            ->navigationGroups([
                'Your work',
                'Company setup',
            ])

            // No dashboard. Signing in put everybody on an empty page carrying two boxes:
            // their own name, and Filament's own panel telling a paying client the
            // framework's name and version number. With nothing registered at `/admin`,
            // Filament sends whoever signs in to the first item in their own navigation,
            // which is what is waiting on them.
            ->pages([])
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
