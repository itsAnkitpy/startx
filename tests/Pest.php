<?php

use App\Models\FormField;
use App\Models\IssuedDocument;
use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\VertexSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
    ->in('Feature', 'Browser');

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

    // The constraint on the tenant and the id together exists so that another
    // tenant-owned table can point at this one with a key that carries the tenant. A
    // table with no `id` at all cannot be pointed at that way and has nothing to
    // protect — Laravel's password reset table is keyed on the address, which is the
    // only one so far. Asking the schema rather than keeping a list of exceptions means
    // nobody has to remember to maintain the list.
    $hasIdColumn = DB::selectOne(
        "select count(*) as total
           from pg_attribute
          where attrelid = ?::regclass and attname = 'id' and attnum > 0 and not attisdropped",
        [$table]
    );

    if ((int) $hasIdColumn->total > 0) {
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

/*
|--------------------------------------------------------------------------
| Browser helpers
|--------------------------------------------------------------------------
|
| The browser tests run the application inside the test itself, and the browser always
| talks to 127.0.0.1. Which client company a request belongs to is read from its host,
| so the plugin rewrites the host on every request from one shared setting — which is
| why it is set here for the whole test, never per visit: a per-visit host is put back
| as soon as the first page has loaded, and every click after it would arrive at no
| company at all.
|
*/

/** Somebody at a seeded client company, by first name: `personAt('meridian', 'anjali')`. */
function personAt(string $company, string $first): User
{
    $tenant = Tenant::query()->where('slug', $company)->sole();

    return TenantContext::run($tenant, fn (): User => User::query()->where('work_email', "{$first}@{$company}.test")->sole());
}

/**
 * Open one or more screens in a real browser, signed in as this person, at their own
 * company's address.
 *
 * @param  string|list<string>  $paths
 */
function browseAs(User $person, string|array $paths): mixed
{
    // The application answering the browser lives as long as the test, and so does its
    // session, which a real server would start empty for each visitor. Left alone, the
    // previous person's sign-in stays in it, and the panel signs the next person out for
    // arriving on somebody else's session.
    test()->flushSession();
    test()->actingAs($person);

    pest()->browser()->withHost($person->tenant->slug.'.'.config('tenancy.central_domain'));

    return visit($paths);
}

/**
 * An exit's letters step answered on the leaver's last working day, the first day it can be
 * (module 09 step 3), with the documents written to a disk nobody keeps.
 *
 * For tests about how an exit finishes rather than about its letters, which since module 09
 * finish it.
 */
function issueTheLettersOn(ProcessCase $exit, int $sequence, User $by): void
{
    Storage::fake(config('startx.documents_disk'));

    if ($exit->lastWorkingDayStillAhead() !== null) {
        test()->travelTo(CarbonImmutable::parse($exit->statutory_from->toDateString().' 10:00', $exit->tenant->timezone));
    }

    (new CaseEngine)->decide($exit->fresh(), $sequence, 'approved', $by);
}

/*
|--------------------------------------------------------------------------
| Neha leaving Vertex (module 09)
|--------------------------------------------------------------------------
|
| Shared by the tests of her letters going out and of her answering them, each of which
| seeds Vertex first.
|
*/

function vertexsOwn(string $first): User
{
    return User::query()->where('work_email', $first.'@vertex.test')->sole();
}

function nehasExitAtVertex(): ProcessCase
{
    return ProcessCase::query()->where('subject_user_id', vertexsOwn('neha')->getKey())->sole();
}

/**
 * Every one of Vertex's seven clearances answered, each by whoever holds it, with the money
 * the statement test in module 08 worked out by hand: ₹2,520.09 claimed, ₹16,235.31 owed.
 */
function everyDepartmentClearsNeha(): ProcessCase
{
    $holders = [1 => 'meera', 2 => 'meera', 3 => 'sanjay', 4 => 'farhan', 5 => 'meera', 6 => 'sanjay', 7 => 'meera'];
    $money = [
        6 => [
            'finance_recovery_amount' => 2000.10,
            'finance_recovery_reason' => 'Salary advance',
            'finance_payable_amount' => 15000.75,
            'finance_payable_reason' => 'Bonus',
        ],
        7 => [
            'notice_recovery_amount' => 500,
            'hr_other_recovery_amount' => 19.99,
            'hr_other_reason' => 'Uniform',
            'encashment_payable_amount' => 1234.56,
        ],
    ];

    foreach ($holders as $sequence => $first) {
        $exit = nehasExitAtVertex();
        $required = $exit->template->steps->firstWhere('sequence', $sequence)->form->fields
            ->where('required', true)
            ->mapWithKeys(fn (FormField $field): array => [$field->key => true])
            ->all();

        (new CaseEngine)->decide($exit, $sequence, 'approved', vertexsOwn($first), [...$required, ...($money[$sequence] ?? [])]);
    }

    return nehasExitAtVertex();
}

/** Ten in the morning on Neha's last working day, on Vertex's own clock. */
function onNehasLastWorkingDay(): CarbonImmutable
{
    return CarbonImmutable::parse(nehasExitAtVertex()->statutory_from->toDateString().' 10:00', Tenant::query()->where('slug', VertexSeeder::Slug)->sole()->timezone);
}

/** An address on Vertex's own subdomain. */
function atVertexsAddress(string $path): string
{
    return 'http://'.VertexSeeder::Slug.'.'.config('tenancy.central_domain').'/'.ltrim($path, '/');
}

function issuedOnNehasExit(string $kind): IssuedDocument
{
    return IssuedDocument::query()->where('case_id', nehasExitAtVertex()->getKey())->where('kind', $kind)->sole();
}
