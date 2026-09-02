<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\RoleAssignment;
use App\Models\User;

/**
 * Granting and revoking is itself scoped to the structure: somebody who manages roles
 * for one branch cannot hand out roles in another. The grant's own unit is what the
 * question is asked about, which is why these three methods pass it.
 */
class RoleAssignmentPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ViewRole);
    }

    public function view(User $user, RoleAssignment $assignment): bool
    {
        return $this->permissions->allows($user, Permission::ViewRole, $assignment->orgUnit);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ManageRole);
    }

    public function update(User $user, RoleAssignment $assignment): bool
    {
        return $this->mayHandOutOrTakeAway($user, $assignment);
    }

    public function delete(User $user, RoleAssignment $assignment): bool
    {
        return $this->mayHandOutOrTakeAway($user, $assignment);
    }

    /**
     * A grant naming no part of the company covers all of it, and a grant like that is
     * only given and taken away by somebody whose own grant covers the whole company.
     *
     * Asking the resolver about no part of the company is asking "do they hold this
     * action anywhere" — the right question for opening a screen and the wrong one here.
     * It let somebody responsible for roles in one branch take the finance head's
     * company-wide role away, from a row the list shows them on purpose.
     */
    private function mayHandOutOrTakeAway(User $user, RoleAssignment $assignment): bool
    {
        return $assignment->org_unit_id === null
            ? $this->permissions->allowsEverywhere($user, Permission::ManageRole)
            : $this->permissions->allows($user, Permission::ManageRole, $assignment->orgUnit);
    }
}
