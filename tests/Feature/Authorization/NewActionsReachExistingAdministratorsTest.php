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

/*
| And the same cost paid a third time, by module 05.2's step 6.
|
| Its own migration is separate because it ships with the cover screen rather than with the
| designations and offices screens, and because it takes the action back off only the
| Administrator roles it put it on — the migration above sweeps every role that holds
| either of its two, which was noted at the time as worth not repeating.
*/

/** Run the cover migration the way it runs on a real database: no company in scope. */
function grantSettingCoverToEveryAdministrator(): void
{
    $migration = require database_path(
        'migrations/2026_09_03_100000_grant_setting_cover_to_existing_administrators.php'
    );

    $migration->up();
}

it('puts setting cover on an administrator role that predates it', function () {
    $chandni = TenantContext::run($this->meridian, function () {
        $administrator = Role::query()->where('key', Role::AdministratorKey)->sole();

        // Wind Meridian back to how it was set up before the cover screen existed.
        $administrator->permissions()->where('permission', Permission::ManageCover)->delete();

        return User::query()->where('work_email', 'chandni@meridian.test')->sole();
    });

    $resolver = app(PermissionResolver::class);

    TenantContext::run($this->meridian, function () use ($resolver, $chandni) {
        expect($resolver->allows($chandni, Permission::ManageCover))->toBeFalse();
    });

    grantSettingCoverToEveryAdministrator();

    $resolver->forget();

    TenantContext::run($this->meridian, function () use ($resolver, $chandni) {
        // Over the whole company, which is what the cover screen asks for.
        expect($resolver->allowsEverywhere($chandni, Permission::ManageCover))->toBeTrue();
    });
});

it('leaves a role a client ticked it onto alone when it is rolled back', function () {
    $hrHead = TenantContext::run($this->meridian, function () {
        $role = Role::query()->where('key', 'hr_head')->sole();

        // A client who decided their own HR head should set cover too.
        $role->permissions()->create(['permission' => Permission::ManageCover]);

        return $role;
    });

    $migration = require database_path(
        'migrations/2026_09_03_100000_grant_setting_cover_to_existing_administrators.php'
    );

    $migration->down();

    TenantContext::run($this->meridian, function () use ($hrHead) {
        expect($hrHead->permissions()->where('permission', Permission::ManageCover)->exists())->toBeTrue();

        $administrator = Role::query()->where('key', Role::AdministratorKey)->sole();

        expect($administrator->permissions()->where('permission', Permission::ManageCover)->exists())->toBeFalse();
    });
});

/*
| And a fourth time, by module 05.2's step 7.
|
| Settling who takes on a leaver's work is done from the leaver's own exit, so an existing
| client whose administrator never got the action would open that exit and find nothing
| there to do it with.
*/

/** Run the handover migration the way it runs on a real database: no company in scope. */
function grantSettlingAHandoverToEveryAdministrator(): void
{
    $migration = require database_path(
        'migrations/2026_09_03_150000_grant_settling_a_handover_to_existing_administrators.php'
    );

    $migration->up();
}

it('puts settling a handover on an administrator role that predates it', function () {
    $chandni = TenantContext::run($this->meridian, function () {
        $administrator = Role::query()->where('key', Role::AdministratorKey)->sole();

        // Wind Meridian back to how it was set up before the handover reached a screen.
        $administrator->permissions()->where('permission', Permission::SettleHandover)->delete();

        return User::query()->where('work_email', 'chandni@meridian.test')->sole();
    });

    $resolver = app(PermissionResolver::class);

    TenantContext::run($this->meridian, function () use ($resolver, $chandni) {
        expect($resolver->allows($chandni, Permission::SettleHandover))->toBeFalse();
    });

    grantSettlingAHandoverToEveryAdministrator();

    $resolver->forget();

    TenantContext::run($this->meridian, function () use ($resolver, $chandni) {
        // Over the whole company, which is what the exit's own screen asks for: the roles
        // being moved can cover the whole company themselves.
        expect($resolver->allowsEverywhere($chandni, Permission::SettleHandover))->toBeTrue();
    });
});
