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
        return $this->permissions->allowsEverywhere($user, Permission::ManageRole);
    }

    /**
     * Opening a role's own page, which is where the people who hold it are listed.
     * Asked about the action anywhere at all, because somebody responsible for one
     * branch does need to get to that list to hand the role out over their branch.
     * What they may change once they are there is {@see changeWhatItCanDo()}.
     */
    public function update(User $user, Role $role): bool
    {
        return $this->permissions->allows($user, Permission::ManageRole);
    }

    /**
     * Changing what a role is called and what it can do.
     *
     * A role's list of actions is one list for the whole client company: everybody who
     * holds the role gets what is ticked, wherever they hold it. So changing it needs a
     * grant covering the whole company. Without that, somebody made responsible for
     * roles in one branch could tick "read tax and bank numbers" onto a role held
     * company-wide — including onto a role they hold themselves — and walk out of their
     * own branch that way.
     */
    public function changeWhatItCanDo(User $user, Role $role): bool
    {
        return $this->permissions->allowsEverywhere($user, Permission::ManageRole);
    }

    /**
     * A seeded role is refused by the model as well, because deleting one takes its
     * grants with it through a database cascade that never reaches the
     * two-administrator rule.
     */
    public function delete(User $user, Role $role): bool
    {
        return ! $role->is_system
            && $this->permissions->allowsEverywhere($user, Permission::ManageRole);
    }
}
