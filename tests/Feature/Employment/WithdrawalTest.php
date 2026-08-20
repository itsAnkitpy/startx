<?php

use App\Models\EmploymentRecord;
use App\Models\OrgUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
| A job row entered by mistake is withdrawn, which is a different act from a job
| change. Without it a mistyped date would sit in Priya's history for good and be
| rendered by every case that read her as of that date.
|
| Workday is the reason this is guarded rather than free: it had an unrestricted
| correction action for years and withdrew it for job changes in March 2026, because
| retroactive edits were breaking payroll and reporting downstream. BambooHR's own
| delete-a-row endpoint refuses when the row is waiting on an approval or tied to a
| live pay schedule. Ours borrows the guard — a row a case has pinned cannot be
| withdrawn — and that refusal belongs to module 02, whose table holds the pointer.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
});

it('cannot withdraw another client company\'s job row', function () {
    // Worth its own test because of how the framework writes a soft delete: the update
    // is built on a query with every global scope dropped, so the client company is
    // absent from that statement's own conditions. What refuses it is the database
    // policy, which is the half of the wall that covers exactly this.
    $vertexRow = TenantContext::run($this->vertex, fn () => EmploymentRecord::factory()->create());

    TenantContext::run($this->meridian, function () use ($vertexRow) {
        $affected = EmploymentRecord::withoutGlobalScopes()->whereKey($vertexRow->getKey())->delete();

        expect($affected)->toBe(0);
    });

    TenantContext::run($this->vertex, function () use ($vertexRow) {
        expect($vertexRow->fresh()->withdrawn_at)->toBeNull();
    });
});

it('hides a withdrawn row from a query that never mentions the withdrawal', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();
        $unit = OrgUnit::factory()->create();

        EmploymentRecord::factory()->forPerson($priya)->in($unit)->effective('2024-04-01', '2026-03-31')->create();

        $mistake = EmploymentRecord::factory()
            ->forPerson($priya)
            ->in($unit)
            ->effective('2026-04-01')
            ->create(['change_reason' => 'transfer typed against the wrong person']);

        $mistake->withdraw($anjali, 'entered against the wrong person');

        // Nothing here filters on the withdrawal column. The framework's own scope is
        // what has to do the work, or every future query has to remember.
        expect($priya->employmentRecords()->count())->toBe(1)
            ->and($priya->employmentAsOf('2026-06-01'))->not->toBeNull()
            ->and($priya->employmentAsOf('2026-06-01')->getKey())->not->toBe($mistake->getKey())
            ->and(EmploymentRecord::query()->whereKey($mistake->getKey())->exists())->toBeFalse()
            // Still in the table, and readable when somebody deliberately asks.
            ->and(EmploymentRecord::withTrashed()->whereKey($mistake->getKey())->exists())->toBeTrue();
    });
});

it('makes the previous row current again when the current one is withdrawn', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $retail = OrgUnit::create(['type' => 'business_line', 'name' => 'Retail Fulfilment']);

        $original = EmploymentRecord::factory()
            ->forPerson($priya)->in($freight)->effective('2024-04-01', '2026-03-31')->create();

        $mistake = EmploymentRecord::factory()
            ->forPerson($priya)->in($retail)->effective('2026-04-01')->create();

        $mistake->withdraw($anjali, 'wrong department');

        expect($original->fresh()->effective_to)->toBeNull()
            ->and($priya->currentEmployment->getKey())->toBe($original->getKey());

        // Putting the right transfer in is now an ordinary job change: end the row that
        // is current, then insert the replacement. That insert is what fails if the
        // one-current-row index does not exclude withdrawn rows, because the withdrawn
        // row still has no end date on it.
        // Re-read first: the withdrawal reopened this row in the database, and a copy
        // held from before that still thinks it has an end date.
        $original->refresh()->update(['effective_to' => '2026-03-31']);

        $corrected = EmploymentRecord::factory()
            ->forPerson($priya)->in($retail)->effective('2026-04-01')->create();

        expect($priya->fresh()->currentEmployment->getKey())->toBe($corrected->getKey())
            ->and($original->fresh()->effective_to->toDateString())->toBe('2026-03-31');
    });
});

