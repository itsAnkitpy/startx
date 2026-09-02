<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\JobHistoryRelationManager;
use App\Filament\Resources\Users\RelationManagers\StatutoryNumbersRelationManager;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Resources\Users\UserResource;
use App\Models\Designation;
use App\Models\EmployeeStatutoryId;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Everyone at the client company, and under each person the jobs they have held.
|
| What is being checked beyond the rows appearing, which is all three of this module's own
| screen rules landing on one screen for the first time:
|
| Rakesh runs HR over the Shimla branch alone, so he sees Shimla's people and not Pune's,
| and a job row he tries to save naming Pune is refused — not because he lacks the action,
| which he holds, but because the picker never offered him that branch.
|
| A job row is never edited. Recording a change adds a dated row, closes the old one off
| the day before and carries the joining date forward, so a question about a past date still
| answers with the department that was true then. A row entered by mistake is withdrawn with
| a reason.
|
| And a bank number is masked to whoever may read it and says it is on file and withheld to
| whoever may not, which is the difference that stops a number being entered twice.
|
| It runs against the demo company as seeded, so what is checked is the screen Ankit opens.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function whoIsOnMeridiansPayroll(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** The address of one of this module's screens on the demo company. */
function meridiansPeopleAddress(string $path): string
{
    return 'http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/'.$path;
}

/** One part of the demo company's structure, by name. */
function meridiansBranchCalled(string $name): OrgUnit
{
    return OrgUnit::query()->where('name', $name)->sole();
}

/**
 * Give somebody a role carrying exactly these actions over the whole company and nothing
 * else.
 *
 * @param  list<string>  $actions
 */
function giveMeridianPersonARoleCarrying(User $person, string $key, array $actions): void
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

/** The job history under one person, as that person's own page draws it. */
function meridiansJobHistoryFor(User $person, User $asSeenBy): Testable
{
    return Livewire::actingAs($asSeenBy)->test(JobHistoryRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => EditUser::class,
    ]);
}

it('shows an administrator everybody at the company, with the job each one holds now', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(whoIsOnMeridiansPayroll('chandni'))->test(ListUsers::class)
            ->assertOk()
            ->assertCanSeeTableRecords(User::query()->orderBy('first_name')->get())
            ->assertSee('Chandni Verma')
            ->assertSee('Rohit Menon')
            // The department, designation and office are read from the job that is true
            // today, not from columns on the account.
            ->assertSee('Shimla branch')
            ->assertSee('Pune branch')
            ->assertSee('Operations Officer');
    });
});

it('shows somebody responsible for one branch that branch\'s people and nobody else\'s', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh runs HR over the Shimla branch alone. Rohit works in Pune and Chandni sits
        // above both branches, so neither is his to see — and the action he holds says only
        // that he may see people somewhere.
        $shimla = ['Rakesh Menon', 'Priya Nair', 'Deepak Iyer', 'Anjali Rao'];

        $list = Livewire::actingAs(whoIsOnMeridiansPayroll('rakesh'))->test(ListUsers::class)
            ->assertOk()
            ->assertDontSee('Rohit Menon')
            ->assertDontSee('Chandni Verma');

        foreach ($shimla as $name) {
            $list->assertSee($name);
        }
    });
});

it('keeps a joiner with no job recorded yet on the list of whoever added them', function () {
    TenantContext::run($this->meridian, function () {
        // Between being added and their first job row, somebody sits nowhere in the
        // structure. Leaving them out would hide a person from the branch manager who had
        // just created them, and it is also what the permission check itself answers.
        Livewire::actingAs(whoIsOnMeridiansPayroll('rakesh'))->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Sunita',
                'last_name' => 'Bhatt',
                'work_email' => 'sunita@meridian.test',
                'password' => 'a-long-enough-one',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(User::query()->where('work_email', 'sunita@meridian.test')->exists())->toBeTrue();

        Livewire::actingAs(whoIsOnMeridiansPayroll('rakesh'))->test(ListUsers::class)
            ->assertSee('Sunita Bhatt');
    });
});

