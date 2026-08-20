<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\User;

/**
 * A question about one person is narrowed to the part of the structure that person
 * belongs to, which their most recent employment record says. So an HR head
 * responsible for one branch is answered no about somebody in another branch, rather
 * than yes because they hold the action somewhere.
 *
 * Their most recent row rather than the row that is true today, because a leaver has
 * no row that is true today. Reading only the current one widened every leaver's file
 * to anybody holding the action in any branch, on the day they left — and a leaver's
 * file is the one this product exists to protect.
 *
 * Somebody who has never held a job row has no place in the structure yet, and the
 * question falls back to whether the asker holds the action anywhere at all. That is
 * the state every account is in before their first employment row is written, so it
 * cannot be a refusal.
 *
 * Two questions here cannot be narrowed and are not:
 * {@see viewAny} opens a list, and the rows in the list are each checked in turn.
 * {@see create} is asked without a record, so the screen has to ask about the target
 * branch before it writes. Both are written into module 12.
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
        return $this->permissions->allows($user, Permission::ViewPerson, $subject->lastKnownOrgUnit());
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, Permission::CreatePerson);
    }

    public function update(User $user, User $subject): bool
    {
        return $this->permissions->allows($user, Permission::UpdatePerson, $subject->lastKnownOrgUnit());
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
        return $this->permissions->allows($user, Permission::DeactivatePerson, $subject->lastKnownOrgUnit());
    }
}
