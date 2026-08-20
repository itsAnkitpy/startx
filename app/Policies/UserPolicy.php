<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\User;

/**
 * A person's own position in the structure arrives in step 4, on their dated
 * employment record. Until then these questions cannot be narrowed to one branch, so
 * they ask only whether the person holds the action at all. When employment records
 * land, the unit is passed here the same way it already is for a structure unit, and
 * no caller changes.
 */
class UserPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ViewPerson);
    }

    public function view(User $user, User $subject): bool
    {
        return $this->permissions->allows($user, Permission::ViewPerson);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, Permission::CreatePerson);
    }

    public function update(User $user, User $subject): bool
    {
        return $this->permissions->allows($user, Permission::UpdatePerson);
    }

    /**
     * Nobody, at any permission level. A person's record is the evidence behind their
     * exit, their settlement and any dispute that follows, and a disputed settlement
     * line is argued after they have gone. An account that should no longer sign in is
     * deactivated, which is what {@see deactivate} covers.
     */
    public function delete(User $user, User $subject): bool
    {
        return false;
    }

    public function deactivate(User $user, User $subject): bool
    {
        return $this->permissions->allows($user, Permission::DeactivatePerson);
    }
}
