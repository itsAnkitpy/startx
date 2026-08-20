<?php

use App\Exceptions\EmployeeRecordRefused;
use App\Models\EmploymentRecord;
use App\Models\OrgUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Priya's job is dated rows, not columns on her account. A promotion, a transfer or a
| change of manager inserts a row and nothing is ever overwritten, because the question
| this product is sold on answering is what a case looked like at the time — the
| department, the manager and the status the person actually had then.
|
| A refused insert abandons the surrounding transaction in Postgres, which under
| RefreshDatabase is the test's own, so each expected refusal gets a test to itself.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
});

/**
 * Priya, her two departments and her two managers, with one job row already in place:
 * Freight, reporting to Anjali, from her joining date.
 *
 * @return array{0: User, 1: OrgUnit, 2: OrgUnit, 3: User, 4: User, 5: EmploymentRecord}
 */
function priyaAtFreight(): array
{
    $company = OrgUnit::create(['type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.']);
    $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight', 'parent_id' => $company->getKey()]);
    $retail = OrgUnit::create(['type' => 'business_line', 'name' => 'Retail Fulfilment', 'parent_id' => $company->getKey()]);

    $priya = User::factory()->named('Priya Nair')->create();
    $anjali = User::factory()->named('Anjali Rao')->create();
    $deepak = User::factory()->named('Deepak Verma')->create();

    $first = EmploymentRecord::factory()
        ->forPerson($priya)
        ->in($freight)
        ->reportingTo($anjali)
        ->effective('2024-04-01', '2026-03-31')
        ->create(['employee_code' => 'MER-0041', 'employment_status' => 'confirmed']);

    return [$priya, $freight, $retail, $anjali, $deepak, $first];
}

it('records a promotion as a new row and leaves the previous one readable', function () {
    TenantContext::run($this->meridian, function () {
        [$priya, $freight, $retail, $anjali, $deepak, $first] = priyaAtFreight();

        EmploymentRecord::factory()
            ->forPerson($priya)
            ->in($retail)
            ->reportingTo($deepak)
            ->effective('2026-04-01')
            ->create(['employee_code' => 'MER-0041', 'change_reason' => 'promotion to regional lead']);

        expect($priya->employmentRecords()->count())->toBe(2)
            ->and($priya->currentEmployment->org_unit_id)->toBe($retail->getKey())
            ->and($priya->currentEmployment->reports_to_id)->toBe($deepak->getKey())
            // The row she held before is untouched, which is the whole point.
            ->and($first->fresh()->org_unit_id)->toBe($freight->getKey())
            ->and($first->fresh()->reports_to_id)->toBe($anjali->getKey());
    });
});

it('stamps who entered a job row rather than trusting a submitted field', function () {
    // The whole product claim is who did what and when, so the field naming who entered
    // a row cannot be one a form fills in. Same rule as the client company: anything
    // answering "where did this row come from" is stamped, never submitted.
    TenantContext::run($this->meridian, function () {
        $anjali = User::factory()->named('Anjali Rao')->create();
        $rakesh = User::factory()->named('Rakesh Iyer')->create();
        $priya = User::factory()->named('Priya Nair')->create();
        $unit = OrgUnit::factory()->create();

        $this->actingAs($anjali);

        $row = EmploymentRecord::create([
            'user_id' => $priya->getKey(),
            'org_unit_id' => $unit->getKey(),
            'employment_type' => 'full_time',
            'employment_status' => 'confirmed',
            'joining_date' => '2024-04-01',
            'effective_from' => '2024-04-01',
            // A hand-crafted field claiming somebody else entered this.
            'recorded_by' => $rakesh->getKey(),
        ]);

        expect($row->recorded_by)->toBe($anjali->getKey());
    });
});

it('refuses a second job row with no end date, at the database', function () {
    // The one-current-row rule is an index, not application code, so nothing that
    // writes to this table can get around it.
    TenantContext::run($this->meridian, function () {
        [$priya, , $retail] = priyaAtFreight();

        EmploymentRecord::factory()->forPerson($priya)->in($retail)->effective('2026-04-01')->create();
        EmploymentRecord::factory()->forPerson($priya)->in($retail)->effective('2026-05-01')->create();
    });
})->throws(QueryException::class);

it('reads a person as they were on a past date, not as they are now', function () {
    TenantContext::run($this->meridian, function () {
        [$priya, $freight, $retail, $anjali, $deepak] = priyaAtFreight();

        EmploymentRecord::factory()
            ->forPerson($priya)
            ->in($retail)
            ->reportingTo($deepak)
            ->effective('2026-04-01')
            ->create(['employment_status' => 'confirmed']);

        $thenRow = $priya->employmentAsOf('2025-06-01');
        $nowRow = $priya->employmentAsOf('2026-08-20');

        expect($thenRow->org_unit_id)->toBe($freight->getKey())
            ->and($thenRow->reports_to_id)->toBe($anjali->getKey())
            ->and($nowRow->org_unit_id)->toBe($retail->getKey())
            ->and($nowRow->reports_to_id)->toBe($deepak->getKey())
            // The day the transfer took effect belongs to the new row, and the day
            // before it to the old one — the two never overlap.
            ->and($priya->employmentAsOf('2026-03-31')->getKey())->toBe($thenRow->getKey())
            ->and($priya->employmentAsOf('2026-04-01')->getKey())->toBe($nowRow->getKey());
    });
});

