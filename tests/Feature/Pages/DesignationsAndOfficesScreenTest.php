<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Resources\Designations\DesignationResource;
use App\Filament\Resources\Designations\Pages\ManageDesignations;
use App\Filament\Resources\Offices\OfficeResource;
use App\Filament\Resources\Offices\Pages\ManageOfficeHolidays;
use App\Filament\Resources\Offices\Pages\ManageOffices;
use App\Models\Designation;
use App\Models\Office;
use App\Models\OfficeHoliday;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
| The two lists a client keeps beside their structure, and the calendar each office works
| to.
|
| What is being checked, beyond the rows appearing: every answer the database would refuse
| is refused under its own box first, in our own words, because each of those refusals
| reaches a client as an error page otherwise. Four of them — a name that differs only in
| capitals, a state written as its own name, a state belonging to another country, and an
| office with all seven days off.
|
| And the weekly days off are checked as far as the thing they exist for: the office is
| asked afterwards whether it works on a Sunday.
|
| It runs against the demo company as seeded, so what is checked is the screen Ankit opens.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function whoKeepsMeridiansLists(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** The address of one of this module's screens on the demo company. */
function meridiansAddress(string $path): string
{
    return 'http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/'.$path;
}

/**
 * Give somebody a role carrying exactly these actions and nothing else.
 *
 * @param  list<string>  $actions
 */
function giveMeridianRoleCarrying(User $person, string $key, array $actions): void
{
    $role = Role::query()->create(['key' => $key, 'name' => $key]);

    foreach ($actions as $action) {
        $role->permissions()->create(['permission' => $action]);
    }

    $role->assignments()->create([
        'user_id' => $person->getKey(),
        'org_unit_id' => null,
        'includes_descendants' => false,
    ]);

    app(PermissionResolver::class)->forget();
}

it('shows an administrator the designations their company already uses', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))->test(ManageDesignations::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Designation::query()->orderBy('name')->get())
            ->assertSee('Branch Manager')
            ->assertSee('Regional Head');
    });
});

it('keeps both lists shut to somebody without the action that opens them', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh runs hiring and exits over Shimla. That role reads the structure and
        // does not keep the company's lists, so both screens are shut to him — which is
        // the whole point of the two new actions this step adds.
        $this->actingAs(whoKeepsMeridiansLists('rakesh'));

        expect(DesignationResource::canViewAny())->toBeFalse()
            ->and(OfficeResource::canViewAny())->toBeFalse();

        $this->actingAs(whoKeepsMeridiansLists('chandni'));

        expect(DesignationResource::canViewAny())->toBeTrue()
            ->and(OfficeResource::canViewAny())->toBeTrue();
    });

    $anjali = TenantContext::run($this->meridian, fn () => whoKeepsMeridiansLists('anjali'));

    // And Anjali, who holds no role at all, is refused the addresses themselves rather
    // than only the menu entries.
    $this->actingAs($anjali)->get(meridiansAddress('designations'))->assertForbidden();
    $this->actingAs($anjali)->get(meridiansAddress('offices'))->assertForbidden();
});

it('adds a designation, and refuses one whose name differs only in capitals', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))->test(ManageDesignations::class)
            ->callAction('create', data: ['name' => 'Logistics Coordinator', 'active' => true])
            ->assertHasNoActionErrors();

        $added = Designation::query()->where('name', 'Logistics Coordinator')->sole();

        expect((int) $added->tenant_id)->toBe((int) $this->meridian->getKey())
            ->and($added->active)->toBeTrue();

        // The database holds one name per company over the lowercased name, so this is
        // the same designation. Refused under the box rather than as an error page.
        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))->test(ManageDesignations::class)
            ->callAction('create', data: ['name' => 'logistics coordinator'])
            ->assertHasActionErrors(['name' => 'You already have a designation with this name.']);

        expect(Designation::query()->where('name', 'logistics coordinator')->exists())->toBeFalse();
    });
});

it('retires a designation instead of offering to delete it', function () {
    TenantContext::run($this->meridian, function () {
        $driver = Designation::query()->where('name', 'Operations Officer')->sole();

        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))->test(ManageDesignations::class)
            // Nothing here deletes: a job row keeps its own copy of the words it read,
            // and the record refuses a delete outright.
            ->assertActionDoesNotExist(TestAction::make('delete')->table($driver))
            ->callAction(TestAction::make('edit')->table($driver), data: ['active' => false])
            ->assertHasNoActionErrors()
            // Asserted against the word that is not truthy, because the check that reads
            // a column's formatted state matches loosely.
            ->assertTableColumnFormattedStateSet('active', 'Retired', $driver);

        expect($driver->fresh()->active)->toBeFalse();
    });
});

