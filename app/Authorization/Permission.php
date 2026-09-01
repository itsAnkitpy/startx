<?php

namespace App\Authorization;

use App\Models\Role;

/**
 * Every action a person can be granted. Constants rather than rows, because a name
 * only means something if there is code behind it performing the action — a
 * client-invented name could never do anything. Held as constants so a typo is a
 * broken build rather than a silent denial.
 *
 * The flexibility clients actually want is in combining these freely into roles they
 * name themselves, which is what {@see Role} holds.
 *
 * Names read as verb then thing, not as a screen name: a screen name stops being true
 * the first time the screen is redesigned or split in two.
 *
 * This list grows with the modules. Only actions with code behind them belong here —
 * adding a name for a module that has not been built yet gives a client a tick-box
 * that does nothing.
 */
final class Permission
{
    public const ViewOrgUnit = 'view_org_unit';

    public const CreateOrgUnit = 'create_org_unit';

    public const UpdateOrgUnit = 'update_org_unit';

    public const DeleteOrgUnit = 'delete_org_unit';

    public const ViewPerson = 'view_person';

    public const CreatePerson = 'create_person';

    public const UpdatePerson = 'update_person';

    public const DeactivatePerson = 'deactivate_person';

    /**
     * Reading a person's tax, provident-fund, bank or passport identifiers. Separate
     * from {@see ViewPerson} on purpose: these are the fields a client is legally
     * exposed by, so seeing somebody's record is not the same as seeing their bank
     * account. Deliberately not on the seeded HR Head role — a client ticks it on for
     * whoever actually hands data to payroll.
     */
    public const ViewStatutoryId = 'view_statutory_id';

    public const ViewRole = 'view_role';

    /** Editing a role's label and its action list, and granting or revoking it. */
    public const ManageRole = 'manage_role';

    /**
     * Changing the switches the company runs on — the salary above which a hire needs
     * the director, who picks up a step nobody holds. Separate from {@see ManageRole}
     * because these change what happens on every case from now on rather than what one
     * person may do, and a company may well want the second in more hands than the first.
     */
    public const ManageSettings = 'manage_settings';

    /**
     * Editing the two lists a client keeps beside their structure — their designations
     * and their offices. One name for both, because four names each would give a client
     * twelve tick-boxes for two short lists nobody guards separately.
     *
     * Reading them needs nothing: a designation and an office are already shown to
     * whoever is filling in a form that picks one.
     */
    public const ManageReferenceList = 'manage_reference_list';

    /**
     * An office's working calendar — the weekdays it does not work, and the dates it is
     * closed. Apart from {@see ManageReferenceList} because these are the only two
     * fields on an office whose mistakes move a legal deadline, so a client may well
     * want them in fewer hands than the office's name and address.
     */
    public const ManageWorkingCalendar = 'manage_working_calendar';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        /** @var list<string> $names */
        $names = array_values((new \ReflectionClass(self::class))->getConstants());

        return $names;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
