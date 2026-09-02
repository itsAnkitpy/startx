<?php

use App\Authorization\AdministratorFloor;
use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RelationManagers\WhoHoldsItRelationManager;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| The client's own roles, and under each one the people who hold it.
|
| What is being checked beyond the rows appearing:
|
| A client reads what a role can do in ordinary words — every action the code checks has a
| sentence beside it, so a name added by a later module cannot reach a client's screen as a
| bare code name.
|
| Rakesh runs HR over the Shimla branch alone. Given the role-managing action over Shimla,
| he may grant a role over Shimla and nowhere else — not because he lacks the action, which
| he holds, but because the picker never offered him Pune. He also cannot make that grant
| reach downwards past what his own covers.
|
| Taking away the second-to-last administrator is refused, and the refusal is a sentence
| naming the way out rather than an error page. This is the first screen on which either of
| the two refusals the security review fixed can be reached by a person at all.
|
| And nobody can untick the two actions the Administrator role needs to put anything back,
| because we have no way of letting a locked-out client back in.
|
| It runs against the demo company as seeded, so what is checked is the screen Ankit opens.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function whoWorksForMeridian(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** One part of the demo company's structure, by name. */
function meridiansPartCalled(string $name): OrgUnit
{
    return OrgUnit::query()->where('name', $name)->sole();
}

/** The address of this screen on the demo company. */
function meridiansRolesAddress(string $path): string
{
    return 'http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/'.$path;
}

/** One of the demo company's seeded roles, by its permanent internal name. */
function meridiansRoleKeyed(string $key): Role
{
    return Role::query()->where('key', $key)->sole();
}

/**
 * Give somebody a role carrying exactly these actions and nothing else, over the whole
 * company or over one named part of it.
 *
 * @param  list<string>  $actions
 */
function giveMeridianStaffARoleFor(User $person, string $key, array $actions, ?OrgUnit $over = null): void
{
    $role = Role::query()->create(['key' => $key, 'name' => Str::headline($key)]);

    foreach ($actions as $action) {
        $role->permissions()->create(['permission' => $action]);
    }

    $role->assignments()->create([
        'user_id' => $person->getKey(),
        'org_unit_id' => $over?->getKey(),
        'includes_descendants' => false,
    ]);

    app(PermissionResolver::class)->forget();
}

/** Who holds one role, as that role's own page draws it. */
function meridiansHoldersOf(Role $role, User $asSeenBy): Testable
{
    return Livewire::actingAs($asSeenBy)->test(WhoHoldsItRelationManager::class, [
        'ownerRecord' => $role,
        'pageClass' => EditRole::class,
    ]);
}

it('shows an administrator every role the company has, with what each can do and who holds it', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(whoWorksForMeridian('chandni'))->test(ListRoles::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Role::query()->orderBy('name')->get())
            ->assertSee('Administrator')
            ->assertSee('HR head')
            // Words rather than bare numbers on both counts, so a role that can do nothing
            // and a role two people share both read as states rather than as digits. The
            // demo has two roles carrying no actions at all, on purpose — approving a step
            // is whose job the step is, not a granted action.
            ->assertSee('Nothing yet')
            ->assertSee('2 people');
    });
});

it('keeps the roles screen away from somebody with no role at all', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(whoWorksForMeridian('anjali'))->test(ListRoles::class)
            ->assertForbidden();
    });
});

it('describes every action the code checks in words a client can read', function () {
    $described = collect(Permission::describedForAClient())
        ->flatMap(fn (array $group): array => array_keys($group['actions']))
        ->all();

    // The list of actions is read off this class's own constants by reflection, so a name
    // added by a later module arrives on this screen whether or not anybody wrote words for
    // it. This is what stops it arriving as `view_org_unit`.
    expect($described)->toEqualCanonicalizing(Permission::all());

    foreach (Permission::describedForAClient() as $group) {
        expect($group['heading'])->not->toBeEmpty();

        foreach ($group['actions'] as $name => $words) {
            expect($words['label'])->not->toContain('_')
                ->and($words['label'])->not->toBe($name)
                ->and($words['description'])->not->toBeEmpty();
        }
    }
});