it('refuses a second person signing in with an address somebody here already uses', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(whoIsOnMeridiansPayroll('chandni'))->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Another',
                'last_name' => 'Rakesh',
                'work_email' => 'rakesh@meridian.test',
                'password' => 'a-long-enough-one',
            ])
            ->call('create')
            ->assertHasFormErrors(['work_email' => 'Somebody here already signs in with that address.']);
    });
});

it('shuts the screen to somebody holding no role at all', function () {
    TenantContext::run($this->meridian, function () {
        $this->actingAs(whoIsOnMeridiansPayroll('rakesh'));
        expect(UserResource::canViewAny())->toBeTrue();

        $this->actingAs(whoIsOnMeridiansPayroll('anjali'));
        expect(UserResource::canViewAny())->toBeFalse();
    });

    $anjali = TenantContext::run($this->meridian, fn () => whoIsOnMeridiansPayroll('anjali'));

    // The address itself, not only the menu entry.
    $this->actingAs($anjali)->get(meridiansPeopleAddress('users'))->assertForbidden();
});

it('draws the list and one person\'s whole page in a real request', function () {
    $chandni = TenantContext::run($this->meridian, fn () => whoIsOnMeridiansPayroll('chandni'));
    $deepak = TenantContext::run($this->meridian, fn () => whoIsOnMeridiansPayroll('deepak'));

    // The component tests above draw each part on its own. This asks for the addresses
    // themselves, so a person's page is proved to draw with both lists underneath it — the
    // job history and the tax and bank numbers — rather than only in isolation.
    $this->actingAs($chandni)->get(meridiansPeopleAddress('users'))
        ->assertOk()
        ->assertSee('People');

    $this->actingAs($chandni)->get(meridiansPeopleAddress('users/'.$deepak->getKey().'/edit'))
        ->assertOk()
        ->assertSee('Deepak Iyer')
        ->assertSee('Job history')
        ->assertSee('Tax and bank numbers');
});

it('offers nowhere to delete a person, on the list or on their own page', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');

        // A person's record is the evidence behind their exit and their settlement, and a
        // disputed settlement is argued after they have gone. An account that should no
        // longer sign in is switched off on the form instead.
        Livewire::actingAs(whoIsOnMeridiansPayroll('chandni'))->test(ListUsers::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table($deepak));

        Livewire::actingAs(whoIsOnMeridiansPayroll('chandni'))->test(EditUser::class, ['record' => $deepak->getKey()])
            ->assertOk()
            ->assertActionDoesNotExist('delete');
    });
});

it('keeps the sign-in switch off the form for somebody who may not switch an account off', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');

        // A field that is not on the form is not written, so this is the refusal as well as
        // the hiding: somebody who only keeps details up to date cannot switch an account
        // off by submitting the form anyway.
        $keepsDetails = whoIsOnMeridiansPayroll('anjali');
        giveMeridianPersonARoleCarrying($keepsDetails, 'details_only', [
            Permission::ViewPerson,
            Permission::UpdatePerson,
        ]);

        Livewire::actingAs($keepsDetails)->test(EditUser::class, ['record' => $deepak->getKey()])
            ->assertOk()
            ->assertFormFieldDoesNotExist('active');

        Livewire::actingAs(whoIsOnMeridiansPayroll('chandni'))->test(EditUser::class, ['record' => $deepak->getKey()])
            ->assertFormFieldExists('active');
    });
});

it('records a change of department as a new dated row and leaves the old one readable', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');
        $pune = meridiansBranchCalled('Pune branch');

        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('chandni'))
            ->assertOk()
            ->callAction(TestAction::make('create')->table(), data: [
                'effective_from' => '2025-01-01',
                'org_unit_id' => $pune->getKey(),
                'employment_type' => 'permanent',
                'employment_status' => 'confirmed',
                'change_reason' => 'Moved to the Pune branch',
            ])
            ->assertHasNoActionErrors();

        // Oldest first. The relation itself is newest-first, so this has to replace that
        // ordering rather than add to it.
        $rows = $deepak->employmentRecords()->reorder('effective_from')->get();

        expect($rows)->toHaveCount(2)
            // The row he held before is closed off the day before the new one starts, not
            // overwritten.
            ->and($rows[0]->effective_to->toDateString())->toBe('2024-12-31')
            ->and($rows[1]->effective_to)->toBeNull()
            // The joining date is carried forward, because a promotion or a transfer is not
            // a new stint — the length of somebody's service is what says whether gratuity
            // is owed.
            ->and($rows[1]->joining_date->toDateString())->toBe($rows[0]->joining_date->toDateString());

        // And the whole reason this is history: a question about a past date still answers
        // with the department that was true then.
        expect($deepak->employmentAsOf('2024-06-01')->orgUnit->name)->toBe('Shimla branch')
            ->and($deepak->employmentAsOf('2025-06-01')->orgUnit->name)->toBe('Pune branch');
    });
});

