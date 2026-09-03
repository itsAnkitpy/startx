<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\Delegation;
use App\Models\User;

/**
 * Every question is about an action, never about a role.
 *
 * **And every question is asked about the whole client company**, which is the one thing
 * this policy does differently from the others. A cover names two people and no part of
 * the structure, so there is nothing for a grant over one branch to be checked against —
 * and the act itself is handing one person's approvals to another, which must not be
 * reachable from a corner of the company. Somebody responsible for the Shimla branch
 * would otherwise name themselves as cover for the finance head and collect her
 * approvals, and no column on the row would have been wrong.
 *
 * This is the same conclusion the roles screen reached on review: an action whose effect
 * is company-wide needs a grant that is company-wide. Rule 2 of this module's three screen
 * rules — narrow every list's rows — has nothing to narrow here for the same reason, since
 * everybody who reaches the screen covers the whole company already.
 */
class DelegationPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allowsEverywhere($user, Permission::ManageCover);
    }

    public function view(User $user, Delegation $cover): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Delegation $cover): bool
    {
        return $this->viewAny($user);
    }

    /**
     * A cover is deleted rather than switched off, unlike a designation. Nothing keeps a
     * copy of what it said and nothing in anybody's history points at it — resolution
     * reads it live and reads nothing else — so removing one that was set by mistake, or
     * whose leave was cancelled, takes nothing out of the record.
     */
    public function delete(User $user, Delegation $cover): bool
    {
        return $this->viewAny($user);
    }
}
