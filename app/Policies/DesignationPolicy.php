<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\Designation;
use App\Models\User;

/**
 * Every question is about an action, never about a role.
 *
 * No part of the structure is passed to any of these, unlike {@see OrgUnitPolicy}: a
 * designation belongs to the client company rather than to one of its branches, so
 * there is nothing for a grant over one branch to narrow. Rule 2 of this module's three
 * screen rules — narrow every list's rows — has nothing to narrow here for the same
 * reason.
 */
class DesignationPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ManageReferenceList);
    }

    public function view(User $user, Designation $designation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Designation $designation): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Nobody, ever. {@see Designation} throws rather than deleting, because a job row
     * keeps its own copy of the words it read and a delete would take a designation out
     * of somebody's history. A designation that is finished with is switched off.
     *
     * Answered here as well as refused there so the screen never offers the button, and
     * the panel's strict authorization has a method to call rather than throwing.
     */
    public function delete(User $user, Designation $designation): bool
    {
        return false;
    }
}
