<?php

use App\Authorization\PermissionResolver;
use App\Filament\Resources\OrgUnits\OrgUnitResource;
use App\Filament\Resources\OrgUnits\Pages\ManageOrgUnits;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
| The screen a client builds their own departments and branches on.
|
| Two things are being checked, and both of them are holes a permission check on its own
| cannot close. One, the list shows Rakesh the branch he runs and not the branch next to
| it — the check that opens the screen only ever answers "may he do this somewhere", so a
| list that trusted it would hand him Pune's rows. Two, a part of the company saved under
| a branch somebody does not cover is refused, because "may he create a department" is
| asked before the department exists and so cannot name where it is going.
|
| It runs against the demo company as seeded, so what is checked is the screen Ankit opens.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function whoSetsUpMeridian(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/**
 * Meridian's four parts, by the names on screen.
 *
 * @return array{company: OrgUnit, north: OrgUnit, shimla: OrgUnit, pune: OrgUnit}
 */
function meridiansParts(): array
{
    $named = fn (string $name): OrgUnit => OrgUnit::query()->where('name', $name)->sole();

    return [
        'company' => $named('Meridian Logistics'),
        'north' => $named('North Logistics'),
        'shimla' => $named('Shimla branch'),
        'pune' => $named('Pune branch'),
    ];
}

it('shows an administrator the whole company', function () {
    TenantContext::run($this->meridian, function () {
        $parts = meridiansParts();

        Livewire::actingAs(whoSetsUpMeridian('chandni'))->test(ManageOrgUnits::class)
            ->assertOk()
            ->assertCanSeeTableRecords(array_values($parts))
            // The column that carries the shape of the company, since there is no
            // indented tree: every row says what it sits under, and the top says it is
            // the top. Whether a part is in use or archived is checked further down,
            // where it is changed.
            ->assertTableColumnFormattedStateSet('parent.name', 'North Logistics', $parts['shimla'])
            // Read off the page rather than off the column: the words standing in for an
            // empty cell are put there when the row is drawn.
            ->assertSee('Top of the company');
    });
});

it('shows somebody only the branch they cover, with the branch next to it present', function () {
    TenantContext::run($this->meridian, function () {
        $parts = meridiansParts();

        // Rakesh runs HR over Shimla alone. Pune is the point of the test: it is what a
        // list would show him if it trusted the check that opened the screen.
        Livewire::actingAs(whoSetsUpMeridian('rakesh'))->test(ManageOrgUnits::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$parts['shimla']])
            ->assertCanNotSeeTableRecords([$parts['pune'], $parts['north'], $parts['company']])
            ->assertCountTableRecords(1);
    });
});

it('keeps the screen shut to somebody holding no role at all', function () {
    TenantContext::run($this->meridian, function () {
        // Anjali raises every hiring request and holds no role, so she is the test of
        // every screen somebody with no permissions has to be kept out of.
        $this->actingAs(whoSetsUpMeridian('anjali'));

        expect(OrgUnitResource::canViewAny())->toBeFalse();

        $this->actingAs(whoSetsUpMeridian('rakesh'));

        // Rakesh may read the structure and may not change it, which is the pair the
        // starter roles were written to produce.
        expect(OrgUnitResource::canViewAny())->toBeTrue()
            ->and(OrgUnitResource::canCreate())->toBeFalse();

        $this->actingAs(whoSetsUpMeridian('chandni'));

        expect(OrgUnitResource::canViewAny())->toBeTrue()
            ->and(OrgUnitResource::canCreate())->toBeTrue();
    });

    // And she is refused the address itself, not only the menu entry.
    $anjali = TenantContext::run($this->meridian, fn () => whoSetsUpMeridian('anjali'));

    $this->actingAs($anjali)
        ->get('http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/org-units')
        ->assertForbidden();
});

it('adds a department under a branch and reads it back on the list', function () {
    TenantContext::run($this->meridian, function () {
        $parts = meridiansParts();

        Livewire::actingAs(whoSetsUpMeridian('chandni'))->test(ManageOrgUnits::class)
            ->callAction('create', data: [
                'name' => 'Customer Service',
                'type' => 'department',
                'parent_id' => $parts['shimla']->getKey(),
                'code' => 'CS-SHI',
                'active' => true,
            ])
            ->assertHasNoActionErrors();

        $added = OrgUnit::query()->where('name', 'Customer Service')->sole();

        expect((int) $added->parent_id)->toBe((int) $parts['shimla']->getKey())
            ->and((int) $added->tenant_id)->toBe((int) $this->meridian->getKey())
            ->and($added->active)->toBeTrue();

        // A code already in use is refused under its own box. The database refuses it too,
        // and that refusal reaches a client as an error page rather than as a correction.
        Livewire::actingAs(whoSetsUpMeridian('chandni'))->test(ManageOrgUnits::class)
            ->callAction('create', data: [
                'name' => 'Customer Care',
                'type' => 'department',
                'parent_id' => $parts['pune']->getKey(),
                'code' => 'CS-SHI',
            ])
            ->assertHasActionErrors(['code']);

        expect(OrgUnit::query()->where('name', 'Customer Care')->exists())->toBeFalse();
    });
});