it('offers no way to edit a job row, only to record a change or withdraw one', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');
        $row = $deepak->employmentRecords()->sole();

        // Editing a row in place rewrites what a case already decided was true on that day.
        // The two honest acts are a further change and a withdrawal.
        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('chandni'))
            ->assertActionDoesNotExist(TestAction::make('edit')->table($row))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($row))
            ->assertActionExists(TestAction::make('withdraw')->table($row));
    });
});

it('withdraws a row entered by mistake and hands its end date back to the row before it', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');
        $pune = meridiansBranchCalled('Pune branch');

        EmploymentRecord::recordAChange($deepak, [
            'effective_from' => '2025-01-01',
            'org_unit_id' => $pune->getKey(),
            'employment_type' => 'permanent',
            'employment_status' => 'confirmed',
            'change_reason' => 'Typed by mistake',
        ]);

        $mistake = $deepak->employmentRecords()->whereNull('effective_to')->sole();

        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('chandni'))
            ->callAction(TestAction::make('withdraw')->table($mistake), data: [
                'reason' => 'Wrong person — this was meant for Rohit.',
            ])
            ->assertHasNoActionErrors();

        $left = $deepak->employmentRecords()->get();

        expect($left)->toHaveCount(1)
            ->and($left[0]->orgUnit->name)->toBe('Shimla branch')
            // The row before it is the row he holds now again, so the history closes over
            // the gap rather than leaving him employed nowhere.
            ->and($left[0]->effective_to)->toBeNull();
    });
});

it('refuses a job row naming a branch the picker never offered', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');
        $pune = meridiansBranchCalled('Pune branch');

        // Rakesh holds the action that changes somebody's record — over Shimla. Whether he
        // may change somebody at all is asked before the row exists, so without the picker
        // being narrowed he could move one of his own people into a branch he has nothing
        // to do with. What refuses it is the picker's own query.
        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('rakesh'))
            ->assertOk()
            ->callAction(TestAction::make('create')->table(), data: [
                'effective_from' => '2025-01-01',
                'org_unit_id' => $pune->getKey(),
                'employment_type' => 'permanent',
                'employment_status' => 'confirmed',
                'change_reason' => 'Moved to the Pune branch',
            ])
            ->assertHasActionErrors(['org_unit_id']);

        expect($deepak->employmentRecords()->count())->toBe(1);

        // And his own branch still saves, on the same form and for the same person.
        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('rakesh'))
            ->callAction(TestAction::make('create')->table(), data: [
                'effective_from' => '2025-01-01',
                'org_unit_id' => meridiansBranchCalled('Shimla branch')->getKey(),
                'employment_type' => 'permanent',
                'employment_status' => 'confirmed',
                'change_reason' => 'Promoted',
            ])
            ->assertHasNoActionErrors();

        expect($deepak->employmentRecords()->count())->toBe(2);
    });
});

it('refuses a change dated before the job somebody already holds, and one dated ahead', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');
        $shimla = meridiansBranchCalled('Shimla branch');

        $change = fn (string $from): array => [
            'effective_from' => $from,
            'org_unit_id' => $shimla->getKey(),
            'employment_type' => 'permanent',
            'employment_status' => 'confirmed',
            'change_reason' => 'Promoted',
        ];

        // The job he holds now started on 1 April 2024. The database refuses an end date
        // before the row's own start, and it refuses it as an error page — so the form says
        // it first, naming the date.
        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('chandni'))
            ->callAction(TestAction::make('create')->table(), data: $change('2024-01-01'))
            ->assertHasActionErrors([
                'effective_from' => 'The job they hold now started on 1 April 2024, so a change to it has to start after that.',
            ]);

        // And a change dated ahead would close today's job off yesterday while the new one
        // has not started, leaving his history empty in between — which every question
        // about a past date reads.
        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('chandni'))
            ->callAction(TestAction::make('create')->table(), data: $change(now()->addMonth()->toDateString()))
            ->assertHasActionErrors(['effective_from']);

        expect($deepak->employmentRecords()->count())->toBe(1);
    });
});

