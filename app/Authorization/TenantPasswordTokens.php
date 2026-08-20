<?php

namespace App\Authorization;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissing;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Database\Query\Builder;

/**
 * Laravel's own reset store, with the client company added to every query.
 *
 * Read in the framework's source on 19 and 20 August 2026: its store keys the table on
 * the email address alone, and all three places it looks — checking a token, checking
 * the throttle, and deleting an existing token — query the address with no client named.
 * Finding the account, by contrast, goes through the person model and so is correctly
 * narrowed by the subdomain. Two consequences, and neither needs an attacker to be
 * clever:
 *
 * - Priya has an account at Meridian and an account at Vertex on the same address. A
 *   reset link issued at Meridian is accepted at Vertex and changes the Vertex
 *   password.
 * - Priya asks for a reset at Meridian while a Vertex link is still pending. The
 *   Vertex link silently stops working.
 *
 * Two overrides close both: every read and delete carries the client company, and
 * every row written carries it too. Auth0 documents the same case as unresolvable
 * without extra identifying information and recommends this shape of fix.
 */
class TenantPasswordTokens extends DatabaseTokenRepository
{
    /**
     * Every read, update and delete the parent performs goes through here, so adding
     * the client company once covers checking a token, checking the throttle, and
     * deleting a pending one.
     */
    protected function getTable(): Builder
    {
        // Stepping aside inside an audited cross-company block, exactly as the Eloquent
        // scope does. That block is how housekeeping below reaches every company's rows.
        if (TenantContext::isCrossTenant()) {
            return parent::getTable();
        }

        return parent::getTable()->where('tenant_id', $this->tenantId());
    }

    /**
     * An insert ignores a `where`, so the row being written needs the client company
     * put on it explicitly.
     *
     * @return array<string, mixed>
     */
    protected function getPayload($email, #[\SensitiveParameter] $token): array
    {
        return ['tenant_id' => $this->tenantId()] + parent::getPayload($email, $token);
    }

    /**
     * Housekeeping across every client company, so it deliberately does not go through
     * the narrowed query above. Scoped, it would delete only the client in scope — and
     * called with no client in scope at all it would match nothing and report success,
     * which is the silent-success shape this module keeps running into.
     *
     * Expired tokens are refused on their own age when checked, so this is hygiene
     * rather than a control.
     */
    public function deleteExpired(): void
    {
        TenantContext::cross(
            fn () => parent::deleteExpired(),
            reason: 'password reset token housekeeping across all client companies',
        );
    }

    private function tenantId(): int
    {
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            throw TenantContextMissing::forModel(self::class);
        }

        return $tenantId;
    }
}