it('refuses a department saved under a branch the person does not cover', function () {
    TenantContext::run($this->meridian, function () {
        $parts = meridiansParts();
        $rakesh = whoSetsUpMeridian('rakesh');

        // An administrator for one branch, which is the shape this rule exists for: he
        // passes "may he create a department" and must still be refused Pune.
        Role::query()->where('key', Role::AdministratorKey)->sole()
            ->assignments()->create([
                'user_id' => $rakesh->getKey(),
                'org_unit_id' => $parts['shimla']->getKey(),
                'includes_descendants' => false,
            ]);

        app(PermissionResolver::class)->forget();

        Livewire::actingAs($rakesh)->test(ManageOrgUnits::class)
            ->callAction('create', data: [
                'name' => 'Pune Customer Service',
                'type' => 'department',
                'parent_id' => $parts['pune']->getKey(),
            ])
            // In our own words, not the framework's "the selected sits under is invalid".
            ->assertHasActionErrors(['parent_id' => 'That is not a part of the company you can add to.']);

        expect(OrgUnit::query()->where('name', 'Pune Customer Service')->exists())->toBeFalse();

        // The same person, the same form, his own branch: allowed. Without this half the
        // test above would also pass on a form that refused everybody.
        Livewire::actingAs($rakesh)->test(ManageOrgUnits::class)
            ->callAction('create', data: [
                'name' => 'Shimla Customer Service',
                'type' => 'department',
                'parent_id' => $parts['shimla']->getKey(),
            ])
            ->assertHasNoActionErrors();

        expect(OrgUnit::query()->where('name', 'Shimla Customer Service')->exists())->toBeTrue();

        // And he cannot leave it empty either. A part with nothing above it is a second
        // company standing beside the first, which is not something a branch's own
        // administrator gets to start.
        Livewire::actingAs($rakesh)->test(ManageOrgUnits::class)
            ->callAction('create', data: [
                'name' => 'Rakesh Logistics',
                'type' => 'company',
                'parent_id' => null,
            ])
            ->assertHasActionErrors(['parent_id']);

        expect(OrgUnit::query()->where('name', 'Rakesh Logistics')->exists())->toBeFalse();
    });
});

it('lets a branch administrator rename their own branch, and still not move it', function () {
    TenantContext::run($this->meridian, function () {
        $parts = meridiansParts();
        $rakesh = whoSetsUpMeridian('rakesh');

        // Administrator over Shimla and everything under it, which is the shape a client
        // writes for a branch that runs itself.
        Role::query()->where('key', Role::AdministratorKey)->sole()
            ->assignments()->create([
                'user_id' => $rakesh->getKey(),
                'org_unit_id' => $parts['shimla']->getKey(),
                'includes_descendants' => true,
            ]);

        app(PermissionResolver::class)->forget();

        // Shimla sits under North Logistics, which he does not cover. Offering only what
        // he covers left this box with nothing in it and required, so the one row he can
        // see was the one row he could not save.
        Livewire::actingAs($rakesh)->test(ManageOrgUnits::class)
            ->callAction(
                TestAction::make('edit')->table($parts['shimla']),
                data: ['name' => 'Shimla office'],
            )
            ->assertHasNoActionErrors();

        expect($parts['shimla']->fresh()->name)->toBe('Shimla office');

        // Keeping where it already sits is all that opened up. Moving his branch under
        // another one is still refused, which is the half that makes the fix a fix rather
        // than a hole.
        Livewire::actingAs($rakesh)->test(ManageOrgUnits::class)
            ->callAction(
                TestAction::make('edit')->table($parts['shimla']),
                data: ['parent_id' => $parts['pune']->getKey()],
            )
            ->assertHasActionErrors(['parent_id' => 'That is not a part of the company you can add to.']);

        expect((int) $parts['shimla']->fresh()->parent_id)->toBe((int) $parts['north']->getKey());
    });
});

it('refuses a part of the company moved under its own branch', function () {
    TenantContext::run($this->meridian, function () {
        $parts = meridiansParts();

        // North Logistics has Shimla under it. Moving North under Shimla is refused by the
        // record itself, which would reach a client as an error page — so the form has to
        // refuse it under the box instead.
        Livewire::actingAs(whoSetsUpMeridian('chandni'))->test(ManageOrgUnits::class)
            ->callAction(
                TestAction::make('edit')->table($parts['north']),
                data: ['parent_id' => $parts['shimla']->getKey()],
            )
            ->assertHasActionErrors(['parent_id']);

        expect((int) $parts['north']->fresh()->parent_id)
            ->toBe((int) $parts['company']->getKey());
    });
});

it('archives a branch instead of offering to delete it', function () {
    TenantContext::run($this->meridian, function () {
        $parts = meridiansParts();

        Livewire::actingAs(whoSetsUpMeridian('chandni'))->test(ManageOrgUnits::class)
            // Nothing on this screen deletes. A part of the company that is finished with
            // is archived, because deleting one takes every job row, role grant and case
            // that named it with it.
            ->assertActionDoesNotExist(TestAction::make('delete')->table($parts['pune']))
            ->callAction(
                TestAction::make('edit')->table($parts['pune']),
                data: ['active' => false],
            )
            ->assertHasNoActionErrors()
            ->assertTableColumnFormattedStateSet('active', 'Archived', $parts['pune'])
            ->assertTableColumnFormattedStateSet('active', 'In use', $parts['shimla']);

        expect($parts['pune']->fresh()->active)->toBeFalse();
    });
});

it('puts the screen in the menu under how the company is set up', function () {
    $chandni = TenantContext::run($this->meridian, fn () => whoSetsUpMeridian('chandni'));

    $this->actingAs($chandni)
        ->get('http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/org-units')
        ->assertOk()
        ->assertSeeInOrder(['Company setup', 'Structure', 'Settings']);
});