it('adds a role from its name alone and lands on its own page to be filled in', function () {
    TenantContext::run($this->meridian, function () {
        $screen = Livewire::actingAs(whoWorksForMeridian('chandni'))->test(ListRoles::class)
            ->callAction('create', ['name' => 'Branch People Lead', 'description' => 'Runs hiring for one branch'])
            ->assertHasNoActionErrors();

        $added = Role::query()->where('name', 'Branch People Lead')->sole();

        // Naming it and saying what it does are two decisions, so adding one lands on the
        // role's own page with the tick-boxes empty and asking to be filled.
        $screen->assertRedirect(RoleResource::getUrl('edit', ['record' => $added]));

        // The client never sees or types the permanent internal name, so it is worked out
        // from what they did type.
        expect($added->key)->toBe('branch_people_lead')
            ->and($added->is_system)->toBeFalse()
            ->and($added->permissionNames())->toBe([]);
    });
});

it('refuses a second role with a name the company already uses', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(whoWorksForMeridian('chandni'))->test(ListRoles::class)
            ->callAction('create', ['name' => 'hr head'])
            ->assertHasActionErrors(['name']);

        // Two roles named alike would collide on the internal name nobody chose, which
        // would otherwise reach the client as an error page about a field not on the form.
        expect(Role::query()->where('key', 'hr_head')->count())->toBe(1);
    });
});

it('writes the ticked actions onto the role and takes the unticked ones off', function () {
    TenantContext::run($this->meridian, function () {
        $hrHead = meridiansRoleKeyed('hr_head');

        expect($hrHead->permissionNames())->toContain(Permission::CreatePerson);

        Livewire::actingAs(whoWorksForMeridian('chandni'))->test(EditRole::class, ['record' => $hrHead->getKey()])
            ->assertOk()
            ->assertSee('See the people at the company')
            // Four headings, grouped by the thing each action is about, which is how the
            // comparable products put a permission list in front of a customer.
            ->assertSee('Departments and branches')
            ->assertSee('People and their records')
            ->assertSee("Your company's own lists")
            ->assertSee('Roles and company settings')
            ->fillForm([
                'actions.structure' => [],
                'actions.people' => [Permission::ViewPerson, Permission::UpdatePerson],
                'actions.lists' => [Permission::ManageReferenceList],
                'actions.control' => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($hrHead->fresh()->permissionNames())->toEqualCanonicalizing([
            Permission::ViewPerson,
            Permission::UpdatePerson,
            Permission::ManageReferenceList,
        ]);
    });
});

it('keeps the administrator role able to see and manage roles whatever is unticked', function () {
    TenantContext::run($this->meridian, function () {
        $administrator = meridiansRoleKeyed(Role::AdministratorKey);

        Livewire::actingAs(whoWorksForMeridian('chandni'))->test(EditRole::class, ['record' => $administrator->getKey()])
            ->fillForm([
                'actions.structure' => [],
                'actions.people' => [],
                'actions.lists' => [],
                'actions.control' => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // Everything else really does come off — this is not a role that refuses to change.
        // The two that stay are the two that would take the roles screen away from the only
        // role that can put it back, and we build no way of letting a locked-out client in.
        expect($administrator->fresh()->permissionNames())->toEqualCanonicalizing([
            Permission::ViewRole,
            Permission::ManageRole,
        ]);
    });
});

it('grants a role to somebody over one part of the company', function () {
    TenantContext::run($this->meridian, function () {
        $hrHead = meridiansRoleKeyed('hr_head');
        $shimla = meridiansPartCalled('Shimla branch');

        meridiansHoldersOf($hrHead, whoWorksForMeridian('chandni'))
            ->callAction(TestAction::make('create')->table(), data: [
                'user_id' => whoWorksForMeridian('deepak')->getKey(),
                'org_unit_id' => $shimla->getKey(),
                'includes_descendants' => false,
            ])
            ->assertHasNoActionErrors();

        expect(RoleAssignment::query()
            ->where('role_id', $hrHead->getKey())
            ->where('user_id', whoWorksForMeridian('deepak')->getKey())
            ->where('org_unit_id', $shimla->getKey())
            ->exists())->toBeTrue();
    });
});

it('refuses a grant over a branch the granter does not cover', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh holds the role-managing action, over Shimla alone. Whether he may create a
        // grant is asked before the grant exists, so it cannot say which branch the grant
        // will name — the picker's own narrowing is what refuses Pune.
        $rakesh = whoWorksForMeridian('rakesh');
        giveMeridianStaffARoleFor($rakesh, 'shimlas_roles', [
            Permission::ViewRole,
            Permission::ManageRole,
        ], over: meridiansPartCalled('Shimla branch'));

        meridiansHoldersOf(meridiansRoleKeyed('hr_head'), $rakesh)
            ->callAction(TestAction::make('create')->table(), data: [
                'user_id' => whoWorksForMeridian('rohit')->getKey(),
                'org_unit_id' => meridiansPartCalled('Pune branch')->getKey(),
                'includes_descendants' => false,
            ])
            ->assertHasActionErrors(['org_unit_id']);
    });
});

it('refuses a grant over the whole company from somebody who covers one branch', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = whoWorksForMeridian('rakesh');
        giveMeridianStaffARoleFor($rakesh, 'shimlas_roles', [
            Permission::ViewRole,
            Permission::ManageRole,
        ], over: meridiansPartCalled('Shimla branch'));

        meridiansHoldersOf(meridiansRoleKeyed('hr_head'), $rakesh)
            ->callAction(TestAction::make('create')->table(), data: [
                'user_id' => whoWorksForMeridian('deepak')->getKey(),
                'org_unit_id' => null,
                'includes_descendants' => false,
            ])
            ->assertHasActionErrors(['org_unit_id']);
    });
});

