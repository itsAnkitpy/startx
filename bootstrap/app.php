<?php

use App\Http\Middleware\BindTenantToRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The subdomain decides which client company a request belongs to, and the host
        // name arrives inside the request — so without this a request may claim any
        // subdomain it likes. Nothing is readable without also signing in as somebody
        // who belongs to that company, which is why this becomes a requirement the
        // moment accounts exist rather than earlier.
        //
        // The one domain named here is the same one the subdomain lookup reads, so
        // choosing the real domain later changes one environment value and nothing else.
        //
        // Two traps in Laravel's own handling, both read in its source on 20 August 2026.
        // Every entry is used as a regular expression, so a bare domain would match far
        // more than itself — the dots are wildcards and there are no anchors, which lets
        // a host somebody else controls match. And `subdomains: true` does not mean
        // subdomains of the hosts listed here; it appends a pattern built from `app.url`,
        // which is a different setting and need not be this domain at all. So the pattern
        // is written out in full and that flag is left off.
        $middleware->trustHosts(
            at: fn (): array => [
                '^(.+\.)?'.preg_quote((string) config('tenancy.central_domain')).'$',
            ],
            subdomains: false,
        );

        // Before anything else, including authentication: the subdomain says which
        // client company this request belongs to, and the database wall denies
        // every read until it is told.
        $middleware->prepend(BindTenantToRequest::class);

        // Where somebody signed out is sent when they open a page that needs an account —
        // a document link forwarded to them, most likely. Laravel looks for a route called
        // `login` and there is none: every sign-in page in this product belongs to a panel
        // and is named after it, so without this an ordinary forwarded link answers with
        // an error page instead of asking them to sign in.
        $middleware->redirectGuestsTo(fn (): string => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
