<?php

use App\Exceptions\EmployeeRecordRefused;
use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\EmploymentRecord;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The engine's five tables. Step 1 of module 02 builds no behaviour, so everything here
| is a promise the schema itself makes and the engine is then free to rely on.
|
| The promises worth naming: a published process version cannot be pointed at by the
| wrong person's job row, a case ends once, a step has exactly one holder, a shared queue
| has exactly one winner, and the history can be added to and never touched again.
|
| A refused insert abandons the surrounding transaction in Postgres, which under
| RefreshDatabase is the test's own, so each expected database refusal gets a test to
| itself.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

/**
 * Rakesh, his job row, and Meridian's exit process at version 1 — three steps, the last
 * two running side by side.
 *
 * @return array{0: User, 1: EmploymentRecord, 2: ProcessTemplate}
 */
function rakeshAndMeridiansExit(): array
{
    $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

    $rakesh = User::factory()->named('Rakesh Menon')->create();

    $job = EmploymentRecord::factory()
        ->forPerson($rakesh)
        ->in($freight)
        ->effective('2019-06-01')
        ->create(['employment_status' => 'confirmed']);

    // Written as a draft and then made live, which is the only order step 2 allows: a
    // live version's steps are frozen at the database.
    $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

    ProcessStep::factory()->of($exit)->at(1, 1)->named('Manager approval')->create();
    ProcessStep::factory()->of($exit)->at(2, 2)->named('IT clearance')->clearance()->create();
    ProcessStep::factory()->of($exit)->at(3, 2)->named('Finance clearance')->clearance()->create();

    $exit->publish();

    return [$rakesh, $job, $exit];
}

it('holds a process version, a case on it, one step acted on and the history of it', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();

        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->countingFrom('2026-09-04')->create();

        $deepak = User::factory()->named('Deepak Verma')->create();

        CaseStep::factory()->of($case)->at(2)->heldBy($deepak)->decided('approved')->create();
        CaseEvent::factory()->of($case)->by($deepak)->ofType('step_decided')->create();

        $case->refresh();

        // The process is read through the version the case points at, not off the case.
        expect($case->template->steps->pluck('name')->all())
            ->toBe(['Manager approval', 'IT clearance', 'Finance clearance']);

        // Rakesh's department as it stood at open, read through the pinned job row.
        expect($case->subjectEmploymentRecord->orgUnit->name)->toBe('Freight');

        // A row exists only for the step somebody touched. Two of the three have none.
        expect($case->steps)->toHaveCount(1);
        expect($case->events->pluck('type')->all())->toBe(['step_decided']);
    });
});

/*
| The history
*/

it('refuses a change to a history entry at the database', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();
        $event = CaseEvent::factory()->of($case)->create();

        // Straight past the model, which is the only way this can be tried by mistake.
        DB::update('update case_events set type = ? where id = ?', ['step_undecided', $event->getKey()]);
    });
})->throws(QueryException::class, 'case_events is add-only');

it('refuses a removal of a history entry at the database', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();
        $event = CaseEvent::factory()->of($case)->create();

        DB::delete('delete from case_events where id = ?', [$event->getKey()]);
    });
})->throws(QueryException::class, 'case_events is add-only');

it('refuses to empty the history table wholesale', function () {
    TenantContext::run($this->meridian, function () {
        // Truncate is the one way of deleting that touches no row, so a rule written per
        // row would not see it coming.
        DB::statement('truncate table case_events');
    });
})->throws(QueryException::class, 'case_events is add-only');

it('refuses a change to a history entry on the model, before any query runs', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();
        $event = CaseEvent::factory()->of($case)->create();

        $event->update(['type' => 'step_undecided']);
    });
})->throws(ProcessRefused::class, 'cannot be changed once it is written');

it('refuses a removal of a history entry on the model, before any query runs', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();

        CaseEvent::factory()->of($case)->create()->delete();
    });
})->throws(ProcessRefused::class, 'cannot be removed once it is written');

/*
| A process and its versions
*/

it('keeps every version of a process as its own rows', function () {
    TenantContext::run($this->meridian, function () {
        $first = ProcessTemplate::factory()->named('exit', 'Exit')->create();
        ProcessStep::factory()->of($first)->at(1, 1)->named('Manager approval')->create();
        $first->publish();

        // Anjali changes the approver, which is version 2 as fresh rows.
        $second = ProcessTemplate::factory()->named('exit', 'Exit')->version(2)->create();
        ProcessStep::factory()->of($second)->at(1, 1)->named('Head of Freight approval')->create();

        expect($first->fresh()->steps->pluck('name')->all())->toBe(['Manager approval']);
        expect($second->steps->pluck('name')->all())->toBe(['Head of Freight approval']);
    });
});

it('refuses a second row at a version the process already has', function () {
    TenantContext::run($this->meridian, function () {
        ProcessTemplate::factory()->named('exit', 'Exit')->version(1)->create();
        ProcessTemplate::factory()->named('exit', 'Exit renamed')->version(1)->create();
    });
})->throws(QueryException::class, 'process_templates_tenant_id_key_version_unique');

