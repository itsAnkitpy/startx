<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;

/*
| Anjali is HR head for Meridian's Freight business line. Under Freight sits Freight
| North, and under that a depot. The question these tests ask is how far down her grant
| reaches, and the answer has to be the client's choice rather than ours: "HR head for
| this one branch" and "finance controller for this line and everything under it" must
| not be the same row.
|
| Nothing here asks which role anybody holds. Every question is about an action.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
    $this->resolver = app(PermissionResolver::class);
});

/**
 * Meridian's structure, three levels, plus a role carrying one action.
 *
 * @return array{company: OrgUnit, freight: OrgUnit, freightNorth: OrgUnit, depot: OrgUnit, retail: OrgUnit, role: Role}
 */
function meridianStructure(): array
{
    $company = OrgUnit::create(['type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.']);
    $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight', 'parent_id' => $company->getKey()]);
    $retail = OrgUnit::create(['type' => 'business_line', 'name' => 'Retail Fulfilment', 'parent_id' => $company->getKey()]);
    $freightNorth = OrgUnit::create(['type' => 'sub_business_line', 'name' => 'Freight North', 'parent_id' => $freight->getKey()]);
    $depot = OrgUnit::create(['type' => 'sub_business_line', 'name' => 'Ludhiana Depot', 'parent_id' => $freightNorth->getKey()]);

    return [
        'company' => $company,
        'freight' => $freight,
        'retail' => $retail,
        'freightNorth' => $freightNorth,
        'depot' => $depot,
        'role' => Role::factory()->keyed('hr_head')->withPermissions([Permission::ViewPerson])->create(),
    ];
}

it('keeps a grant on one branch out of the branch below it', function () {
    TenantContext::run($this->meridian, function () {
        $tree = meridianStructure();
        $anjali = User::factory()->create(['name' => 'Anjali Rao']);

        $anjali->roleAssignments()->create([
            'role_id' => $tree['role']->getKey(),
            'org_unit_id' => $tree['freight']->getKey(),
            'includes_descendants' => false,
        ]);

        expect($this->resolver->allows($anjali, Permission::ViewPerson, $tree['freight']))->toBeTrue()
            ->and($this->resolver->allows($anjali, Permission::ViewPerson, $tree['freightNorth']))->toBeFalse()
            ->and($this->resolver->allows($anjali, Permission::ViewPerson, $tree['retail']))->toBeFalse();
    });
});

it('reaches everything below when the grant says to', function () {
    TenantContext::run($this->meridian, function () {
        $tree = meridianStructure();
        $anjali = User::factory()->create(['name' => 'Anjali Rao']);

        $anjali->roleAssignments()->create([
            'role_id' => $tree['role']->getKey(),
            'org_unit_id' => $tree['freight']->getKey(),
            'includes_descendants' => true,
        ]);

        expect($this->resolver->allows($anjali, Permission::ViewPerson, $tree['freight']))->toBeTrue()
            ->and($this->resolver->allows($anjali, Permission::ViewPerson, $tree['freightNorth']))->toBeTrue()
            // Two levels down, so this is the recursive walk upwards doing the work
            // rather than a single parent check.
            ->and($this->resolver->allows($anjali, Permission::ViewPerson, $tree['depot']))->toBeTrue()
            // Still nothing in a sibling branch.
            ->and($this->resolver->allows($anjali, Permission::ViewPerson, $tree['retail']))->toBeFalse()
            // And a grant lower down never reaches upwards.
            ->and($this->resolver->allows($anjali, Permission::ViewPerson, $tree['company']))->toBeFalse();
    });
});

it('covers the whole client company when a grant names no unit', function () {
    TenantContext::run($this->meridian, function () {
        $tree = meridianStructure();
        $anjali = User::factory()->create(['name' => 'Anjali Rao']);

        $anjali->roleAssignments()->create(['role_id' => $tree['role']->getKey()]);

        expect($this->resolver->allows($anjali, Permission::ViewPerson, $tree['depot']))->toBeTrue()
            ->and($this->resolver->allows($anjali, Permission::ViewPerson, $tree['retail']))->toBeTrue()
            ->and($this->resolver->allows($anjali, Permission::ViewPerson))->toBeTrue();
    });
});

it('says no to an action the person\'s roles do not carry', function () {
    TenantContext::run($this->meridian, function () {
        $tree = meridianStructure();
        $anjali = User::factory()->create(['name' => 'Anjali Rao']);

        $anjali->roleAssignments()->create(['role_id' => $tree['role']->getKey()]);

        // Her role carries viewing a person and nothing else.
        expect($this->resolver->allows($anjali, Permission::ViewPerson))->toBeTrue()
            ->and($this->resolver->allows($anjali, Permission::DeactivatePerson))->toBeFalse()
            ->and($this->resolver->allows($anjali, Permission::ManageRole))->toBeFalse();
    });
});

it('says no to someone holding no role at all', function () {
    TenantContext::run($this->meridian, function () {
        $tree = meridianStructure();
        $priya = User::factory()->create(['name' => 'Priya Nair']);

        expect($this->resolver->allows($priya, Permission::ViewPerson))->toBeFalse()
            ->and($this->resolver->allows($priya, Permission::ViewPerson, $tree['freight']))->toBeFalse();
    });
});

it('answers the same question per client company, not once per person', function () {
    // The trap module 06's scheduled pass would otherwise walk into: it loops over client
    // companies inside one process, and one resolver instance serves the whole loop.
    //
    // The question deliberately concerns the *same* account both times. Asking about two
    // different people would prove nothing, because two accounts never share an id — the
    // key would differ whether or not the client company were part of it. Only repeating
    // the identical question under a second company exercises that part of the key.
    //
    // Anjali's grant belongs to Meridian, so under Vertex there is nothing to find and
    // the honest answer is no. Remembered without the company in the key, Meridian's yes
    // would be handed back.
    $anjali = TenantContext::run($this->meridian, function () {
        $role = Role::factory()->keyed('hr_head')->withPermissions([Permission::ViewPerson])->create();
        $anjali = User::factory()->create(['name' => 'Anjali Rao']);
        $anjali->roleAssignments()->create(['role_id' => $role->getKey()]);

        return $anjali;
    });

    // One resolver instance across both, deliberately: a fresh one per company would
    // prove nothing.
    $atMeridian = TenantContext::run(
        $this->meridian,
        fn () => $this->resolver->allows($anjali, Permission::ViewPerson),
    );

    $atVertex = TenantContext::run(
        $this->vertex,
        fn () => $this->resolver->allows($anjali, Permission::ViewPerson),
    );

    expect($atMeridian)->toBeTrue()->and($atVertex)->toBeFalse();
});

it('counts one person holding the role twice as one grant, not two answers', function () {
    TenantContext::run($this->meridian, function () {
        $tree = meridianStructure();
        $anjali = User::factory()->create(['name' => 'Anjali Rao']);

        $anjali->roleAssignments()->create([
            'role_id' => $tree['role']->getKey(),
            'org_unit_id' => $tree['freight']->getKey(),
        ]);

        $anjali->roleAssignments()->create([
            'role_id' => $tree['role']->getKey(),
            'org_unit_id' => $tree['retail']->getKey(),
        ]);

        expect($this->resolver->allows($anjali, Permission::ViewPerson, $tree['freight']))->toBeTrue()
            ->and($this->resolver->allows($anjali, Permission::ViewPerson, $tree['retail']))->toBeTrue()
            ->and($this->resolver->allows($anjali, Permission::ViewPerson, $tree['depot']))->toBeFalse();
    });
});
