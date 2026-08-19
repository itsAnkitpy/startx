<?php

use App\Tenancy\Rls;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Tenancy helpers
|--------------------------------------------------------------------------
|
| Module 01's wall has to be provable before the tables it protects exist, and it
| has to stay provable as they arrive. `createWalledFixtureTables()` gives the wall
| tests a stand-in table built exactly as the rule requires, plus a deliberately
| unprotected one so the audit below is shown to catch a mistake rather than only
| to pass. `tenantOwnedTables()` and `tenantWallFaults()` are the audit itself: they
| read the live schema, so a table added in a later module cannot skip the wall
| without a test failing.
|
*/

/**
 * Create the stand-in tables used by the tenant wall tests. Both are dropped with
 * the test's own transaction.
 */
function createWalledFixtureTables(): void
{
    Schema::create('wall_fixtures', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('name');
        $table->unsignedBigInteger('parent_id')->nullable();

        // What every tenant-owned table carries: something for another tenant-owned
        // table to point at, and a foreign key that carries the tenant with it.
        $table->unique(['tenant_id', 'id']);
        $table->foreign(['tenant_id', 'parent_id'])
            ->references(['tenant_id', 'id'])
            ->on('wall_fixtures');
    });

    Rls::enable('wall_fixtures');

    // Deliberately left unprotected, so the audit is seen to fail on a table that
    // skipped the rule.
    Schema::create('wall_leaks', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->unsignedBigInteger('fixture_id')->nullable();
        $table->foreign('fixture_id')->references('id')->on('wall_fixtures');
    });
}

/**
 * Every table in the schema that claims to be owned by a client company, which is
 * to say every table carrying a `tenant_id` column.
 *
 * @return list<string>
 */
function tenantOwnedTables(): array
{
    $rows = DB::select(
        "select c.relname as table_name
           from pg_class c
           join pg_namespace n on n.oid = c.relnamespace
           join pg_attribute a on a.attrelid = c.oid and a.attname = 'tenant_id'
                              and a.attnum > 0 and not a.attisdropped
          where c.relkind = 'r' and n.nspname = current_schema()
          order by c.relname"
    );

    return array_map(fn ($row) => $row->table_name, $rows);
}

/**
 * Everything wrong with one tenant-owned table's wall, in plain sentences. An empty
 * list means the table follows the rule.
 *
 * The list of client-owned tables is passed in when several tables are checked in one
 * pass, so the schema is read once rather than once per table.
 *
 * @param  list<string>|null  $tenantOwned
 * @return list<string>
 */
function tenantWallFaults(string $table, ?array $tenantOwned = null): array
{
    $faults = [];

    $flags = DB::selectOne(
        'select relrowsecurity, relforcerowsecurity from pg_class where oid = ?::regclass',
        [$table]
    );

    if (! $flags->relrowsecurity) {
        $faults[] = "{$table} does not have row-level security switched on.";
    }

    if (! $flags->relforcerowsecurity) {
        $faults[] = "{$table} does not force row-level security, so the table's own owner reads every tenant's rows.";
    }

    $policy = DB::selectOne(
        'select qual, with_check from pg_policies where schemaname = current_schema() and tablename = ? and policyname = ?',
        [$table, Rls::policyName($table)]
    );

    if ($policy === null) {
        $faults[] = "{$table} has no tenant isolation policy.";
    } else {
        if ($policy->qual === null) {
            $faults[] = "{$table}'s policy does not restrict reads.";
        }

        if ($policy->with_check === null) {
            $faults[] = "{$table}'s policy does not restrict writes, so a row stamped with another tenant's id would be accepted.";
        }
    }

    $hasCompositeUnique = DB::selectOne(
        "select count(*) as total
           from pg_index i
          where i.indrelid = ?::regclass
            and i.indisunique
            and (select array_agg(att.attname::text order by att.attname)
                   from pg_attribute att
                  where att.attrelid = i.indrelid and att.attnum = any(i.indkey)) = array['id', 'tenant_id']",
        [$table]
    );

    if ((int) $hasCompositeUnique->total === 0) {
        $faults[] = "{$table} has no unique constraint on the tenant and the id together, so no other table can point at it with a key that carries the tenant.";
    }

    $tenantOwned ??= tenantOwnedTables();

    $keys = DB::select(
        "select con.conname as name,
                con.confrelid::regclass::text as parent_table,
                (select array_agg(att.attname::text order by u.ord)
                   from unnest(con.conkey) with ordinality as u(attnum, ord)
                   join pg_attribute att on att.attrelid = con.conrelid and att.attnum = u.attnum) as child_columns
           from pg_constraint con
          where con.contype = 'f' and con.conrelid = ?::regclass",
        [$table]
    );

    foreach ($keys as $key) {
        if (! in_array($key->parent_table, $tenantOwned, true)) {
            continue;
        }

        $columns = explode(',', trim((string) $key->child_columns, '{}'));

        if (! in_array('tenant_id', $columns, true)) {
            $faults[] = "{$table}'s key [{$key->name}] points at {$key->parent_table} without carrying the tenant, so it can reference another tenant's row.";
        }
    }

    return $faults;
}
