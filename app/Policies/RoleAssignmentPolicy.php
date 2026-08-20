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
        return $this->permissions->allows($user, Permission::ManageRole, $assignment->orgUnit);
    }

    public function delete(User $user, RoleAssignment $assignment): bool
    {
        return $this->permissions->allows($user, Permission::ManageRole, $assignment->orgUnit);
    }
}
