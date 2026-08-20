<?php

namespace App\Authorization;

use App\Models\Role;

/**
 * The roles a client company starts with, which they then rename, extend and prune.
 * This is where "a new client is configuration rather than a fork" is actually cashed
 * in: none of these labels means anything to any permission check, so Meridian
 * calling theirs "People Lead" and Vertex calling theirs "HR Manager" changes no
 * answer anywhere.
 *
 * Until the tick-box screen exists (module 12), this is the only thing writing a
 * role's action list.
 *
 * Runs against the client company in scope — every row is stamped from it rather than
 * passed in.
 *
 * One known cost of holding actions as rows: a permission added by a later module is
 * not automatically on any existing client's administrator role. That module grants it
 * to every seeded administrator role in its own migration. The alternative — letting
 * the administrator role skip the check because of its name — is the exact rule this
 * module refuses, because it is what makes role names load-bearing in code.
 */
class StarterRoles
{
    /**
     * @return array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            Role::AdministratorKey => [
                'name' => 'Administrator',
                'description' => 'Full access, including roles and who holds them. A company keeps at least two.',
                'permissions' => Permission::all(),
            ],
            'hr_head' => [
                'name' => 'HR Head',
                'description' => 'Runs hiring, onboarding and exits for the part of the company they are given.',
                'permissions' => [
                    Permission::ViewPerson,
                    Permission::CreatePerson,
                    Permission::UpdatePerson,
                    Permission::DeactivatePerson,
                    Permission::ViewOrgUnit,
                    Permission::ViewRole,
                ],
            ],
            'finance_approver' => [
                'name' => 'Finance Approver',
                'description' => 'Approves money on a settlement — recoveries and payables.',
                'permissions' => [
                    Permission::ViewPerson,
                    Permission::ViewOrgUnit,
                ],
            ],
            'manager' => [
                'name' => 'Manager',
                'description' => 'Approves for their own team, and clears their own department on an exit.',
                'permissions' => [
                    Permission::ViewPerson,
                    Permission::ViewOrgUnit,
                ],
            ],
            'employee' => [
                'name' => 'Employee',
                'description' => 'Their own record and their own requests. Seeing your own details is not a granted action.',
                'permissions' => [],
            ],
        ];
    }

    /**
     * Seed the starter roles for the client company in scope. Safe to run twice.
     *
     * @return array<string, Role> keyed by permanent internal name
     */
    public static function seed(): array
    {
        $roles = [];

        foreach (self::definitions() as $key => $definition) {
            $role = Role::query()->firstOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                ],
            );

            // Not a field a form may fill, so it is set here rather than passed in.
            if (! $role->is_system) {
                $role->forceFill(['is_system' => true])->save();
            }

            foreach ($definition['permissions'] as $permission) {
                $role->permissions()->firstOrCreate(['permission' => $permission]);
            }

            $roles[$key] = $role;
        }

        return $roles;
    }
}
