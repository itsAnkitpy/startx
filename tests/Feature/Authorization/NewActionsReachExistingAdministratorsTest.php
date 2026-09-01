<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;

/*
| A client company already using the product, and two actions that did not exist when it
| was set up.
|
| A role's actions are rows, so a name added by a later module reaches nobody on its own:
| Chandni would open the designations screen and be refused on her own company. The
| migration this module ships is what puts the two new actions on every administrator role
| that already exists, and this proves it does — on a company built the way one was built
| before this module, with the two rows taken back off it first.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    $this->newActions = [Permission::ManageReferenceList, Permission::ManageWorkingCalendar];
});

/** Run the migration that grants them, the way it runs on a real database: no company in scope. */
function grantTheNewActionsToEveryAdministrator(): void
{
    $migration = require database_path(
        'migrations/2026_09_01_100000_grant_reference_lists_and_the_working_calendar_to_existing_administrators.php'
    );

    $migration->up();
}

it('puts both new actions on an administrator role that predates them', function () {
    $chandni = TenantContext::run($this->meridian, function () {
        $administrator = Role::query()->where('key', Role::AdministratorKey)->sole();

        // Wind Meridian back to how it was set up before this module existed.
        $administrator->permissions()->whereIn('permission', $this->newActions)->delete();

        return User::query()->where('work_email', 'chandni@meridian.test')->sole();
    });

    $resolver = app(PermissionResolver::class);

    TenantContext::run($this->meridian, function () use ($resolver, $chandni) {
        expect($resolver->allows($chandni, Permission::ManageReferenceList))->toBeFalse()
            ->and($resolver->allows($chandni, Permission::ManageWorkingCalendar))->toBeFalse();
    });

    grantTheNewActionsToEveryAdministrator();

    $resolver->forget();

    TenantContext::run($this->meridian, function () use ($resolver, $chandni) {
        expect($resolver->allows($chandni, Permission::ManageReferenceList))->toBeTrue()
            ->and($resolver->allows($chandni, Permission::ManageWorkingCalendar))->toBeTrue();
    });
});

it('leaves every other role alone', function () {
    TenantContext::run($this->meridian, function () {
        Role::query()->whereNot('key', Role::AdministratorKey)->get()
            ->each(fn (Role $role) => $role->permissions()->whereIn('permission', $this->newActions)->delete());
    });

    grantTheNewActionsToEveryAdministrator();

    TenantContext::run($this->meridian, function () {
        // Rakesh's HR head role runs hiring and exits for one branch. Nothing about that
        // is keeping the company's own lists, and a migration that swept every role would
        // have handed it to him.
        $hrHead = Role::query()->where('key', 'hr_head')->sole();

        expect($hrHead->permissions()->whereIn('permission', $this->newActions)->exists())->toBeFalse();
    });
});

it('runs twice without complaining', function () {
    grantTheNewActionsToEveryAdministrator();
    grantTheNewActionsToEveryAdministrator();

    TenantContext::run($this->meridian, function () {
        $administrator = Role::query()->where('key', Role::AdministratorKey)->sole();

        expect($administrator->permissions()->whereIn('permission', $this->newActions)->count())->toBe(2);
    });
});
