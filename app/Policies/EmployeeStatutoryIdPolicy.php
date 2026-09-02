<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\EmployeeStatutoryId;
use App\Models\User;

/**
 * A person's tax, provident-fund, bank and passport numbers.
 *
 * The split between opening the list and reading a value is deliberate, and it is what
 * rule 3 of this module is for. Somebody who may see a person's record sees *which*
 * numbers are on file and the withheld marker in place of each one, because "no tax number
 * on file" and "not yours to see" looking identical is how a number ends up entered twice.
 * Reading the number itself, adding one and removing one all need
 * {@see Permission::ViewStatutoryId}, which a client ticks on for whoever actually hands
 * data to payroll.
 *
 * Adding needs the reading action rather than the person-editing one for a plain reason:
 * anybody entering a tax number has to be able to check the one already there, and
 * somebody who cannot read it cannot tell a correction from a second copy.
 */
class EmployeeStatutoryIdPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ViewPerson);
    }

    public function view(User $user, EmployeeStatutoryId $identifier): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Asked before the number exists, so there is no person here to narrow it to — the
     * screen that knows whose file is open narrows it there, the way rule 1 of this
     * module says a create always has to.
     */
    public function create(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ViewStatutoryId);
    }

    /**
     * Nobody, and for a reason the screen depends on: an edit form fills its boxes from
     * the record itself, which would hand over a value the record's own reader was built
     * to withhold. A wrong number is removed and entered again.
     */
    public function update(User $user, EmployeeStatutoryId $identifier): bool
    {
        return false;
    }

    /**
     * Removing one takes nothing out of anybody's history — no case, letter or job row
     * keeps a copy of a tax or bank number, unlike a designation's words. So a client who
     * typed the wrong one can take it out again, which is the same answer an office's
     * closed dates got for the same reason.
     *
     * Narrowed to the part of the structure the person belongs to, exactly as
     * {@see EmployeeStatutoryId::valueFor()} narrows reading the value. Asked without
     * that, somebody granted the tax-number action over one branch and the person-keeping
     * action over another could open a person in the second branch, be told each number
     * was on file and not theirs to see, and delete it anyway.
     */
    public function delete(User $user, EmployeeStatutoryId $identifier): bool
    {
        return $this->permissions->allows(
            $user,
            Permission::ViewStatutoryId,
            $identifier->user?->lastKnownOrgUnit(),
        );
    }
}
