<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned: its queries are narrowed to the tenant in scope,
 * and a new record is stamped with that tenant rather than trusting the caller to
 * pass it.
 *
 * The matching table carries `tenant_id`, `UNIQUE (tenant_id, id)` so other
 * tenant-owned tables can point at it with a composite foreign key, and row-level
 * security switched on through {@see Rls::enable}.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenantId = TenantContext::id();

            if ($tenantId === null) {
                throw TenantContextMissing::forModel($model::class);
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
