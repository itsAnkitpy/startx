<?php

namespace App\Authorization;

use App\Models\Role;
use App\Policies\DelegationPolicy;

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
     * Setting who holds somebody's approvals while they are away.
     *
     * A cover names two people and no part of the company, so unlike every other action
     * here there is nothing for a grant over one branch to narrow it down to — and
     * handing one person's approvals to another is exactly the act that must not be
     * reachable from a corner of the company. {@see DelegationPolicy} asks
     * for it over the whole company for that reason, the same way changing what a role
     * can do does.
     */
    public const ManageCover = 'manage_cover';

    /**
     * The same actions, in the words a client reads on the roles screen, grouped by the
     * thing each one is about.
     *
     * Deliberately a method rather than constants: {@see all()} reads this class's
     * constants by reflection, so anything added there becomes a permission name, and an
     * array of labels sitting among them would be handed to the roles screen as an action
     * to grant. A test checks that every name in {@see all()} appears here, so a name
     * added by a later module without words is a failing test rather than a blank line on
     * a client's screen.
     *
     * The words are here rather than in the screen because this is the file somebody edits
     * when they add a name, and the two belong within a few lines of each other.
     *
     * @return list<array{key: string, heading: string, description: string, actions: array<string, array{label: string, description: string}>}>
     */
    public static function describedForAClient(): array
    {
        return [
            [
                'key' => 'structure',
                'heading' => 'Departments and branches',
                'description' => 'Your company structure — the screen that lists every department and branch.',
                'actions' => [
                    self::ViewOrgUnit => [
                        'label' => 'See the departments and branches',
                        'description' => 'Somebody sees only the parts of the company their own grant covers.',
                    ],
                    self::CreateOrgUnit => [
                        'label' => 'Add a department or branch',
                        'description' => 'Only under a part of the company they already cover.',
                    ],
                    self::UpdateOrgUnit => [
                        'label' => 'Rename one, move it, or archive it',
                        'description' => 'Archiving stops it being offered on new records and leaves everything already recorded against it alone.',
                    ],
                    self::DeleteOrgUnit => [
                        'label' => 'Delete one outright',
                        'description' => 'Nothing on the screens does this today — a department that is finished with is archived instead, because deleting one would take every job, grant and case that named it with it. Ticking this changes nothing yet.',
                    ],
                ],
            ],
            [
                'key' => 'people',
                'heading' => 'People and their records',
                'description' => 'The people screen, the jobs each person has held, and the numbers on file for them.',
                'actions' => [
                    self::ViewPerson => [
                        'label' => 'See the people at the company',
                        'description' => 'Only the people in the parts of the company their own grant covers.',
                    ],
                    self::CreatePerson => [
                        'label' => 'Add somebody',
                        'description' => 'A joiner, with the details they sign in with.',
                    ],
                    self::UpdatePerson => [
                        'label' => "Change somebody's details, and record a change to their job",
                        'description' => 'A promotion or a move adds a dated row. Nothing already recorded is rewritten.',
                    ],
                    self::DeactivatePerson => [
                        'label' => 'Stop somebody signing in',
                        'description' => 'For a leaver. Their record and their whole history stay exactly as they are.',
                    ],
                    self::ViewStatutoryId => [
                        'label' => "Read somebody's tax and bank numbers",
                        'description' => 'Kept apart from seeing their record on purpose: these are the numbers your company is legally exposed by. Tick it for whoever actually hands details to payroll. Without it the numbers read as withheld rather than as empty.',
                    ],
                ],
            ],
            [
                'key' => 'lists',
                'heading' => "Your company's own lists",
                'description' => 'The short lists you keep beside your structure, and the calendar your deadlines are counted against.',
                'actions' => [
                    self::ManageReferenceList => [
                        'label' => 'Keep the designations and offices up to date',
                        'description' => 'Adding, renaming and retiring the jobs your people hold and the offices they work from. Reading them needs nothing — they are already shown to anybody filling in a form that picks one.',
                    ],
                    self::ManageWorkingCalendar => [
                        'label' => "Set an office's working calendar",
                        'description' => 'The weekdays an office does not work and the dates it is closed. These are the two fields whose mistakes move a legal deadline.',
                    ],
                ],
            ],
            [
                'key' => 'control',
                'heading' => 'Roles, cover and company settings',
                'description' => 'Who can do what, who stands in when somebody is away, and the switches every case is decided against.',
                'actions' => [
                    self::ViewRole => [
                        'label' => 'See the list of your roles',
                        'description' => 'The names, what each is for, how many actions each carries and how many people hold it. Opening a role to read what it can do, or to see who holds it, needs the one below as well.',
                    ],
                    self::ManageRole => [
                        'label' => 'Change what a role can do, and grant it or take it away',
                        'description' => 'The strongest one here: somebody with this can give themselves anything else on this screen, over any part of the company they cover. Granted over one branch it hands roles out in that branch only — changing what a role can do needs it over your whole company, because a role\'s actions apply wherever anybody holds it. Your Administrator role always keeps this one and the one above it, so nobody can lock your company out of its own roles.',
                    ],
                    self::ManageCover => [
                        'label' => 'Set who covers somebody while they are away',
                        'description' => 'Naming a stand-in hands one person\'s approvals to another for a fortnight, so this one only counts when it covers your whole company — a grant over a single branch does not give it. Anything the person away has already opened stays with them.',
                    ],
                    self::ManageSettings => [
                        'label' => 'Change the company settings',
                        'description' => 'The salary above which a hire needs the director, and who picks up a step nobody holds. These change what happens on every case from now on.',
                    ],
                ],
            ],
        ];
    }

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