it('lets the first row of a history start on a future date, because there is nothing in front of it', function () {
    TenantContext::run($this->meridian, function () {
        // A joiner starting next month is ordinary, and there is no earlier row for that
        // start to leave a gap in front of. So the rule above deliberately says nothing here.
        $joiner = User::factory()->named('Sunita Bhatt')->create([
            'work_email' => 'sunita@meridian.test',
            'password' => MeridianSeeder::Password,
        ]);

        meridiansJobHistoryFor($joiner, whoIsOnMeridiansPayroll('chandni'))
            ->assertOk()
            ->assertSee('No job recorded yet')
            ->callAction(TestAction::make('create')->table(), data: [
                'effective_from' => now()->addMonth()->toDateString(),
                'org_unit_id' => meridiansBranchCalled('Shimla branch')->getKey(),
                'employment_type' => 'permanent',
                'employment_status' => 'probation',
                'change_reason' => 'Joined',
            ])
            ->assertHasNoActionErrors();

        $row = $joiner->employmentRecords()->sole();

        // With nothing before it, the joining date is the day the row starts.
        expect($row->joining_date->toDateString())->toBe(now()->addMonth()->toDateString());
    });
});

it('says nothing about a last working day until there is a change date to compare it against', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');
        $shimla = meridiansBranchCalled('Shimla branch');

        // The mistake this avoids is the one step 3's review found on the offices form: a
        // box comparing itself against an empty box and answering with a sentence that has
        // nothing in it. The change date already says it is required.
        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('chandni'))
            ->callAction(TestAction::make('create')->table(), data: [
                'org_unit_id' => $shimla->getKey(),
                'employment_type' => 'permanent',
                'employment_status' => 'exited',
                'last_working_day' => '2025-03-31',
                'change_reason' => 'Left',
            ])
            ->assertHasActionErrors(['effective_from'])
            ->assertHasNoActionErrors(['last_working_day']);

        // With a date to compare against, a last working day before it is refused.
        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('chandni'))
            ->callAction(TestAction::make('create')->table(), data: [
                'effective_from' => '2025-04-01',
                'org_unit_id' => $shimla->getKey(),
                'employment_type' => 'permanent',
                'employment_status' => 'exited',
                'last_working_day' => '2025-03-31',
                'change_reason' => 'Left',
            ])
            ->assertHasActionErrors([
                'last_working_day' => 'Their last working day cannot come before the date this change takes effect.',
            ]);
    });
});

it('masks a bank number for whoever may read it and says it is withheld to whoever may not', function () {
    TenantContext::run($this->meridian, function () {
        $priya = whoIsOnMeridiansPayroll('priya');

        $account = EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(),
            'type' => 'bank_account',
            'country' => 'IN',
            'value' => '50100234567890',
        ]);

        $numbersAsSeenBy = fn (User $reader) => Livewire::actingAs($reader)
            ->test(StatutoryNumbersRelationManager::class, [
                'ownerRecord' => $priya,
                'pageClass' => EditUser::class,
            ]);

        // Chandni's role carries reading these. She gets the last four behind dots on the
        // list, and the whole number only when she asks for it.
        $numbersAsSeenBy(whoIsOnMeridiansPayroll('chandni'))
            ->assertOk()
            ->assertTableColumnStateSet('value', '•••• 7890', $account)
            ->assertActionExists(TestAction::make('showTheWholeNumber')->table($account));

        // Rakesh may see her record and not her bank account. He is told it is on file
        // rather than shown an empty space, which is what stops it being entered twice —
        // and he does not get the last four digits either.
        $numbersAsSeenBy(whoIsOnMeridiansPayroll('rakesh'))
            ->assertOk()
            ->assertTableColumnStateSet('value', 'On file — not yours to see', $account)
            ->assertActionHidden(TestAction::make('showTheWholeNumber')->table($account))
            // Nor may he add one: entering a number needs being able to check the one
            // already there.
            ->assertActionHidden(TestAction::make('create')->table());
    });
});

