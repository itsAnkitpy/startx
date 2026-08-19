<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * Thrown when code touches a tenant-owned model with no tenant in scope and
 * outside an audited cross-tenant block. The alternative — returning nothing —
 * reads as an empty table and hides the bug.
 */
class TenantContextMissing extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(
            "No tenant is in scope, so [{$model}] cannot be queried. Set one with "
            .'TenantContext::run(), or use TenantContext::cross() if reaching across tenants is intended.'
        );
    }
}
