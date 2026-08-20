<?php

use App\Authorization\Permission;
use App\Authorization\StarterRoles;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;

use function Filament\authorize;

/*
| Filament's own documentation says plainly that when a policy is missing, or a method
| on it is missing, it *grants* access — it assumes authorization has not been set up
| yet. For a product whose whole claim is an attributable trail over salary and
| settlement figures, a forgotten policy has to fail loudly instead.
|
| `Filament\authorize()` is the function every resource page and every built-in action
| goes through, so that is what these tests call. It needs no screen to exist, which is
| why step 3 does not build one.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

it('throws rather than opening a screen for a model with no policy', function () {
    TenantContext::run($this->meridian, function () {
        $this->actingAs(User::factory()->create());

        // The client company table itself has no policy on purpose: it is not a client
        // screen, it belongs to the platform admin area outside every client company. So
        // if a client-facing screen for it ever appears, this is what stops it opening
        // quietly.
        authorize('view', Tenant::class);
    });
})->throws(LogicException::class, 'Strict authorization mode is enabled');

it('answers from the policy where there is one', function () {
    TenantContext::run($this->meridian, function () {
        $roles = StarterRoles::seed();
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

        $anjali = User::factory()->named('Anjali Rao')->create();
        $anjali->roleAssignments()->create([
            'role_id' => $roles['hr_head']->getKey(),
            'org_unit_id' => $freight->getKey(),
        ]);

        $this->actingAs($anjali);

        // Her role can view a structure unit, so this returns rather than throwing.
        expect(authorize('view', $freight)->allowed())->toBeTrue();

        // It cannot change one, and that is a denial from the policy — not a missing
        // policy, which would have thrown.
        expect(\Filament\get_authorization_response('update', $freight)->allowed())->toBeFalse();
    });
});

it('denies a person whose role carries none of the actions', function () {
    TenantContext::run($this->meridian, function () {
        $roles = StarterRoles::seed();
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

        $priya = User::factory()->named('Priya Nair')->create();
        $priya->roleAssignments()->create(['role_id' => $roles['employee']->getKey()]);

        $this->actingAs($priya);

        expect(\Filament\get_authorization_response('view', $freight)->allowed())->toBeFalse();
    });
});

it('refuses to delete a person at any permission level', function () {
    // A person's record is the evidence behind their exit, their settlement, and any
    // dispute afterwards — and a disputed settlement line is argued after they have gone.
    // An account that should no longer sign in is deactivated instead.
    TenantContext::run($this->meridian, function () {
        $role = Role::factory()->withPermissions(Permission::all())->create();

        $anjali = User::factory()->named('Anjali Rao')->create();
        $anjali->roleAssignments()->create(['role_id' => $role->getKey()]);

        $rakesh = User::factory()->named('Rakesh Iyer')->create();

        $this->actingAs($anjali);

        expect(\Filament\get_authorization_response('delete', $rakesh)->allowed())->toBeFalse()
            ->and(\Filament\get_authorization_response('deactivate', $rakesh)->allowed())->toBeTrue();
    });
});
