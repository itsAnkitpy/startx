<?php

namespace App\Filament\Platform\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

/**
 * The sign-in page for our own area. Filament's own form, unchanged — so the
 * throttling, the failed-attempt messages and remember-me behave exactly as they do
 * on a client's sign-in — inside the same branded shell the client's page uses.
 *
 * Nothing here narrows the lookup or redirects: this page only ever answers on the
 * plain address, because the panel is pinned to it.
 */
class Login extends BaseLogin
{
    protected string $view = 'filament.auth.login';

    protected static string $layout = 'filament.auth.login-layout';
}