it('refuses a grant that would reach further down the company than the granter does', function () {
    TenantContext::run($this->meridian, function () {
        // Chandni's own grant is company-wide, so she may reach downwards. Rakesh's covers
        // the Shimla branch alone, and nothing below it, so granting "Shimla and everything
        // under it" would hand out more of the company than he holds — and would keep
        // growing as departments are added underneath.
        $rakesh = whoWorksForMeridian('rakesh');
        $shimla = meridiansPartCalled('Shimla branch');
        giveMeridianStaffARoleFor($rakesh, 'shimlas_roles', [
            Permission::ViewRole,
            Permission::ManageRole,
        ], over: $shimla);

        OrgUnit::query()->create([
            'name' => 'Shimla despatch',
            'type' => 'department',
            'parent_id' => $shimla->getKey(),
            'active' => true,
        ]);

        meridiansHoldersOf(meridiansRoleKeyed('hr_head'), $rakesh)
            ->callAction(TestAction::make('create')->table(), data: [
                'user_id' => whoWorksForMeridian('deepak')->getKey(),
                'org_unit_id' => $shimla->getKey(),
                'includes_descendants' => true,
            ])
            ->assertHasActionErrors(['includes_descendants']);
    });
});

it('refuses granting the same role to the same person over the same part twice', function () {
    TenantContext::run($this->meridian, function () {
        // The database holds one of these per person per role per part, counting the
        // whole-company grant as one of them, and a refused insert reaches a client as an
        // error page saying nothing at all.
        meridiansHoldersOf(meridiansRoleKeyed('hr_head'), whoWorksForMeridian('chandni'))
            ->callAction(TestAction::make('create')->table(), data: [
                'user_id' => whoWorksForMeridian('rakesh')->getKey(),
                'org_unit_id' => meridiansPartCalled('Shimla branch')->getKey(),
                'includes_descendants' => false,
            ])
            ->assertHasActionErrors(['user_id']);
    });
});

it('shows a branch HR head the grants over their own branch and not another branch', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = whoWorksForMeridian('rakesh');
        $shimla = meridiansPartCalled('Shimla branch');
        giveMeridianStaffARoleFor($rakesh, 'shimlas_roles', [
            Permission::ViewRole,
            Permission::ManageRole,
        ], over: $shimla);

        $hrHead = meridiansRoleKeyed('hr_head');

        $overPune = $hrHead->assignments()->create([
            'user_id' => whoWorksForMeridian('rohit')->getKey(),
            'org_unit_id' => meridiansPartCalled('Pune branch')->getKey(),
            'includes_descendants' => false,
        ]);

        $overShimla = $hrHead->assignments()
            ->where('org_unit_id', $shimla->getKey())
            ->firstOrFail();

        meridiansHoldersOf($hrHead, $rakesh)
            ->assertCanSeeTableRecords([$overShimla])
            ->assertCanNotSeeTableRecords([$overPune]);
    });
});

