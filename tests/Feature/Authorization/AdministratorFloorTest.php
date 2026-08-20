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
        $rakesh = User::factory()->inactive()->create(['name' => 'Rakesh Iyer']);

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

it('counts one person administering two branches as one administrator', function () {
    TenantContext::run($this->meridian, function () {
        $role = StarterRoles::seed()[Role::AdministratorKey];
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $retail = OrgUnit::create(['type' => 'business_line', 'name' => 'Retail Fulfilment']);

        $anjali = User::factory()->create(['name' => 'Anjali Rao']);
        $anjali->roleAssignments()->create(['role_id' => $role->getKey(), 'org_unit_id' => $freight->getKey()]);
        $anjali->roleAssignments()->create(['role_id' => $role->getKey(), 'org_unit_id' => $retail->getKey()]);

        expect(AdministratorFloor::count())->toBe(1);
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
