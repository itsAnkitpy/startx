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
}