it('refuses two steps at the same position in one version', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Manager approval')->create();
        ProcessStep::factory()->of($exit)->at(1, 1)->named('HR approval')->create();
    });
})->throws(QueryException::class, 'process_steps_tenant_id_template_id_sequence_unique');

it('refuses a step that offers an outcome nobody may pick from a form', function () {
    // Resolving a hold into a disputed settlement line is one of the two ways a hold
    // ends, not a button. Offering it on a step would let somebody record a dispute with
    // no settlement line behind it.
    TenantContext::run($this->meridian, function () {
        ProcessStep::factory()->create(['allowed_outcomes' => ['approved', 'closed_disputed']]);
    });
})->throws(QueryException::class, 'process_steps_outcomes_are_choosable');

it('refuses a step that offers nothing at all', function () {
    TenantContext::run($this->meridian, function () {
        ProcessStep::factory()->create(['allowed_outcomes' => []]);
    });
})->throws(QueryException::class, 'process_steps_outcomes_are_choosable');

/*
| A case
*/

it("refuses a case pinned to somebody else's job row", function () {
    // The pointer exists so a case read years later shows the department, designation and
    // manager the subject actually had. Pinned to the wrong person it does that
    // convincingly and wrongly, which is worse than showing nothing.
    TenantContext::run($this->meridian, function () {
        [$rakesh, , $exit] = rakeshAndMeridiansExit();

        $priya = User::factory()->named('Priya Nair')->create();
        $priyasJob = EmploymentRecord::factory()->forPerson($priya)->create();

        ProcessCase::factory()->on($exit)->about($rakesh, $priyasJob)->create();
    });
})->throws(QueryException::class, 'cases_pinned_row_is_the_subjects_own');

it('refuses a case pinned to a job row with nobody to own it', function () {
    TenantContext::run($this->meridian, function () {
        [, $job, $exit] = rakeshAndMeridiansExit();

        ProcessCase::factory()->on($exit)->create(['subject_employment_record_id' => $job->getKey()]);
    });
})->throws(QueryException::class, 'cases_pinned_row_has_a_subject');

it('opens a hiring request about nobody at all', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->published()->create();

        $case = ProcessCase::factory()->on($hiring)->create();

        expect($case->subject_user_id)->toBeNull()
            ->and($case->subject_employment_record_id)->toBeNull()
            ->and($case->state)->toBe(ProcessCase::Open);
    });
});

it('refuses a case that is both closed and cancelled', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $anjali = User::factory()->named('Anjali Rao')->create();

        ProcessCase::factory()->on($exit)->about($rakesh, $job)->create([
            'closed_at' => '2026-09-08 12:00:00',
            'cancelled_at' => '2026-09-08 12:00:00',
            'cancellation_reason' => 'Resignation withdrawn',
            'cancelled_by' => $anjali->getKey(),
        ]);
    });
})->throws(QueryException::class, 'cases_end_once');

it('refuses a cancellation that records no reason', function () {
    // A withdrawn resignation with nothing written down is the skippable decision this
    // rebuild exists to remove, arriving through a different door.
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $anjali = User::factory()->named('Anjali Rao')->create();

        ProcessCase::factory()->on($exit)->about($rakesh, $job)->create([
            'cancelled_at' => '2026-09-08 12:00:00',
            'cancelled_by' => $anjali->getKey(),
        ]);
    });
})->throws(QueryException::class, 'cases_cancellation_is_accounted_for');

it('refuses a deadline with no date to count it from', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();

        ProcessCase::factory()->on($exit)->about($rakesh, $job)->create(['statutory_due_at' => '2026-09-08']);
    });
})->throws(QueryException::class, 'cases_deadlines_have_a_starting_date');

it('reads whether a case is running off the case itself, with no status to disagree with', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $anjali = User::factory()->named('Anjali Rao')->create();

        $running = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();
        expect($running->state)->toBe(ProcessCase::Open);

        // Closing is the engine's act and no form may fill it, which is why it is set
        // here rather than passed through `update`.
        $running->closed_at = '2026-09-08 12:00:00';
        $running->save();
        expect($running->state)->toBe(ProcessCase::Closed);

        $withdrawn = ProcessCase::factory()->on($exit)->create([
            'cancelled_at' => '2026-09-08 12:00:00',
            'cancellation_reason' => 'Resignation withdrawn',
            'cancelled_by' => $anjali->getKey(),
        ]);
        expect($withdrawn->state)->toBe(ProcessCase::Cancelled);
    });
});

it('keeps no status column for anything to write to', function () {
    // The old tool's magic status numbers — 14, 20, 30, 40, 62, 100 — kept a status
    // beside the facts and the two drifted, so an exit could read as finished with
    // clearances still open. There is nothing here to assign.
    expect(columnsOf('cases'))->not->toContain('state');
    expect(columnsOf('case_steps'))->not->toContain('state');
});

it('keeps no copy of a step definition on a case step', function () {
    // A case reads its process through the frozen version it points at. A copy here would
    // force a row per step the moment a case opens, which is the write this module
    // removed so that a case cannot silently stall.
    expect(columnsOf('case_steps'))
        ->not->toContain('name')
        ->not->toContain('group_no')
        ->not->toContain('allowed_outcomes')
        ->not->toContain('sla_hours');
});

