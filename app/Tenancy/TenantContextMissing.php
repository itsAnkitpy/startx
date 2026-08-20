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

    /**
     * Thrown when a setting is read or written with no tenant in scope. Handing back
     * the declared default here would be worse than the error: a scheduled pass that
     * forgot to name its client company would read a money threshold nobody chose and
     * act on it.
     */
    public static function forSetting(string $key): self
    {
        return new self(
            "No tenant is in scope, so the setting [{$key}] cannot be read or written. A default "
            .'returned here would be a value no client had chosen. Set the client company with '
            .'TenantContext::run().'
        );
    }
}
