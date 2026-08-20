<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Authorization\StarterRoles;
use App\Exceptions\SystemRoleProtected;
use App\Exceptions\UnknownPermission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/*
| Roles are rows per client company, with a permanent internal name underneath a label
| the client edits. That is what lets Meridian call a role "People Lead" and Vertex call
| the same internal role "HR Manager", give them different actions, and have neither
| affect the other's answers.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
});

it('gives a new client company a starter set it can rename and prune', function () {
    TenantContext::run($this->meridian, function () {
        $roles = StarterRoles::seed();

        expect(Role::query()->count())->toBe(count(StarterRoles::definitions()))
            ->and($roles[Role::AdministratorKey]->permissionNames())
            ->toEqualCanonicalizing(Permission::all());

        // Running it twice must not double the rows — a client company can be set up
        // again after a failure part-way through.
        StarterRoles::seed();

        expect(Role::query()->count())->toBe(count(StarterRoles::definitions()));
    });
});

it('does not change any answer when a client renames a role', function () {
    // This is the test that holds the whole rule: code asks whether a person may perform
    // an action, never whether they hold a named role. The label is renamed between two
    // otherwise identical checks.
    TenantContext::run($this->meridian, function () {
        $resolver = app(PermissionResolver::class);

        $role = Role::factory()
            ->keyed('hr_head', 'HR Head')
            ->withPermissions([Permission::ViewPerson])
            ->create();

        $anjali = User::factory()->create(['name' => 'Anjali Rao']);
        $anjali->roleAssignments()->create(['role_id' => $role->getKey()]);

        $before = $resolver->allows($anjali, Permission::ViewPerson);

        $role->update(['name' => 'People Lead']);

        // Without this the second check would come back from memory and the test could
        // not fail even if a check did read the label.
        $resolver->forget();

        $after = $resolver->allows($anjali, Permission::ViewPerson);

        expect($before)->toBeTrue()->and($after)->toBeTrue();
    });
});

it('keeps two client companies using one internal role name entirely separate', function () {
    $resolver = app(PermissionResolver::class);

    $anjali = TenantContext::run($this->meridian, function () {
        $role = Role::factory()
            ->keyed('hr_head', 'People Lead')
            ->withPermissions([Permission::ViewPerson, Permission::UpdatePerson])
            ->create();

        $anjali = User::factory()->create(['name' => 'Anjali Rao']);
        $anjali->roleAssignments()->create(['role_id' => $role->getKey()]);

        return $anjali;
    });

    $deepak = TenantContext::run($this->vertex, function () {
        // Same internal name, different label, deliberately a shorter action list.
        $role = Role::factory()
            ->keyed('hr_head', 'HR Manager')
            ->withPermissions([Permission::ViewPerson])
            ->create();

        $deepak = User::factory()->create(['name' => 'Deepak Verma']);
        $deepak->roleAssignments()->create(['role_id' => $role->getKey()]);

        return $deepak;
    });

    TenantContext::run($this->meridian, function () use ($resolver, $anjali) {
        expect($resolver->allows($anjali, Permission::UpdatePerson))->toBeTrue();
    });

    TenantContext::run($this->vertex, function () use ($resolver, $deepak) {
        expect($resolver->allows($deepak, Permission::UpdatePerson))->toBeFalse();
    });
});

it('refuses an action name that no code performs', function () {
    TenantContext::run($this->meridian, function () {
        $role = Role::factory()->create();

        $role->permissions()->create(['permission' => 'approve_everything']);
    });
})->throws(UnknownPermission::class);

it('refuses to change a role\'s permanent internal name', function () {
    TenantContext::run($this->meridian, function () {
        $role = Role::factory()->keyed('hr_head')->create();

        $role->update(['key' => 'people_lead']);
    });
})->throws(SystemRoleProtected::class);

it('refuses to delete a seeded role', function () {
    // Deleting one takes its grants with it through a database cascade that no model
    // event sees — so deleting the administrator role would remove every administrator
    // in a single statement and never reach the two-administrator rule.
    TenantContext::run($this->meridian, function () {
        StarterRoles::seed();

        Role::query()->where('key', Role::AdministratorKey)->first()->delete();
    });
})->throws(SystemRoleProtected::class);

it('refuses a grant pointing at another client company\'s role', function () {
    $vertexRole = TenantContext::run($this->vertex, fn () => Role::factory()->create());

    TenantContext::run($this->meridian, function () use ($vertexRole) {
        $anjali = User::factory()->create();

        // The key carries the client company, so this reference cannot be made. Without
        // that, Postgres would check the key with policies bypassed and accept it.
        $anjali->roleAssignments()->create(['role_id' => $vertexRole->getKey()]);
    });
})->throws(QueryException::class);