it('shows the offices with the days each one does not work, in words', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::query()->where('name', 'Shimla office')->sole();

        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))->test(ManageOffices::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Office::query()->orderBy('name')->get())
            ->assertTableColumnFormattedStateSet('weekly_off_days', 'Sunday, Saturday', $shimla)
            ->assertTableColumnFormattedStateSet('state_code', 'IN-HP', $shimla)
            // Nothing here deletes either.
            ->assertActionDoesNotExist(TestAction::make('delete')->table($shimla));
    });
});

it('adds an office typed in small letters and stores the codes as codes', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))->test(ManageOffices::class)
            ->callAction('create', data: [
                'name' => 'Nashik office',
                'country' => 'in',
                'state_code' => 'in-mh',
                'address_block' => "12 Trimbak Road\nNashik",
                // The text a set of boxes hands back, which is what a browser sends.
                'weekly_off_days' => ['0', '6'],
                'active' => true,
            ])
            ->assertHasNoActionErrors();

        $added = Office::query()->where('name', 'Nashik office')->sole();

        expect($added->country)->toBe('IN')
            ->and($added->state_code)->toBe('IN-MH')
            ->and($added->weekly_off_days)->toBe([0, 6]);
    });
});

it('refuses a state written as its own name, and one belonging to another country', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoKeepsMeridiansLists('chandni');

        // The database refuses both of these, and its refusal is an error page.
        Livewire::actingAs($chandni)->test(ManageOffices::class)
            ->callAction('create', data: [
                'name' => 'Kullu office',
                'country' => 'IN',
                'state_code' => 'Himachal Pradesh',
            ])
            ->assertHasActionErrors(['state_code' => 'Write the state as its code — IN-HP for Himachal Pradesh.']);

        Livewire::actingAs($chandni)->test(ManageOffices::class)
            ->callAction('create', data: [
                'name' => 'Colombo office',
                'country' => 'LK',
                'state_code' => 'IN-HP',
            ])
            ->assertHasActionErrors(['state_code' => "A state's code starts with its own country, so this one has to begin LK-."]);

        // And the country written out in full, which the database also refuses.
        Livewire::actingAs($chandni)->test(ManageOffices::class)
            ->callAction('create', data: [
                'name' => 'Solan office',
                'country' => 'India',
            ])
            ->assertHasActionErrors(['country' => 'Write the country as its two-letter code — IN for India.']);

        expect(Office::query()->whereIn('name', ['Kullu office', 'Colombo office', 'Solan office'])->exists())->toBeFalse();

        // With the country box left empty there is nothing to compare the state against,
        // so the state box says nothing at all and the country box does the telling. It
        // used to say the state had to begin "-.", which is gibberish on a client's
        // screen.
        Livewire::actingAs($chandni)->test(ManageOffices::class)
            ->callAction('create', data: [
                'name' => 'Mandi office',
                'country' => '',
                'state_code' => 'IN-HP',
            ])
            ->assertHasActionErrors(['country' => 'The country field is required.'])
            ->assertHasNoActionErrors(['state_code']);

        // A country with no states at all is allowed through, which is the half that
        // makes the two refusals above a rule rather than a wall.
        Livewire::actingAs($chandni)->test(ManageOffices::class)
            ->callAction('create', data: [
                'name' => 'Dublin office',
                'country' => 'IE',
                'state_code' => null,
                'weekly_off_days' => [0, 6],
            ])
            ->assertHasNoActionErrors();

        expect(Office::query()->where('name', 'Dublin office')->sole()->state_code)->toBeNull();
    });
});

it('refuses an office that never works, and saves the week it does work', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoKeepsMeridiansLists('chandni');

        Livewire::actingAs($chandni)->test(ManageOffices::class)
            ->callAction('create', data: [
                'name' => 'Never open',
                'country' => 'IN',
                'state_code' => 'IN-HP',
                'weekly_off_days' => ['0', '1', '2', '3', '4', '5', '6'],
            ])
            ->assertHasActionErrors([
                'weekly_off_days' => 'An office has to work at least one day a week, so it cannot have all seven off.',
            ]);

        expect(Office::query()->where('name', 'Never open')->exists())->toBeFalse();

        // A Gulf week, which is the reason the days are per office rather than per
        // company. Asked of the office afterwards rather than read out of the column,
        // because working out a deadline is the only thing this field is for.
        Livewire::actingAs($chandni)->test(ManageOffices::class)
            ->callAction('create', data: [
                'name' => 'Dubai office',
                'country' => 'AE',
                'state_code' => 'AE-DU',
                'weekly_off_days' => ['5', '6'],
            ])
            ->assertHasNoActionErrors();

        $dubai = Office::query()->where('name', 'Dubai office')->sole();

        // Whole numbers, not the text the boxes hand back, because the database checks
        // them as numbers.
        expect($dubai->weekly_off_days)->toBe([5, 6])
            ->and($dubai->isWorkingDay(new DateTimeImmutable('2026-09-04')))->toBeFalse()  // a Friday
            ->and($dubai->isWorkingDay(new DateTimeImmutable('2026-09-06')))->toBeTrue();  // a Sunday

        // And an office that works every day of the week, which the database allows and
        // which is what nothing ticked has to mean.
        Livewire::actingAs($chandni)->test(ManageOffices::class)
            ->callAction('create', data: [
                'name' => 'Depot',
                'country' => 'IN',
                'state_code' => 'IN-HP',
                'weekly_off_days' => [],
            ])
            ->assertHasNoActionErrors();

        expect(Office::query()->where('name', 'Depot')->sole()->weekly_off_days)->toBe([]);
    });
});

