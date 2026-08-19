<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\WallFixture;

/*
| Every query in this file is written as raw SQL on purpose. The Eloquent scope is
| not in the picture, so anything that holds here is being held by the database. If
| a test in this file starts passing only through Eloquent, the wall is off.
*/

beforeEach(function () {
    createWalledFixtureTables();

    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods']);

    $this->meridianRow = TenantContext::run(
        $this->meridian,
        fn () => WallFixture::create(['name' => 'Meridian head office'])->getKey()
    );

    $this->vertexRow = TenantContext::run(
        $this->vertex,
        fn () => WallFixture::create(['name' => 'Vertex head office'])->getKey()
    );
});

it('hides one client company\'s rows from another, in raw SQL with no scope in the way', function () {
    $rows = TenantContext::run(
        $this->meridian,
        fn () => DB::select('select id, name from wall_fixtures')
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($this->meridianRow)
        ->and($rows[0]->name)->toBe('Meridian head office');
});

it('returns nothing at all when no client company has been named', function () {
    DB::statement('reset app.tenant_id');

    expect(DB::select('select id from wall_fixtures'))->toBe([]);
});

it('returns nothing, rather than a database error, when the marker is left empty', function () {
    DB::statement("set local app.tenant_id = ''");

    expect(DB::select('select id from wall_fixtures'))->toBe([]);
});

it('refuses a row stamped with another client company\'s id', function () {
    expect(fn () => TenantContext::run($this->meridian, fn () => DB::insert(
        'insert into wall_fixtures (tenant_id, name) values (?, ?)',
        [$this->vertex->getKey(), 'Planted in the wrong company']
    )))->toThrow(QueryException::class, 'row-level security policy');
});

it('refuses an update that moves a row into another client company', function () {
    expect(fn () => TenantContext::run($this->meridian, fn () => DB::update(
        'update wall_fixtures set tenant_id = ? where id = ?',
        [$this->vertex->getKey(), $this->meridianRow]
    )))->toThrow(QueryException::class, 'row-level security policy');
});

it('refuses a reference to another client company\'s row', function () {
    // The reason every key between two client-owned tables carries the client:
    // Postgres does not apply the isolation policy while it checks a reference, so a
    // key on the id alone accepts this insert.
    expect(fn () => TenantContext::run($this->meridian, fn () => DB::insert(
        'insert into wall_fixtures (tenant_id, name, parent_id) values (?, ?, ?)',
        [$this->meridian->getKey(), 'Reports to the wrong company', $this->vertexRow]
    )))->toThrow(QueryException::class, 'foreign key constraint');
});

it('changes nothing when an update runs with no client company named, and says it succeeded', function () {
    DB::statement('reset app.tenant_id');

    $changed = DB::update('update wall_fixtures set name = ?', ['Renamed by a careless migration']);

    expect($changed)->toBe(0);

    $names = TenantContext::cross(
        fn () => array_map(fn ($row) => $row->name, DB::select('select name from wall_fixtures order by id')),
        reason: 'test: confirming the careless update touched nothing'
    );

    expect($names)->toBe(['Meridian head office', 'Vertex head office']);
});

it('lets the audited path across client companies read everything', function () {
    $rows = TenantContext::cross(
        fn () => DB::select('select id from wall_fixtures'),
        reason: 'test: the audited cross-company path'
    );

    expect($rows)->toHaveCount(2);
});