it('records a rehire as a second sequence of rows with a new joining date', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $freight] = [User::factory()->named('Rakesh Iyer')->create(), OrgUnit::factory()->create()];

        $firstStint = EmploymentRecord::factory()
            ->forPerson($rakesh)
            ->in($freight)
            ->effective('2024-04-01', '2025-09-30')
            ->create(['joining_date' => '2024-04-01', 'last_working_day' => '2025-09-30']);

        EmploymentRecord::factory()
            ->forPerson($rakesh)
            ->in($freight)
            ->effective('2026-01-05')
            ->create(['joining_date' => '2026-01-05', 'change_reason' => 'rehired']);

        expect($rakesh->employmentRecords()->count())->toBe(2)
            ->and($firstStint->fresh()->joining_date->toDateString())->toBe('2024-04-01')
            ->and($rakesh->currentEmployment->joining_date->toDateString())->toBe('2026-01-05')
            // Nothing was employed in between, and reading him then says so rather
            // than guessing from the nearest row.
            ->and($rakesh->employmentAsOf('2025-11-01'))->toBeNull();
    });
});

it('refuses a person set to report to themselves', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        EmploymentRecord::factory()->forPerson($priya)->reportingTo($priya)->create();
    });
})->throws(EmployeeRecordRefused::class);

it('refuses a reporting line that comes back round to the same person', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();
        $deepak = User::factory()->named('Deepak Verma')->create();

        // Priya reports to Anjali, Anjali to Deepak.
        EmploymentRecord::factory()->forPerson($priya)->reportingTo($anjali)->create();
        EmploymentRecord::factory()->forPerson($anjali)->reportingTo($deepak)->create();

        // Deepak cannot then be put under Priya.
        EmploymentRecord::factory()->forPerson($deepak)->reportingTo($priya)->create();
    });
})->throws(EmployeeRecordRefused::class);

it('refuses a reporting line that loops back through somebody who has left', function () {
    // The walk up a reporting line used to read only the row that is true today, so it
    // stopped dead at anybody who had left. Deepak the Finance head goes, his team is
    // not moved to Chandni yet, and a circle can then be drawn straight through him
    // from an ordinary screen — becoming a live loop the day he is rehired.
    TenantContext::run($this->meridian, function () {
        $freight = OrgUnit::factory()->create();

        $priya = User::factory()->named('Priya Nair')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();
        $deepak = User::factory()->named('Deepak Verma')->create();
        $chandni = User::factory()->named('Chandni Bose')->create();

        // Priya reports to Anjali, Anjali to Deepak, Deepak to Chandni.
        EmploymentRecord::factory()->forPerson($priya)->in($freight)->reportingTo($anjali)->create();
        EmploymentRecord::factory()->forPerson($anjali)->in($freight)->reportingTo($deepak)->create();
        $deepaksJob = EmploymentRecord::factory()->forPerson($deepak)->in($freight)->reportingTo($chandni)->create();

        // Deepak leaves. Anjali still reports to him on the record.
        $deepaksJob->update(['effective_to' => '2025-09-30', 'employment_status' => 'exited']);

        // Chandni cannot now be put under Priya: the line would run
        // Chandni to Priya to Anjali to Deepak and back to Chandni.
        EmploymentRecord::factory()->forPerson($chandni)->in($freight)->reportingTo($priya)->create();
    });
})->throws(EmployeeRecordRefused::class);

it('refuses a job row that ends before it starts', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        EmploymentRecord::factory()->forPerson($priya)->effective('2026-04-01', '2026-03-01')->create();
    });
})->throws(QueryException::class);

it('refuses a job row naming another client company\'s department', function () {
    // Written as a raw insert with the Eloquent scope out of the way, because the key
    // is what has to refuse this. Postgres ignores row-level policies while checking
    // referential integrity, so a plain single-column key here would let Meridian
    // point a job row at a Vertex department.
    $vertexUnit = TenantContext::run($this->vertex, fn () => OrgUnit::factory()->create());

    TenantContext::run($this->meridian, function () use ($vertexUnit) {
        $priya = User::factory()->named('Priya Nair')->create();

        DB::table('employment_records')->insert([
            'tenant_id' => $this->meridian->getKey(),
            'user_id' => $priya->getKey(),
            'org_unit_id' => $vertexUnit->getKey(),
            'employment_type' => 'full_time',
            'employment_status' => 'confirmed',
            'joining_date' => '2024-04-01',
            'effective_from' => '2024-04-01',
        ]);
    });
})->throws(QueryException::class);

it('refuses to delete a department that people have worked in', function () {
    // History outlives the structure. A department nobody has ever been in can be
    // deleted; one that appears in somebody's job history cannot, because those rows
    // are the evidence behind their case.
    TenantContext::run($this->meridian, function () {
        [, $freight] = priyaAtFreight();

        $freight->delete();
    });
})->throws(QueryException::class);
