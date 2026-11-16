<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A client company. The one table in the system with no `tenant_id` on it, because
 * it is the tenant, and the one table with no row-level security for the same
 * reason. Everything else hangs off it.
 *
 * `slug` is the subdomain, and it is what resolves the tenant before anyone
 * authenticates.
 */
#[Fillable(['name', 'slug', 'legal_name', 'country', 'timezone', 'active', 'onboarded_at'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'onboarded_at' => 'datetime',
        ];
    }

    /**
     * This company's own web address, with a path on it.
     *
     * Built from the subdomain rather than from `route()`, because the addresses that
     * matter most are made where there is no request to read a host from — a scheduled
     * pass, a command, a seeder — and `route()` would answer with the platform's own
     * domain, which has no client company in scope at all.
     */
    public function addressFor(string $path = '/'): string
    {
        $scheme = str_starts_with((string) config('app.url'), 'https://') ? 'https' : 'http';

        return $scheme.'://'.$this->slug.'.'.config('tenancy.central_domain').'/'.ltrim($path, '/');
    }
}