it('takes a role away and says so', function () {
    TenantContext::run($this->meridian, function () {
        $hrHead = meridiansRoleKeyed('hr_head');
        $priyas = $hrHead->assignments()->where('user_id', whoWorksForMeridian('priya')->getKey())->firstOrFail();

        meridiansHoldersOf($hrHead, whoWorksForMeridian('chandni'))
            ->callAction(TestAction::make('revoke')->table($priyas))
            ->assertNotified();

        expect(RoleAssignment::query()->whereKey($priyas->getKey())->exists())->toBeFalse();
    });
});

it('refuses taking away the administrator role that would leave the company with one', function () {
    TenantContext::run($this->meridian, function () {
        $administrator = meridiansRoleKeyed(Role::AdministratorKey);

        expect(AdministratorFloor::count())->toBe(2);

        $priyas = $administrator->assignments()
            ->where('user_id', whoWorksForMeridian('priya')->getKey())
            ->firstOrFail();

        // Said in a sentence naming the way out, rather than reaching the client as an
        // error page from inside the model. This is the first screen from which this
        // refusal can be reached by a person at all.
        meridiansHoldersOf($administrator, whoWorksForMeridian('chandni'))
            ->callAction(TestAction::make('revoke')->table($priyas))
            ->assertNotified('This one cannot be taken away');

        expect(RoleAssignment::query()->whereKey($priyas->getKey())->exists())->toBeTrue()
            ->and(AdministratorFloor::count())->toBe(2);
    });
});

it('draws the list and one role\'s whole page in a real request', function () {
    $chandni = TenantContext::run($this->meridian, fn () => whoWorksForMeridian('chandni'));
    $hrHead = TenantContext::run($this->meridian, fn () => meridiansRoleKeyed('hr_head'));

    // The component tests above draw each part on its own. This asks for the addresses
    // themselves, so the role's own page is proved to draw with the list of who holds it
    // mounted underneath it.
    //
    // The list's own title is deliberately not looked for here: a list underneath a page
    // loads when it comes into view, so the page's first response carries the list's name
    // and none of its contents. The people screen shows its two lists' titles straight away
    // only because two of them are drawn as tabs.
    $this->actingAs($chandni)->get(meridiansRolesAddress('roles'))
        ->assertOk()
        ->assertSee('Roles and who holds them');

    $this->actingAs($chandni)->get(meridiansRolesAddress('roles/'.$hrHead->getKey().'/edit'))
        ->assertOk()
        ->assertSee('HR head')
        ->assertSee('People and their records')
        ->assertSeeLivewire(WhoHoldsItRelationManager::class);
});

it('still refuses taking the second-to-last administrator away when branch administrators remain', function () {
    TenantContext::run($this->meridian, function () {
        $administrator = meridiansRoleKeyed(Role::AdministratorKey);
        $shimla = meridiansPartCalled('Shimla branch');
        $chandni = whoWorksForMeridian('chandni');

        // The lockout this screen made reachable for the first time. Handing the
        // administrator role to two people in one branch and then dropping both
        // company-wide administrators would leave nobody able to grant a role over the
        // rest of the company, and we have no way of letting a client back in.
        foreach (['deepak', 'anjali'] as $first) {
            meridiansHoldersOf($administrator, $chandni)
                ->callAction(TestAction::make('create')->table(), data: [
                    'user_id' => whoWorksForMeridian($first)->getKey(),
                    'org_unit_id' => $shimla->getKey(),
                    'includes_descendants' => false,
                ])
                ->assertHasNoActionErrors();

            app(PermissionResolver::class)->forget();
        }

        $priyas = $administrator->assignments()
            ->where('user_id', whoWorksForMeridian('priya')->getKey())
            ->whereNull('org_unit_id')
            ->firstOrFail();

        meridiansHoldersOf($administrator, $chandni)
            ->callAction(TestAction::make('revoke')->table($priyas))
            ->assertNotified('This one cannot be taken away');

        expect(RoleAssignment::query()->whereKey($priyas->getKey())->exists())->toBeTrue();
    });
});

