<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The application half of the tenant wall: every query built through Eloquent on a
 * tenant-owned model is narrowed to the tenant in scope.
 *
 * This is what makes ordinary application code correct by default. It does not
 * cover raw SQL, which is what the Postgres policy underneath is for.
 *
 * Note that `withoutGlobalScopes()` drops this along with every other scope, and
 * that Laravel's `unique` and `exists` validation rules ignore global scopes
 * entirely — tenant-scoped fields use the scoped variants of those rules.
 */
class TenantScope implements Scope
{
    public const NAME = 'tenant';

    public function apply(Builder $builder, Model $model): void
    {
        if (TenantContext::isCrossTenant()) {
            return;
        }

        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            throw TenantContextMissing::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
