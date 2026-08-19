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
 * tenant in scope, which the wall reads as deny rather than as everything.
 */
class BindTenantToRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::applyWebRequest($this->resolveTenantId($request));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::resetWebRequest();
    }

    private function resolveTenantId(Request $request): ?int
    {
        $slug = $this->subdomain($request->getHost());

        if ($slug === null) {
            return null;
        }

        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('active', true)
            ->first();

        return $tenant === null ? null : (int) $tenant->getKey();
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
