<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ViewRole);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->permissions->allows($user, Permission::ViewRole);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ManageRole);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->permissions->allows($user, Permission::ManageRole);
    }

    /**
     * A seeded role is refused by the model as well, because deleting one takes its
     * grants with it through a database cascade that never reaches the
     * two-administrator rule.
     */
    public function delete(User $user, Role $role): bool
    {
        return ! $role->is_system
            && $this->permissions->allows($user, Permission::ManageRole);
    }
}
