<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\OfficeHoliday;
use App\Models\User;

/**
 * The dates an office is closed. Guarded under the working calendar rather than the
 * reference lists, because these are the rows a statutory deadline is counted against.
 *
 * Deleting one is allowed here, unlike a designation or an office. Nothing freezes a
 * copy of a holiday — a case's deadline is worked out once when it opens and never
 * recomputed — so removing one takes nothing out of anybody's history, and a client who
 * typed the wrong date has to be able to take it out again.
 */
class OfficeHolidayPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ManageWorkingCalendar);
    }

    public function view(User $user, OfficeHoliday $holiday): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, OfficeHoliday $holiday): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, OfficeHoliday $holiday): bool
    {
        return $this->viewAny($user);
    }
}
