<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\EmploymentRecord;
use App\Models\User;

/**
 * A person's job history, which is reached only through that person's own page.
 *
 * None of these is narrowed to a part of the structure, and that is not the gap it looks
 * like. A job row is only ever reached through the person it belongs to, and
 * {@see UserPolicy} narrows that question to the part of the structure the person sits in
 * — so somebody responsible for one branch cannot open a person in another branch, and
 * therefore cannot reach their history either. The row's own department is checked on the
 * form, which is rule 1 of this module's three.
 *
 * Adding a row is {@see Permission::UpdatePerson} rather than a name of its own, because
 * recording a promotion or a transfer *is* changing somebody's record — the same action a
 * client already ticks on to let somebody keep people up to date.
 */
class EmploymentRecordPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ViewPerson);
    }

    public function view(User $user, EmploymentRecord $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, Permission::UpdatePerson);
    }

    /**
     * Nobody, and this is the point of the screen rather than an omission.
     *
     * Editing a row in place is the act this screen exists not to offer: it rewrites what
     * a case already decided was true on that day. A job that changed is a new row, and a
     * row entered by mistake is withdrawn with a reason. Workday came to the same
     * conclusion and stopped letting its own administrators correct a job change after the
     * event, because the correction moves payroll and reporting with it.
     */
    public function update(User $user, EmploymentRecord $record): bool
    {
        return false;
    }

    /**
     * Nobody, ever. A row is withdrawn, which keeps it and records who withdrew it and
     * why — see {@see withdraw} and {@see EmploymentRecord::withdraw()}.
     */
    public function delete(User $user, EmploymentRecord $record): bool
    {
        return false;
    }

    public function withdraw(User $user, EmploymentRecord $record): bool
    {
        return $this->permissions->allows($user, Permission::UpdatePerson);
    }
}