it('lets somebody responsible for one branch hand a role out there without changing what it does', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh runs roles for the Shimla branch. A role's actions are one list for the
        // whole company, so ticking one on would reach every branch — including onto a
        // role he holds himself, which is how he would walk out of his own branch.
        $rakesh = whoWorksForMeridian('rakesh');
        giveMeridianStaffARoleFor($rakesh, 'shimlas_roles', [
            Permission::ViewRole,
            Permission::ManageRole,
        ], over: meridiansPartCalled('Shimla branch'));

        $hrDirector = meridiansRoleKeyed('hr_director');
        $before = $hrDirector->permissionNames();

        expect($before)->not->toBe([]);

        $page = Livewire::actingAs($rakesh)->test(EditRole::class, ['record' => $hrDirector->getKey()])
            ->assertOk()
            ->assertFormFieldDisabled('actions.people')
            ->assertFormFieldDisabled('name');

        // And the refusal is on the page rather than only on the tick-box, because a
        // disabled tick-box can be re-enabled from the browser.
        $page->fillForm([
            'actions.structure' => [],
            'actions.people' => [Permission::ViewStatutoryId],
            'actions.lists' => [],
            'actions.control' => [Permission::ManageSettings],
        ])->call('save');

        expect($hrDirector->fresh()->permissionNames())->toEqualCanonicalizing($before);
    });
});

it('shows the list of roles to somebody who may only see them', function () {
    TenantContext::run($this->meridian, function () {
        $reader = whoWorksForMeridian('deepak');
        giveMeridianStaffARoleFor($reader, 'roles_reader', [Permission::ViewRole]);

        // What the tick-box promises and no more: the names and the counts. Reading what
        // a role can do, and who holds it, is the next tick-box along.
        Livewire::actingAs($reader)->test(ListRoles::class)
            ->assertOk()
            ->assertSee('Administrator');

        Livewire::actingAs($reader)->test(EditRole::class, ['record' => meridiansRoleKeyed('hr_head')->getKey()])
            ->assertForbidden();
    });
});

it('changes the Administrator role without asking the client to untick anything first', function () {
    TenantContext::run($this->meridian, function () {
        $administrator = meridiansRoleKeyed(Role::AdministratorKey);

        // The two locked tick-boxes arrive already ticked, so a screen that treated a
        // locked answer as an answer nobody may give would refuse every save of this
        // role — over a tick-box with no label to name in the complaint.
        Livewire::actingAs(whoWorksForMeridian('chandni'))->test(EditRole::class, ['record' => $administrator->getKey()])
            ->fillForm(['description' => 'Runs everything'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($administrator->fresh()->description)->toBe('Runs everything')
            ->and($administrator->fresh()->permissionNames())
            ->toContain(Permission::ViewRole)
            ->toContain(Permission::ManageRole);
    });
});

it('refuses somebody responsible for one branch taking away a grant that covers the whole company', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = whoWorksForMeridian('rakesh');
        giveMeridianStaffARoleFor($rakesh, 'shimlas_roles', [
            Permission::ViewRole,
            Permission::ManageRole,
        ], over: meridiansPartCalled('Shimla branch'));

        // Chandni is HR director for the whole company. Rakesh is shown her grant on
        // purpose — a grant over everything is everybody's business — but taking it away
        // is not his to do, and asking whether he holds the action "anywhere" said it was.
        $hrDirector = meridiansRoleKeyed('hr_director');
        $chandnis = $hrDirector->assignments()->whereNull('org_unit_id')->firstOrFail();

        meridiansHoldersOf($hrDirector, $rakesh)
            ->assertCanSeeTableRecords([$chandnis])
            ->assertActionHidden(TestAction::make('revoke')->table($chandnis));

        expect(RoleAssignment::query()->whereKey($chandnis->getKey())->exists())->toBeTrue();
    });
});

it('counts only the holders the reader can see', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = whoWorksForMeridian('rakesh');
        giveMeridianStaffARoleFor($rakesh, 'shimlas_roles', [
            Permission::ViewRole,
            Permission::ManageRole,
        ], over: meridiansPartCalled('Shimla branch'));

        $hrHead = meridiansRoleKeyed('hr_head');

        $hrHead->assignments()->create([
            'user_id' => whoWorksForMeridian('rohit')->getKey(),
            'org_unit_id' => meridiansPartCalled('Pune branch')->getKey(),
            'includes_descendants' => false,
        ]);

        // Three people hold HR head, one of them in Pune. Rakesh runs Shimla, so the
        // number beside the role has to be the number of rows he is shown on it.
        Livewire::actingAs(whoWorksForMeridian('chandni'))->test(ListRoles::class)
            ->assertSee('3 people');

        Livewire::actingAs($rakesh)->test(ListRoles::class)
            ->assertSee('2 people')
            ->assertDontSee('3 people');
    });
});
