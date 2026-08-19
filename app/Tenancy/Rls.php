<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Postgres row-level security — the database half of the tenant wall.
 *
 * Called from every tenant-owned table's migration. The session markers this
 * relies on are written by {@see TenantContext}: plain `SET` for a web request,
 * `SET LOCAL` inside a transaction for a job, command or import.
 *
 * Row-level security binds only while the connecting role is not a superuser and
 * does not hold BYPASSRLS — both ignore every policy silently. `startx_app` is
 * deliberately neither, and `tests/Feature/DatabaseRoleTest.php` fails if
 * that ever changes.
 */
class Rls
{
    /** Session marker holding the tenant in scope. An empty string means none. */
    public const TENANT_MARKER = 'app.tenant_id';

    /** Session marker that, set to 'on', opens the audited cross-tenant path. */
    public const BYPASS_MARKER = 'app.bypass_rls';

    /**
     * Switch tenant isolation on for a table: enable row-level security, force it
     * so the table's own owner obeys it too, and file a default-deny policy. No
     * tenant in scope means zero rows, never every row.
     */
    public static function enable(string $table, string $tenantColumn = 'tenant_id'): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($tenantColumn);

        $policy = self::policyName($table);

        // NULLIF is not cosmetic: without it a marker left as an empty string
        // raises a cast error instead of denying the read.
        $predicate = sprintf(
            "(current_setting('%s', true) = 'on' OR %s = NULLIF(current_setting('%s', true), '')::bigint)",
            self::BYPASS_MARKER,
            $tenantColumn,
            self::TENANT_MARKER,
        );

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");

        // WITH CHECK is written out as well as USING, so an insert or an update
        // carrying another tenant's id is refused by the database rather than
        // only by the Eloquent scope.
        DB::statement("CREATE POLICY {$policy} ON {$table} FOR ALL USING {$predicate} WITH CHECK {$predicate}");
    }

    public static function policyName(string $table): string
    {
        self::assertIdentifier($table);

        return "{$table}_tenant_isolation";
    }

    /**
     * These identifiers come from migration code rather than from user input, but
     * this class writes the security boundary, so it stays strict.
     */
    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $identifier) !== 1) {
            throw new \InvalidArgumentException("Unsafe SQL identifier [{$identifier}].");
        }
    }
}