/*
| A case's steps
*/

it('refuses a step row holding both an account and an outside address', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();
        $deepak = User::factory()->named('Deepak Verma')->create();

        CaseStep::factory()->of($case)->heldBy($deepak)->create([
            'external_assignee' => ['name' => 'Chandni Rao', 'email' => 'chandni@example.test'],
        ]);
    });
})->throws(QueryException::class, 'case_steps_have_one_holder');

it('refuses a step row held by nobody', function () {
    // A row exists because somebody picked the step up. A step nobody has touched has no
    // row at all, which is what lets an unstarted step still be chased.
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();

        CaseStep::factory()->of($case)->create(['assignee_id' => null]);
    });
})->throws(QueryException::class, 'case_steps_have_one_holder');

it('refuses a decision with no time on it', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();

        CaseStep::factory()->of($case)->create(['outcome' => 'approved']);
    });
})->throws(QueryException::class, 'case_steps_outcome_is_dated');

it('gives a shared queue exactly one winner', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();

        $deepak = User::factory()->named('Deepak Verma')->create();
        $chandni = User::factory()->named('Chandni Rao')->create();

        CaseStep::factory()->of($case)->at(2)->heldBy($deepak)->create();
        CaseStep::factory()->of($case)->at(2)->heldBy($chandni)->create();
    });
})->throws(QueryException::class, 'case_steps_one_live_attempt');

it('lets a sent-back step be done again while the first attempt stays readable', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();

        $anjali = User::factory()->named('Anjali Rao')->create();

        $first = CaseStep::factory()->of($case)->at(1)->heldBy($anjali)->decided('approved')->create();

        // Marked replaced first, then the new attempt goes in behind it. The other order
        // is refused, which is why this is a time and not a pointer at the replacing row.
        $first->superseded_at = '2026-09-09 09:00:00';
        $first->save();

        CaseStep::factory()->of($case)->at(1)->heldBy($anjali)->create();

        // The winner rule counts live rows only, so the redo is allowed and the first
        // attempt is still there to read.
        expect($case->steps()->where('sequence', 1)->count())->toBe(2);
        expect($first->fresh()->outcome)->toBe('approved');
    });
});

/*
| What the case's pointer costs module 01
*/

it('refuses to withdraw a job row a case is pinned to, and names the case', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $anjali = User::factory()->named('Anjali Rao')->create();

        $case = ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();

        expect(fn () => $job->withdraw($anjali, 'Entered against the wrong person'))
            ->toThrow(EmployeeRecordRefused::class, "case [{$case->getKey()}]");

        // Nothing half-done: the row is still live and still readable through the case.
        expect($job->fresh()->withdrawn_at)->toBeNull();
        expect($case->fresh()->subjectEmploymentRecord->getKey())->toBe($job->getKey());

        // And nothing half-done in memory either. The refusal used to land after the
        // reason had been written onto the record, so a screen re-rendering from this
        // same record showed a withdrawal that never happened.
        expect($job->withdrawn_by)->toBeNull()
            ->and($job->withdrawn_reason)->toBeNull();
    });
});

it('refuses to withdraw a pinned job row straight past the model, at the database', function () {
    // Withdrawing is a soft delete, so it reaches the table as an ordinary update and
    // the key from the case never sees it. Without this, a bulk update leaves Rakesh's
    // closed exit rendering no department, no designation and no manager.
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();

        ProcessCase::factory()->on($exit)->about($rakesh, $job)->create();

        DB::update(
            'update employment_records set withdrawn_at = now() where id = ?',
            [$job->getKey()]
        );
    });
})->throws(QueryException::class, 'cannot be withdrawn');

it('still withdraws a pinned row once the case pointing at it is gone', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job, $exit] = rakeshAndMeridiansExit();
        $anjali = User::factory()->named('Anjali Rao')->create();

        ProcessCase::factory()->on($exit)->about($rakesh, $job)->create()->delete();

        $job->withdraw($anjali, 'Entered against the wrong person');

        expect(EmploymentRecord::query()->whereKey($job->getKey())->exists())->toBeFalse();
    });
});

it('still withdraws a job row no case has been read against', function () {
    TenantContext::run($this->meridian, function () {
        [$rakesh, $job] = rakeshAndMeridiansExit();
        $anjali = User::factory()->named('Anjali Rao')->create();

        $job->withdraw($anjali, 'Entered against the wrong person');

        expect(EmploymentRecord::query()->where('user_id', $rakesh->getKey())->exists())->toBeFalse();
    });
});

/**
 * The columns a table actually has, read from the live schema rather than from a list
 * somebody has to remember to update.
 *
 * @return list<string>
 */
function columnsOf(string $table): array
{
    $rows = DB::select(
        'select attname from pg_attribute where attrelid = ?::regclass and attnum > 0 and not attisdropped',
        [$table]
    );

    return array_map(fn ($row) => $row->attname, $rows);
}