it('extends the predecessor over the gap when a middle row is withdrawn', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $retail = OrgUnit::create(['type' => 'business_line', 'name' => 'Retail Fulfilment']);

        $first = EmploymentRecord::factory()
            ->forPerson($priya)->in($freight)->effective('2024-04-01', '2025-03-31')->create();

        $middle = EmploymentRecord::factory()
            ->forPerson($priya)->in($retail)->effective('2025-04-01', '2026-03-31')->create();

        EmploymentRecord::factory()
            ->forPerson($priya)->in($freight)->effective('2026-04-01')->create();

        $middle->withdraw($anjali, 'that transfer never happened');

        // Reading her inside the withdrawn period returns the row before it rather
        // than nothing, so there is no hole in her history.
        expect($first->fresh()->effective_to->toDateString())->toBe('2026-03-31')
            ->and($priya->employmentAsOf('2025-08-15')->getKey())->toBe($first->getKey())
            ->and($priya->employmentRecords()->count())->toBe(2);
    });
});

it('does not bring a leaver back when a mistaken rehire is withdrawn', function () {
    // Rakesh left on 30 September 2025. Somebody records a rehire in January against
    // the wrong person and withdraws it. The end date must not travel back across that
    // gap: he really did leave, and reopening his old row would make him currently
    // employed with his own last working day still on it.
    TenantContext::run($this->meridian, function () {
        $rakesh = User::factory()->named('Rakesh Iyer')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();

        $firstStint = EmploymentRecord::factory()
            ->forPerson($rakesh)
            ->effective('2024-04-01', '2025-09-30')
            ->create(['joining_date' => '2024-04-01', 'last_working_day' => '2025-09-30']);

        $mistakenRehire = EmploymentRecord::factory()
            ->forPerson($rakesh)
            ->effective('2026-01-05')
            ->create(['joining_date' => '2026-01-05', 'change_reason' => 'rehired']);

        $mistakenRehire->withdraw($anjali, 'rehire recorded against the wrong person');

        expect($firstStint->fresh()->effective_to->toDateString())->toBe('2025-09-30')
            ->and($rakesh->currentEmployment)->toBeNull()
            ->and($rakesh->employmentAsOf('2026-02-01'))->toBeNull();
    });
});

it('records when a row was withdrawn, by whom, and why', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();

        $mistake = EmploymentRecord::factory()->forPerson($priya)->create();

        $mistake->withdraw($anjali, 'joining date typed as 2016 instead of 2026');

        $withdrawn = EmploymentRecord::withTrashed()->whereKey($mistake->getKey())->sole();

        expect($withdrawn->withdrawn_at)->not->toBeNull()
            ->and($withdrawn->withdrawn_by)->toBe($anjali->getKey())
            ->and($withdrawn->withdrawn_reason)->toBe('joining date typed as 2016 instead of 2026')
            ->and($withdrawn->withdrawnBy->name)->toBe('Anjali Rao');
    });
});

it('allows withdrawing the only row a person has', function () {
    // An account with no job row is a legal state — it is what every account is before
    // its first employment row is written — so this needs no rule of its own.
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();

        $only = EmploymentRecord::factory()->forPerson($priya)->create();

        $only->withdraw($anjali, 'this person never joined');

        expect($priya->employmentRecords()->count())->toBe(0)
            ->and($priya->currentEmployment)->toBeNull()
            ->and($priya->lastKnownOrgUnit())->toBeNull();
    });
});

it('leaves no row open in the database while a withdrawal is being applied', function () {
    // The withdrawal writes twice: the row is marked withdrawn, then its predecessor
    // is reopened. Both happen inside one transaction, so nothing can observe a person
    // with two open rows or with none.
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();

        EmploymentRecord::factory()->forPerson($priya)->effective('2024-04-01', '2026-03-31')->create();
        $mistake = EmploymentRecord::factory()->forPerson($priya)->effective('2026-04-01')->create();

        $mistake->withdraw($anjali, 'wrong person');

        $open = DB::table('employment_records')
            ->where('user_id', $priya->getKey())
            ->whereNull('effective_to')
            ->whereNull('withdrawn_at')
            ->count();

        expect($open)->toBe(1);
    });
});
