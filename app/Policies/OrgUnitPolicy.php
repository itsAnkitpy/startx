<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\OrgUnit;
use App\Models\User;

/**
 * Every question is about an action, never about a role. A check written as "is this
 * person the HR head" breaks for the first client who renames that role, and breaks
 * worse for the first client who splits the job across two roles.
 *
 * Where a unit is passed, the answer depends on where in the structure the record
 * sits: a grant on one branch does not reach another, and only reaches below itself
 * when the grant says so.
 */
class OrgUnitPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ViewOrgUnit);
    }

    public function view(User $user, OrgUnit $unit): bool
    {
        return $this->permissions->allows($user, Permission::ViewOrgUnit, $unit);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, Permission::CreateOrgUnit);
    }

    public function update(User $user, OrgUnit $unit): bool
    {
        return $this->permissions->allows($user, Permission::UpdateOrgUnit, $unit);
    }

    public function delete(User $user, OrgUnit $unit): bool
    {
        return $this->permissions->allows($user, Permission::DeleteOrgUnit, $unit);
    }
}
