<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\User;

/**
 * Who may read a case, and who may settle a leaver's handover from it.
 *
 * **A case is about a person, so reading one is the same action as seeing them** — asked
 * about the department that person was in when the case opened, which is the department
 * the case pinned rather than the one they are in now. Rakesh clearing HR for Shimla does
 * not get to read a Pune exit, and the rule the rest of the product already uses is what
 * says so rather than a new rule invented here.
 *
 * **And a case somebody started is theirs to read, whatever else they may do here.**
 * Anjali holds nothing at all and raises every hiring request, so without this she could
 * have a request turned down with a reason written on it and no screen in the product that
 * says so. It is read off the case rather than asked of the permission rules because it is
 * not a permission: nobody grants it and nobody can take it away. **Except a case about
 * themselves**: Deepak raising his own resignation is told it started and when it ends,
 * never what each department said in between — {@see ProcessCase::scopeRaisedBy()}.
 *
 * A case with no department at all never had one — it is about a vacancy, or about a
 * candidate — and falls back to holding the action anywhere, which is the same answer
 * somebody with no job row already gets on their own record.
 */
class ProcessCasePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    /**
     * Whether there is any point opening the screen at all. Every row is checked again in
     * turn, both by the list's own narrowing and by {@see view()}.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, Permission::ViewPerson)
            || ProcessCase::query()->raisedBy($user)->exists();
    }

    public function view(User $user, ProcessCase $case): bool
    {
        if ($this->theyStartedIt($case, $user)) {
            return true;
        }

        $unit = $case->subjectEmploymentRecord?->org_unit_id;

        return $this->permissions->allows(
            $user,
            Permission::ViewPerson,
            $unit === null ? null : OrgUnit::query()->find($unit),
        );
    }

    /**
     * Settling who takes on a leaver's work.
     *
     * Over the whole client company, for the reason cover is: the grants being moved can
     * cover the whole company themselves, so somebody responsible for one branch would
     * otherwise inherit the finance head's company-wide role by settling her exit.
     *
     * Only on a case about a person, because there is nobody to hand the work on from
     * otherwise — the same thing the record refuses, asked here as a question so the
     * control is not offered where it could only be refused.
     */
    public function settleHandover(User $user, ProcessCase $case): bool
    {
        return $case->subject_user_id !== null
            && $this->permissions->allowsEverywhere($user, Permission::SettleHandover);
    }

    /**
     * Withdrawing an exit, or changing its last working day.
     *
     * Over the department the case pinned, the same question {@see view()} asks, so Rakesh,
     * HR head over Shimla, acts on Deepak's exit and not on Rohit's in Pune. Never on a case
     * about themselves: Rakesh moving his own last working day moves the deadline on his
     * own money. Deepak withdrawing his own resignation is not a permission and is not
     * asked here — the engine allows it until anybody has acted on it.
     */
    public function changeExit(User $user, ProcessCase $case): bool
    {
        if ((int) $case->subject_user_id === (int) $user->getKey()) {
            return false;
        }

        $unit = $case->subjectEmploymentRecord?->org_unit_id;

        return $this->permissions->allows(
            $user,
            Permission::ChangeExit,
            $unit === null ? null : OrgUnit::query()->find($unit),
        );
    }

    /**
     * Closing a held clearance over the department holding it.
     *
     * Over the department the case pinned, like {@see changeExit()}, so Rakesh overrides a
     * hold on Deepak's exit in Shimla and not on Rohit's in Pune. Never on a case about
     * themselves. **Only on a case about a person**: the override exists because a hold must
     * not stop a legal settlement, and a held hiring approval passed over this way would let
     * a hire go ahead that nobody approved.
     */
    public function overrideHold(User $user, ProcessCase $case): bool
    {
        if ($case->subject_user_id === null || (int) $case->subject_user_id === (int) $user->getKey()) {
            return false;
        }

        $unit = $case->subjectEmploymentRecord?->org_unit_id;

        return $this->permissions->allows(
            $user,
            Permission::OverrideHold,
            $unit === null ? null : OrgUnit::query()->find($unit),
        );
    }

    /**
     * Nothing on this screen writes to a case. Deciding a step is the queue's job, and
     * module 12's editor is where a process itself is changed.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProcessCase $case): bool
    {
        return false;
    }

    public function delete(User $user, ProcessCase $case): bool
    {
        return false;
    }

    private function theyStartedIt(ProcessCase $case, User $user): bool
    {
        return $case->initiated_by !== null
            && (int) $case->initiated_by === (int) $user->getKey()
            && (int) $case->subject_user_id !== (int) $user->getKey();
    }
}
