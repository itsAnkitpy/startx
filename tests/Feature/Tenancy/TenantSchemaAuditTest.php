<?php

use App\Models\Tenant;

/*
| The wall is a rule about every table, so it is checked against the live schema
| rather than against a list someone has to remember to update. A table added in a
| later module that skips any part of the rule fails here.
*/

it('protects every table owned by a client company', function () {
    $tables = tenantOwnedTables();

    $faults = [];

    foreach ($tables as $table) {
        $faults = array_merge($faults, tenantWallFaults($table, $tables));
    }

    expect($faults)->toBe([]);
});

it('catches a table that skipped the rule', function () {
    createWalledFixtureTables();

    // `wall_leaks` is built wrong on purpose, so the check above is shown to be
    // capable of failing rather than merely passing over an empty list.
    expect(implode(' ', tenantWallFaults('wall_leaks')))
        ->toContain('does not have row-level security switched on')
        ->toContain('does not force row-level security')
        ->toContain('has no tenant isolation policy')
        ->toContain('no unique constraint on the tenant and the id together')
        ->toContain('without carrying the tenant');

    expect(tenantWallFaults('wall_fixtures'))->toBe([]);

    // The sweep above hands the table list in so the schema is read once. Same answer either
    // way, or the sweep would be checking something weaker than this test.
    expect(tenantWallFaults('wall_leaks', tenantOwnedTables()))
        ->toBe(tenantWallFaults('wall_leaks'));
});

it('leaves the client company table itself outside the wall', function () {
    expect(tenantOwnedTables())->not->toContain('tenants');

    // Proof it is genuinely readable with no client company in scope, which is what
    // the subdomain lookup needs before anyone has authenticated.
    Tenant::factory()->create(['slug' => 'meridian']);

    expect(Tenant::query()->where('slug', 'meridian')->exists())->toBeTrue();
});
