<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Holds the tenant in scope for the current execution path, and keeps the two
 * halves of the wall pointed at the same tenant: the Eloquent scope in the
 * application, and the Postgres policy in the database.
 *
 * The tenant is never derived from the signed-in user. Every entry point sets it
 * explicitly — the web request bridge, a queued job, a scheduled or artisan
 * command, an import, a signed-link portal — because most of those have no signed-in
 * user at all.
 *
 * The two ways of writing the database marker are not interchangeable:
 *
 * - A web request uses plain `SET` ({@see applyWebRequest}). There is no
 *   request-long transaction, deliberately, so that mail and outbound HTTP never
 *   run inside one. A `SET LOCAL` written at request start would end before
 *   Filament's first query and every screen would come back empty.
 * - A job, command or import uses `SET LOCAL` inside its own transaction
 *   ({@see run}), which clears itself when that transaction ends.
 */
class TenantContext
{
    private static ?int $tenantId = null;

    private static bool $crossTenant = false;

    /**
     * Run a callback with one tenant in scope. Safe to nest — the previous scope,
     * in the application and on the connection, is restored afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Tenant|int $tenant, Closure $callback): mixed
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        return self::bind($tenantId, false, $callback);
    }

    /**
     * The audited way across tenants, for the few places that genuinely need it:
     * a migration backfilling existing rows, and SummerHill's own cross-client
     * view. Every use is logged, which is what keeps it rare enough to be a
     * useful signal.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function cross(Closure $callback, ?string $reason = null): mixed
    {
        Log::info('tenant.cross_access', [
            'reason' => $reason,
            'tenant_in_scope' => self::$tenantId,
        ]);

        return self::bind(self::$tenantId, true, $callback);
    }

    /**
     * The tenant in scope, or null when there is none.
     */
    public static function id(): ?int
    {
        return self::$tenantId;
    }

    public static function isCrossTenant(): bool
    {
        return self::$crossTenant;
    }

    /**
     * Forget the tenant in the application. The database marker written by
     * {@see run} clears itself with its transaction; the one written by
     * {@see applyWebRequest} is cleared by {@see resetWebRequest}.
     */
    public static function forget(): void
    {
        self::$tenantId = null;
        self::$crossTenant = false;
    }

    /**
     * Stamp the tenant for a web request, in the application and on the
     * connection, using plain `SET` so the marker lasts the whole request across
     * Filament's many nested transactions.
     *
     * Written fresh at the start of every request, so a connection reused after a
     * previous request cannot inherit its tenant.
     *
     * @param  bool  $crossTenant  true for SummerHill's own cross-client screens,
     *                             where there is no single tenant in scope.
     */
    public static function applyWebRequest(?int $tenantId, bool $crossTenant = false): void
    {
        // Same order as run(): the connection is told first, so a failure cannot leave
        // the application scoped to a client company the database does not know about.
        try {
            self::writeMarkers($tenantId, $crossTenant, local: false);
        } catch (Throwable $e) {
            self::forget();

            throw $e;
        }

        self::$tenantId = $tenantId;
        self::$crossTenant = $crossTenant;
    }

    /**
     * Put the request's marker back to no tenant and no bypass, which the policy
     * reads as deny. Best-effort: a connection already closed at the end of a
     * request must not raise, and the next request writes its own marker first
     * thing anyway.
     */
    public static function resetWebRequest(): void
    {
        self::$tenantId = null;
        self::$crossTenant = false;

        try {
            self::writeMarkers(null, false, local: false);
        } catch (Throwable) {
            // Connection already gone; the next request sets its own marker.
        }
    }

    /**
     * Set the tenant, run the callback, then restore what was in scope before.
     * A transaction is opened when there is none, because `SET LOCAL` needs one.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private static function bind(?int $tenantId, bool $crossTenant, Closure $callback): mixed
    {
        $previousTenantId = self::$tenantId;
        $previousCrossTenant = self::$crossTenant;

        $connection = DB::connection();
        $openedTransaction = false;

        if ($connection->transactionLevel() === 0) {
            $connection->beginTransaction();
            $openedTransaction = true;
        }

        try {
            // The marker goes on the connection before the application believes it is
            // scoped. The other order leaves the application pointed at a client company
            // the database has never been told about, if writing the marker fails.
            self::writeMarkers($tenantId, $crossTenant, local: true);

            self::$tenantId = $tenantId;
            self::$crossTenant = $crossTenant;

            $result = $callback();

            if ($openedTransaction) {
                $connection->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($openedTransaction) {
                $connection->rollBack();
            }

            throw $e;
        } finally {
            self::$tenantId = $previousTenantId;
            self::$crossTenant = $previousCrossTenant;

            // Where we opened the transaction it has now ended, taking its
            // SET LOCAL with it. Where we ran inside someone else's transaction,
            // the markers have to be put back by hand — best-effort, because that
            // transaction may already be aborted after a database error.
            if (! $openedTransaction) {
                try {
                    self::writeMarkers($previousTenantId, $previousCrossTenant, local: true);
                } catch (Throwable) {
                    // The owner of the aborted transaction will roll it back.
                }
            }
        }
    }

    /**
     * Write both session markers onto the connection.
     *
     * @param  bool  $local  true for `SET LOCAL`, which ends with the transaction.
     */
    private static function writeMarkers(?int $tenantId, bool $crossTenant, bool $local): void
    {
        $connection = DB::connection();
        $set = $local ? 'SET LOCAL' : 'SET';

        // Postgres does not accept a bound parameter in SET, so the values are
        // written into the statement. What keeps that safe is the types: the tenant
        // is an int on every path into this method, and the bypass flag is a bool.

        $connection->statement(
            $set.' '.Rls::TENANT_MARKER." = '".($tenantId === null ? '' : $tenantId)."'"
        );

        $connection->statement(
            $set.' '.Rls::BYPASS_MARKER." = '".($crossTenant ? 'on' : 'off')."'"
        );
    }
}