it('refuses an Aadhaar number written into a tax number box, under the box', function () {
    TenantContext::run($this->meridian, function () {
        $priya = whoIsOnMeridiansPayroll('priya');

        // The record refuses it too, and would otherwise reach a client as an error page.
        // Not a real Aadhaar number and obviously nobody's — it passes the check digit.
        Livewire::actingAs(whoIsOnMeridiansPayroll('chandni'))
            ->test(StatutoryNumbersRelationManager::class, [
                'ownerRecord' => $priya,
                'pageClass' => EditUser::class,
            ])
            ->callAction(TestAction::make('create')->table(), data: [
                'type' => 'pan',
                'country' => 'IN',
                'value' => '222233334444',
            ])
            ->assertHasActionErrors(['value']);

        expect($priya->statutoryIds()->count())->toBe(0);

        // And the two kinds that are themselves twelve digits are left alone, because
        // plenty of real Indian bank accounts are twelve digits long.
        Livewire::actingAs(whoIsOnMeridiansPayroll('chandni'))
            ->test(StatutoryNumbersRelationManager::class, [
                'ownerRecord' => $priya,
                'pageClass' => EditUser::class,
            ])
            ->callAction(TestAction::make('create')->table(), data: [
                'type' => 'bank_account',
                'country' => 'IN',
                'value' => '222233334444',
            ])
            ->assertHasNoActionErrors();

        expect($priya->statutoryIds()->count())->toBe(1);
    });
});

it('refuses a manager who already reports up to the person, under the box that names them', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = whoIsOnMeridiansPayroll('rakesh');
        $deepak = whoIsOnMeridiansPayroll('deepak');

        // Deepak reports to Rakesh, so naming Deepak as Rakesh's manager sends the line
        // back round. The record refuses it and would otherwise reach a client as an error
        // page — and picking somebody's own junior by mistake is three clicks from here.
        meridiansJobHistoryFor($rakesh, whoIsOnMeridiansPayroll('chandni'))
            ->callAction(TestAction::make('create')->table(), data: [
                'effective_from' => now()->toDateString(),
                'org_unit_id' => meridiansBranchCalled('Shimla branch')->getKey(),
                'employment_type' => 'permanent',
                'employment_status' => 'confirmed',
                'reports_to_id' => $deepak->getKey(),
                'change_reason' => 'Now reports to Deepak',
            ])
            ->assertHasActionErrors([
                'reports_to_id' => 'Deepak Iyer already reports up to Rakesh Menon, so naming them here would send the reporting line back round. Name somebody above them instead.',
            ]);

        expect($rakesh->employmentRecords()->count())->toBe(1);

        // And somebody above him still saves, on the same form and for the same person.
        meridiansJobHistoryFor($rakesh, whoIsOnMeridiansPayroll('chandni'))
            ->callAction(TestAction::make('create')->table(), data: [
                'effective_from' => now()->toDateString(),
                'org_unit_id' => meridiansBranchCalled('Shimla branch')->getKey(),
                'employment_type' => 'permanent',
                'employment_status' => 'confirmed',
                'reports_to_id' => whoIsOnMeridiansPayroll('chandni')->getKey(),
                'change_reason' => 'Now reports to Chandni',
            ])
            ->assertHasNoActionErrors();

        expect($rakesh->employmentRecords()->count())->toBe(2);
    });
});

