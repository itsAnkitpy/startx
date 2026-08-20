<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the tenant in scope for a web request, from the subdomain, before anything
 * else runs — including authentication, because the subdomain says which client
 * company an email address belongs to.
 *
 * The marker is written fresh on every request, so a connection reused after a
 * previous request cannot inherit its tenant. An unrecognised subdomain leaves no
 * tenant in scope, which the wall reads as deny rather than as everything, and the
 * request stops here with a page saying so rather than reaching a sign-in form
 * nobody can use.
 *
 * The central domain itself is not a wrong address — it has no company in it on
 * purpose, and carries on to the welcome page.
 */
class BindTenantToRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->subdomain($request->getHost());
        $tenant = $slug === null ? null : Tenant::query()->where('slug', $slug)->first();

        TenantContext::applyWebRequest($tenant?->active === true ? (int) $tenant->getKey() : null);

        // An address naming a client company we do not have, and one naming a company whose
        // access is switched off, are two different things to whoever typed it — and before
        // this they were one blank refusal, so somebody at a company locked out for
        // non-payment saw exactly what a stranger guessing addresses saw. Neither page says
        // why access is off: that is between SummerHill and the company, not something to
        // print for every employee.
        if ($slug !== null && $tenant === null) {
            return response()->view('tenant-door', [
                'heading' => 'No StartX company at this address',
                'message' => 'Check the address with your HR team — they have the link for your company.',
            ], 404);
        }

        if ($tenant !== null && ! $tenant->active) {
            return response()->view('tenant-door', [
                'heading' => 'Signing in is not available',
                'message' => $tenant->name.' cannot be signed in to right now. Your HR team can sort this out.',
            ], 403);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::resetWebRequest();
    }

    /**
     * The part of the host in front of the central domain, or null when the request
     * is for the central domain itself.
     */
    private function subdomain(string $host): ?string
    {
        $central = (string) config('tenancy.central_domain');
        $suffix = '.'.$central;

        if ($central === '' || ! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        return $slug === '' ? null : $slug;
    }
}
