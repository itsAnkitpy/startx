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

    public const ViewRole = 'view_role';

    /** Editing a role's label and its action list, and granting or revoking it. */
    public const ManageRole = 'manage_role';

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
