<?php

use App\Authorization\AdministratorFloor;
use App\Authorization\StarterRoles;
use App\Exceptions\TooFewAdministrators;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;

/*
| Meridian must keep at least two administrators. That is not a preference — we refuse
| to build a platform path that rescues a locked-out client, because a mechanism whose
| only purpose is bypassing a client's own controls is worse than the lockout it
| prevents. So the lockout has to be made impossible instead.
|
| What is deliberately not refused: deactivating an account. An exit carries a
| two-working-day statutory clock, and holding one up to protect an administrator count
| is the wrong trade. Module 07's exit flow catches the same problem weeks earlier, by
| making a departing administrator appoint a replacement.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

/**
 * Give the client company in scope $count administrators and hand back their grants.
 *
 * @return list<RoleAssignment>
 */
function appointAdministrators(int $count): array
{
    $role = StarterRoles::seed()[Role::AdministratorKey];

    $grants = [];

    for ($i = 0; $i < $count; $i++) {
        $person = User::factory()->create();
        $grants[] = $person->roleAssignments()->create(['role_id' => $role->getKey()]);
    }

    return $grants;
}

it('lets an administrator go while two would remain', function () {
    TenantContext::run($this->meridian, function () {
        $grants = appointAdministrators(3);

        $grants[0]->delete();

        expect(AdministratorFloor::count())->toBe(2);
    });
});

it('refuses to remove an administrator when only one would remain', function () {
    TenantContext::run($this->meridian, function () {
        $grants = appointAdministrators(2);

        $grants[0]->delete();
    });
})->throws(TooFewAdministrators::class);

it('refuses to move an administrator onto another role when only one would remain', function () {
    // The other manual route down: not taking the grant away, but pointing it at a
    // different role.
    TenantContext::run($this->meridian, function () {
        $grants = appointAdministrators(2);
        $manager = Role::query()->where('key', 'manager')->firstOrFail();

        $grants[0]->update(['role_id' => $manager->getKey()]);
    });
})->throws(TooFewAdministrators::class);

it('refuses to move an administrator grant onto a leaver when only one would remain', function () {
    // The quietest of the three routes down, and the one found by reviewing rather than
    // by planning: nothing is deleted and no role changes, the grant is simply handed to
    // an account that can no longer sign in.
    TenantContext::run($this->meridian, function () {
        $grants = appointAdministrators(2);
        $rakesh = User::factory()->inactive()->named('Rakesh Iyer')->create();

        $grants[0]->update(['user_id' => $rakesh->getKey()]);
    });
})->throws(TooFewAdministrators::class);

it('does not error at a client company that is still being set up with one administrator', function () {
    // Refusing this would break setting a client up on their first day. One administrator
    // is a prompt to appoint a second, not an error — only a removal is refused.
    TenantContext::run($this->meridian, function () {
        appointAdministrators(1);

        expect(AdministratorFloor::count())->toBe(1);
    });
});

it('still refuses to remove the very last administrator', function () {
    TenantContext::run($this->meridian, function () {
        $grants = appointAdministrators(1);

        $grants[0]->delete();
    });
})->throws(TooFewAdministrators::class);

it('lets an exit deactivate an administrator without being blocked', function () {
    // The case that must not be refused. Rakesh is an administrator and today is his last
    // working day; the exit deactivates his account at midnight and cannot be held up.
    TenantContext::run($this->meridian, function () {
        $grants = appointAdministrators(2);
        $rakesh = $grants[0]->user;

        $rakesh->update(['active' => false]);

        expect($rakesh->fresh()->active)->toBeFalse()
            // And the count now honestly reads one, because a deactivated account cannot
            // sign in to administer anything.
            ->and(AdministratorFloor::count())->toBe(1);
    });
});

it('refuses to delete the account of an administrator when only one would remain', function () {
    // The grant rows go with the account through a database cascade that no model event
    // on the grant ever sees — GitLab carries an open bug of exactly this shape.
    TenantContext::run($this->meridian, function () {
        $grants = appointAdministrators(2);

        $grants[0]->user->delete();
    });
})->throws(TooFewAdministrators::class);

it('counts one person holding the administrator role twice as one administrator', function () {
    TenantContext::run($this->meridian, function () {
        $role = StarterRoles::seed()[Role::AdministratorKey];
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

        $anjali = User::factory()->named('Anjali Rao')->create();
        $anjali->roleAssignments()->create(['role_id' => $role->getKey()]);
        $anjali->roleAssignments()->create(['role_id' => $role->getKey(), 'org_unit_id' => $freight->getKey()]);

        expect(AdministratorFloor::count())->toBe(1);
    });
});

it('does not count somebody who administers one branch as one of the two', function () {
    TenantContext::run($this->meridian, function () {
        $role = StarterRoles::seed()[Role::AdministratorKey];
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

        User::factory()->named('Deepak Iyer')->create()
            ->roleAssignments()->create(['role_id' => $role->getKey(), 'org_unit_id' => $freight->getKey()]);

        // Two of these are not two people who can put anything back: an administrator for
        // one branch cannot grant a role over the rest of the company.
        expect(AdministratorFloor::count())->toBe(0);
    });
});

it('refuses to remove a company-wide administrator when branch administrators are all that would be left', function () {
    TenantContext::run($this->meridian, function () {
        $role = StarterRoles::seed()[Role::AdministratorKey];
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

        $grants = appointAdministrators(2);

        User::factory()->named('Deepak Iyer')->create()
            ->roleAssignments()->create(['role_id' => $role->getKey(), 'org_unit_id' => $freight->getKey()]);

        // The whole lockout this rule exists to prevent: a client hands the administrator
        // role to people in one branch, drops the administrators covering everything, and
        // is left unable to grant a role anywhere else — for good.
        $grants[0]->delete();
    });
})->throws(TooFewAdministrators::class);

it('lets a branch administrator go without asking about the floor', function () {
    TenantContext::run($this->meridian, function () {
        $role = StarterRoles::seed()[Role::AdministratorKey];
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

        // Only one administrator covers the whole company, which is below the floor
        // already. Taking a branch administrator away still locks nobody out.
        appointAdministrators(1);

        $branch = User::factory()->named('Deepak Iyer')->create()
            ->roleAssignments()->create(['role_id' => $role->getKey(), 'org_unit_id' => $freight->getKey()]);

        $branch->delete();

        expect(RoleAssignment::query()->whereKey($branch->getKey())->exists())->toBeFalse();
    });
});

it('lets a person drop one of their two administrator grants', function () {
    TenantContext::run($this->meridian, function () {
        $role = StarterRoles::seed()[Role::AdministratorKey];
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

        // Two other administrators keep the company above the floor.
        $grants = appointAdministrators(2);

        $anjali = $grants[0]->user;
        $extra = $anjali->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'org_unit_id' => $freight->getKey(),
        ]);

        $extra->delete();

        expect(AdministratorFloor::count())->toBe(2);
    });
});