it('keeps a retired designation and a closed office on offer while somebody still holds them', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');
        $held = $deepak->employmentRecords()->whereNull('effective_to')->sole();

        // The client tidies their two lists. Deepak has not moved, and nothing about a
        // transfer should quietly take away what he is called or where he works from —
        // the panel above every approval reads his designation off the row.
        Designation::query()->where('name', 'Operations Officer')->sole()->update(['active' => false]);
        Office::query()->where('name', 'Shimla office')->sole()->update(['active' => false]);

        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('chandni'))
            ->callAction(TestAction::make('create')->table(), data: [
                'effective_from' => now()->toDateString(),
                'org_unit_id' => $held->org_unit_id,
                'designation_id' => $held->designation_id,
                'office_id' => $held->office_id,
                'employment_type' => 'permanent',
                'employment_status' => 'confirmed',
                'change_reason' => 'Moved desks, same job',
            ])
            ->assertHasNoActionErrors();

        $now = $deepak->employmentRecords()->whereNull('effective_to')->sole();

        expect($now->recorded_designation_name)->toBe('Operations Officer')
            ->and($now->office_id)->toBe($held->office_id);

        // A retired entry nobody holds is still off the list, which is what retiring one is
        // for. Nothing but Deepak's own two survive being switched off.
        $retiredButUnheld = Designation::factory()->named('Night Supervisor')->create(['active' => false]);

        meridiansJobHistoryFor($deepak, whoIsOnMeridiansPayroll('chandni'))
            ->callAction(TestAction::make('create')->table(), data: [
                'effective_from' => now()->addDay()->toDateString(),
                'org_unit_id' => $held->org_unit_id,
                'designation_id' => $retiredButUnheld->getKey(),
                'employment_type' => 'permanent',
                'employment_status' => 'confirmed',
                'change_reason' => 'Promoted to night supervisor',
            ])
            ->assertHasActionErrors(['designation_id']);
    });
});

it('offers only the branches somebody covers in the box that narrows the list', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh runs HR over Shimla alone. Offering him Pune in this box tells him the
        // company has a Pune branch — which the structure screen hides from him — and
        // choosing it hands him an empty list, because those rows were never his.
        $offered = UsersTable::configure(
            Livewire::actingAs(whoIsOnMeridiansPayroll('rakesh'))->test(ListUsers::class)->instance()->getTable()
        )->getFilter('department')->getOptions();

        expect($offered)->toContain('Shimla branch')
            ->and($offered)->not->toContain('Pune branch')
            ->and($offered)->not->toContain('North Logistics');

        // Chandni covers the whole company, so every part of it is worth narrowing to.
        $hers = UsersTable::configure(
            Livewire::actingAs(whoIsOnMeridiansPayroll('chandni'))->test(ListUsers::class)->instance()->getTable()
        )->getFilter('department')->getOptions();

        expect($hers)->toContain('Shimla branch')
            ->and($hers)->toContain('Pune branch');
    });
});

it('refuses somebody who reads numbers in one branch the numbers of a person in another', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoIsOnMeridiansPayroll('deepak');
        $account = $deepak->statutoryIds()->create([
            'type' => 'bank_account',
            'country' => 'IN',
            'value' => '900011112222333',
        ]);

        // Somebody who keeps Shimla's people up to date and reads tax numbers in Pune. The
        // two grants cover different branches on purpose: without narrowing, being told a
        // number is not theirs to see and being able to delete it were separate answers.
        $split = User::factory()->named('Split Grant')->create([
            'work_email' => 'split@meridian.test',
            'password' => MeridianSeeder::Password,
        ]);

        $overABranch = function (string $key, array $actions, OrgUnit $unit) use ($split): void {
            $role = Role::query()->create(['key' => $key, 'name' => $key]);

            foreach ($actions as $action) {
                $role->permissions()->create(['permission' => $action]);
            }

            $role->assignments()->create([
                'user_id' => $split->getKey(),
                'org_unit_id' => $unit->getKey(),
                'includes_descendants' => true,
            ]);
        };

        $overABranch('keeps_shimla', [Permission::ViewPerson, Permission::UpdatePerson], meridiansBranchCalled('Shimla branch'));
        $overABranch('reads_pune', [Permission::ViewStatutoryId], meridiansBranchCalled('Pune branch'));

        app(PermissionResolver::class)->forget();

        Livewire::actingAs($split)
            ->test(StatutoryNumbersRelationManager::class, [
                'ownerRecord' => $deepak,
                'pageClass' => EditUser::class,
            ])
            ->assertOk()
            ->assertTableColumnStateSet('value', 'On file — not yours to see', $account)
            // Being told it is not theirs to see and being able to destroy it were two
            // different answers until the record's own reader decided both.
            ->assertActionHidden(TestAction::make('delete')->table($account))
            ->assertActionHidden(TestAction::make('create')->table());

        expect($split->can('delete', $account))->toBeFalse();
        expect($deepak->statutoryIds()->count())->toBe(1);
    });
});
