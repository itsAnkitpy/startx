<?php

namespace App\Authorization;

use App\Exceptions\TooFewAdministrators;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;

/**
 * A client company keeps at least two administrators, and this refuses the changes
 * that would take it below that.
 *
 * Two is a consequence rather than a preference. We deliberately do not build a
 * platform rescue path for a locked-out client — a mechanism whose only purpose is
 * bypassing a client's own controls is worse than the lockout it prevents — so the
 * lockout has to be made impossible instead. One administrator is enough only for a
 * product that can rescue a customer. Security guidance for emergency accounts
 * recommends two with independent credentials for the same reason.
 *
 * What is deliberately *not* refused here is deactivating an account. An exit carries
 * a two-working-day statutory clock and must never be held up to protect an
 * administrator count; module 07's exit flow gains a step that makes a departing
 * administrator appoint a replacement, which surfaces the same problem weeks earlier
 * and blocks the clearance rather than the person's last working day.
 *
 * A client newly created and sitting at one administrator is not an error either —
 * that would break setting a client up on their first day. It is a prompt to appoint
 * a second. Only a removal is refused.
 */
class AdministratorFloor
{
    /** The floor itself. */
    public const Minimum = 2;

    /**
     * Refuse taking a grant away, or moving it off the administrator role, when doing
     * so would leave the client company with fewer than two administrators.
     */
    public static function refuseRemoval(RoleAssignment $assignment): void
    {
        // It is the role the grant is moving *away from* that decides whether this is a
        // demotion at all, so on an edit the original value is the one to read.
        $roleId = $assignment->isDirty('role_id')
            ? (int) $assignment->getOriginal('role_id')
            : (int) $assignment->role_id;

        if (! self::isAdministratorRole($roleId)) {
            return;
        }

        // Handing a grant to somebody else who can sign in and is not an administrator
        // already leaves the count exactly where it was — one name swapped for another.
        // That is what happens when a departing administrator's work is handed to their
        // successor, and refusing it would block the one exit that most needs authority
        // moved on. A grant moved onto an account that cannot sign in, or onto somebody
        // who is an administrator already, does take one away: both fall through to the
        // count below.
        if ($assignment->isDirty('user_id')
            && ! $assignment->isDirty('role_id')
            && self::wouldBeANewAdministrator((int) $assignment->user_id)) {
            return;
        }

        $remaining = self::countExcluding(exceptAssignmentId: (int) $assignment->getKey());

        if ($remaining < self::Minimum) {
            throw TooFewAdministrators::onRemoval($remaining);
        }
    }

    /**
     * The same rule for deleting an account outright. It needs its own call because the
     * grant rows go with the account through a database cascade, which no model event
     * ever sees — the shape of GitLab's own open bug, where a group's last owner can be
     * removed if the account is deactivated first.
     */
    public static function refuseAccountDeletion(User $user): void
    {
        $holdsAdministrator = RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->whereIn('role_id', self::administratorRoleIds())
            ->exists();

        if (! $holdsAdministrator) {
            return;
        }

        $remaining = self::countExcluding(exceptUserId: (int) $user->getKey());

        if ($remaining < self::Minimum) {
            throw TooFewAdministrators::onAccountDeletion($remaining);
        }
    }

    /**
     * How many people in the client company in scope would still be administrators,
     * counting only accounts that can actually sign in.
     */
    public static function count(): int
    {
        return self::countExcluding();
    }

    private static function countExcluding(?int $exceptAssignmentId = null, ?int $exceptUserId = null): int
    {
        $query = RoleAssignment::query()
            ->join('roles', function (JoinClause $join): void {
                $join->on('roles.id', '=', 'role_assignments.role_id')
                    ->on('roles.tenant_id', '=', 'role_assignments.tenant_id');
            })
            ->join('users', function (JoinClause $join): void {
                $join->on('users.id', '=', 'role_assignments.user_id')
                    ->on('users.tenant_id', '=', 'role_assignments.tenant_id');
            })
            ->where('roles.key', Role::AdministratorKey)
            ->where('users.active', true);

        if ($exceptAssignmentId !== null) {
            $query->where('role_assignments.id', '!=', $exceptAssignmentId);
        }

        if ($exceptUserId !== null) {
            $query->where('role_assignments.user_id', '!=', $exceptUserId);
        }

        // One person may hold the administrator role over two different units and is
        // still one administrator.
        return $query->distinct()->count('role_assignments.user_id');
    }

    /**
     * Whether handing a grant to this account would add an administrator the client did
     * not already have.
     */
    private static function wouldBeANewAdministrator(int $userId): bool
    {
        $canSignIn = User::query()->whereKey($userId)->where('active', true)->exists();

        return $canSignIn && ! RoleAssignment::query()
            ->where('user_id', $userId)
            ->whereIn('role_id', self::administratorRoleIds())
            ->exists();
    }

    private static function isAdministratorRole(int $roleId): bool
    {
        return in_array($roleId, self::administratorRoleIds(), true);
    }

    /**
     * @return list<int>
     */
    private static function administratorRoleIds(): array
    {
        return Role::query()
            ->where('key', Role::AdministratorKey)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
