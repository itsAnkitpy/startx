<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\Office;
use App\Models\User;

/**
 * Every question is about an action, never about a role.
 *
 * This class answers only for the office as a record — its name, country, state and
 * address, which are ordinary list keeping. The weekdays it does not work are the other
 * half of the calendar {@see OfficeHolidayPolicy} guards, so that one field on the form
 * asks the working-calendar question for itself and disappears for somebody who does not
 * hold it.
 *
 * No part of the structure is passed to any of these: an office belongs to the client
 * company rather than to one of its branches.
 */
class OfficePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ManageReferenceList);
    }

    public function view(User $user, Office $office): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Office $office): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Nobody, ever, for the same reason as a designation: {@see Office} throws rather
     * than deleting, because a job row keeps its own copy of the country and state it
     * read. An office that is closed is switched off.
     */
    public function delete(User $user, Office $office): bool
    {
        return false;
    }
}