it('hides the working week from somebody who only keeps the lists, and leaves it alone', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = whoKeepsMeridiansLists('anjali');

        // Somebody a client has put on the lists and kept off the calendar, which is the
        // whole reason the two actions are apart.
        giveMeridianRoleCarrying($anjali, 'list_keeper', [Permission::ManageReferenceList]);

        $shimla = Office::query()->where('name', 'Shimla office')->sole();

        Livewire::actingAs($anjali)->test(ManageOffices::class)
            ->assertOk()
            // The dates page is not offered to her either.
            ->assertActionHidden(TestAction::make('holidays')->table($shimla))
            // She sends the working week along with the new name anyway, which is what
            // somebody doing this on purpose would do. The form does not carry that
            // field for her, so it is not written.
            ->callAction(
                TestAction::make('edit')->table($shimla),
                data: ['name' => 'Shimla head office', 'weekly_off_days' => []],
            )
            ->assertHasNoActionErrors();

        expect($shimla->fresh()->name)->toBe('Shimla head office')
            ->and($shimla->fresh()->weekly_off_days)->toBe([0, 6]);

        // And Chandni, who does hold it, changes the same office's week from the same
        // form — without which the check above would also pass on a form that never
        // saved the week at all.
        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))->test(ManageOffices::class)
            ->callAction(
                TestAction::make('edit')->table($shimla),
                data: ['weekly_off_days' => [6]],
            )
            ->assertHasNoActionErrors();

        expect($shimla->fresh()->weekly_off_days)->toBe([6]);
    });

    $anjali = TenantContext::run($this->meridian, fn () => whoKeepsMeridiansLists('anjali'));
    $shimla = TenantContext::run($this->meridian, fn () => Office::query()->where('name', 'Shimla head office')->sole());

    $this->actingAs($anjali)
        ->get(meridiansAddress('offices/'.$shimla->getKey().'/holidays'))
        ->assertForbidden();
});

it('records a date an office is closed, under that office', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::query()->where('name', 'Shimla office')->sole();

        expect($shimla->hasNoHolidaysRecorded())->toBeTrue();

        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))
            ->test(ManageOfficeHolidays::class, ['record' => $shimla->getKey()])
            ->assertOk()
            ->assertSee('Dates Shimla office is closed')
            ->callAction(TestAction::make('create')->table(), data: [
                'date' => '2027-01-26',
                'name' => 'Republic Day',
            ])
            ->assertHasNoActionErrors();

        $recorded = OfficeHoliday::query()->where('name', 'Republic Day')->sole();

        expect((int) $recorded->office_id)->toBe((int) $shimla->getKey())
            ->and((int) $recorded->tenant_id)->toBe((int) $this->meridian->getKey())
            // The only thing the date is for: the office is shut that day.
            ->and($shimla->fresh()->isWorkingDay(new DateTimeImmutable('2027-01-26')))->toBeFalse();

        // The ordinary way this gets tried is pasting last year's list over this year's.
        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))
            ->test(ManageOfficeHolidays::class, ['record' => $shimla->getKey()])
            ->callAction(TestAction::make('create')->table(), data: [
                'date' => '2027-01-26',
                'name' => 'Republic Day again',
            ])
            ->assertHasActionErrors(['date' => 'This office already has a date recorded for that day.']);

        expect(OfficeHoliday::query()->where('office_id', $shimla->getKey())->count())->toBe(1);
    });
});

it('takes a date back off, which the two lists beside it do not allow', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::query()->where('name', 'Shimla office')->sole();

        $wrong = OfficeHoliday::factory()->at($shimla)->on('2027-03-04', 'Typed by mistake')->create();

        Livewire::actingAs(whoKeepsMeridiansLists('chandni'))
            ->test(ManageOfficeHolidays::class, ['record' => $shimla->getKey()])
            ->assertCanSeeTableRecords([$wrong])
            ->callAction(TestAction::make('delete')->table($wrong))
            ->assertHasNoActionErrors();

        expect(OfficeHoliday::query()->whereKey($wrong->getKey())->exists())->toBeFalse();
    });
});

it('puts both screens in the menu between the structure and the settings', function () {
    $chandni = TenantContext::run($this->meridian, fn () => whoKeepsMeridiansLists('chandni'));

    $this->actingAs($chandni)
        ->get(meridiansAddress('designations'))
        ->assertOk()
        ->assertSeeInOrder(['Company setup', 'Structure', 'Designations', 'Offices', 'Settings']);
});
