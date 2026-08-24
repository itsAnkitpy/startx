<?php

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\Designation;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\OfficeHoliday;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;

/*
| Step 4 of module 02, first piece: the two legal dates on a case.
|
| Rakesh Menon leaves Meridian Logistics on Friday 14 August 2026 from its Shimla office.
| Two dates follow from that one date, and they obey different arithmetic on purpose:
|
|   - everything owed to him is payable within two *working* days, counted against the
|     calendar of the office he worked in;
|   - his gratuity is payable within thirty *ordinary* days, counted against nothing.
|
| Both are worked out when the case opens and neither moves afterwards. Every test below
| is one way of getting that wrong.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

/** Meridian's exit as a live one-step process, which is all a deadline needs. */
function exitCarryingDeadlines(string $about = 'employee'): ProcessTemplate
{
    $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about($about)->create();

    ProcessStep::factory()->of($exit)->at(1, 1)->named('IT clearance')->clearance()->create();

    $exit->publish();

    return $exit;
}

/** A leaver whose job row names the office his deadlines are counted against. */
function leaverBasedAt(Office $office, string $joined = '2019-04-01', string $called = 'Rakesh Menon'): User
{
    $person = User::factory()->named($called)->create();

    EmploymentRecord::factory()->forPerson($person)->basedAt($office)->create([
        'joining_date' => $joined,
    ]);

    return $person;
}

/*
| The settlement deadline — two working days
*/

it('gives a Friday leaver two working days, landing on the Tuesday', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        // Saturday and Sunday are not working days, so the two days are the Monday and
        // the Tuesday. Forty-eight hours would have answered Sunday.
        expect($case->statutory_due_at->toDateString())->toBe('2026-08-18');
    });
});

it('pushes the deadline out when a day inside it is a holiday at that person’s office', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        OfficeHoliday::factory()->at($shimla)->on('2026-08-17', 'Independence Day observed')->create();

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        expect($case->statutory_due_at->toDateString())->toBe('2026-08-19');
    });
});

it('gives two people leaving on the same day different deadlines when their offices are in different states', function () {
    TenantContext::run($this->meridian, function () {
        $exit = exitCarryingDeadlines();

        $shimla = Office::factory()->named('Shimla')->create(['state_code' => 'IN-HP']);
        OfficeHoliday::factory()->at($shimla)->on('2026-08-17', 'A Himachal holiday')->create();

        $gurgaon = Office::factory()->named('Gurgaon')->create(['state_code' => 'IN-HR']);

        $rakesh = (new CaseEngine)->open($exit, leaverBasedAt($shimla), null, '2026-08-14');
        $deepak = (new CaseEngine)->open($exit, leaverBasedAt($gurgaon, called: 'Deepak Rao'), null, '2026-08-14');

        expect($rakesh->statutory_due_at->toDateString())->toBe('2026-08-19')
            ->and($deepak->statutory_due_at->toDateString())->toBe('2026-08-18');
    });
});

it('leaves a deadline alone when a holiday is added to the calendar afterwards', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        expect($case->statutory_due_at->toDateString())->toBe('2026-08-18');

        // Meridian adds a festival holiday on the Monday, a day after Rakesh's case
        // opened. Recomputing would move a legal date under a running case, which is the
        // thing this whole module refuses.
        OfficeHoliday::factory()->at($shimla)->on('2026-08-17', 'Added later')->create();

        expect($case->fresh()->statutory_due_at->toDateString())->toBe('2026-08-18');
    });
});

it('counts a deadline against an office that works a Gulf week', function () {
    TenantContext::run($this->meridian, function () {
        $dubai = Office::factory()->named('Dubai')->in('AE')->weekendOn([5, 6])->create();

        // Friday and Saturday are the weekend there, so two working days from Thursday
        // 13 August lands on the Sunday and the Monday.
        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($dubai), null, '2026-08-13');

        expect($case->statutory_due_at->toDateString())->toBe('2026-08-17');
    });
});

/*
| The gratuity deadline — thirty ordinary days, and only where it is owed
*/

it('gives a leaver with six years’ service thirty calendar days for their gratuity', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $rakesh = leaverBasedAt($shimla, joined: '2019-04-01');

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), $rakesh, null, '2026-08-14');

        expect($case->gratuity_due_at->toDateString())->toBe('2026-09-13');
    });
});

it('gives a leaver with two years’ service no gratuity deadline at all', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $anjali = leaverBasedAt($shimla, joined: '2024-04-01', called: 'Anjali Verma');

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), $anjali, null, '2026-08-14');

        // Null is the answer the settlement statement reads to decide whether to show a
        // gratuity line at all, so nothing else has to be stored to say she is not owed
        // one. Her settlement deadline is unaffected.
        expect($case->gratuity_due_at)->toBeNull()
            ->and($case->statutory_due_at->toDateString())->toBe('2026-08-18');
    });
});

it('owes gratuity to somebody who completed exactly five years and not to somebody a day short', function () {
    TenantContext::run($this->meridian, function () {
        $exit = exitCarryingDeadlines();
        $shimla = Office::factory()->named('Shimla')->create();

        $exactlyFive = leaverBasedAt($shimla, joined: '2021-08-14', called: 'Chandni Bhatt');
        $oneDayShort = leaverBasedAt($shimla, joined: '2021-08-15', called: 'Rohit Sethi');

        $chandni = (new CaseEngine)->open($exit, $exactlyFive, null, '2026-08-14');
        $rohit = (new CaseEngine)->open($exit, $oneDayShort, null, '2026-08-14');

        expect($chandni->gratuity_due_at->toDateString())->toBe('2026-09-13')
            ->and($rohit->gratuity_due_at)->toBeNull();
    });
});

it('counts a promoted leaver’s service from the day they first joined', function () {
    // Rakesh joined in 2018 and was promoted in 2025, which writes a second job row. His
    // exit reads that second row, so the gratuity his eight years earn him depends
    // entirely on the joining date being carried across — which the record now refuses to
    // let anybody restart.
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $rakesh = leaverBasedAt($shimla, joined: '2018-04-01');

        $rakesh->currentEmployment->update(['effective_to' => '2025-03-31']);

        EmploymentRecord::factory()->forPerson($rakesh)->basedAt($shimla)
            ->effective('2025-04-01')
            ->create(['joining_date' => '2018-04-01', 'change_reason' => 'promotion']);

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), $rakesh, null, '2026-08-14');

        expect($case->subject_employment_record_id)->toBe($rakesh->fresh()->currentEmployment->id)
            ->and($case->gratuity_due_at->toDateString())->toBe('2026-09-13');
    });
});

it('never skips a weekend or a holiday when counting the thirty gratuity days', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        // Two holidays: the Monday inside the settlement window, and the Sunday the
        // gratuity deadline lands on.
        OfficeHoliday::factory()->at($shimla)->on('2026-08-17', 'Inside the settlement window')->create();
        OfficeHoliday::factory()->at($shimla)->on('2026-09-13', 'On the gratuity deadline itself')->create();

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        // The settlement deadline moves for its holiday. The gratuity deadline stays on a
        // Sunday that the office also closes for, because thirty days means thirty days.
        // This is the assertion that fails if anybody reaches for the working-day
        // calendar here out of habit.
        expect($case->statutory_due_at->toDateString())->toBe('2026-08-19')
            ->and($case->gratuity_due_at->toDateString())->toBe('2026-09-13')
            ->and($case->gratuity_due_at->isSunday())->toBeTrue()
            ->and($shimla->fresh()->isWorkingDay($case->gratuity_due_at))->toBeFalse();
    });
});

/*
| Saying which calendar answered, and warning when it was empty
*/

it('records which calendar a deadline was counted against, and that the calendar was empty', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        $opened = CaseEvent::query()->where('case_id', $case->getKey())->where('type', 'case_opened')->sole();

        // Nobody has filled Shimla's holiday list in, so this deadline got weekends off
        // and nothing else. A screen showing it has to be able to say so.
        expect($opened->payload['deadlines_counted_against'])->toBe('Shimla')
            ->and($opened->payload['counted_from_an_empty_calendar'])->toBeTrue();
    });
});

it('stops saying the calendar was empty once the office has holidays on it', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        OfficeHoliday::factory()->at($shimla)->on('2026-10-02', 'Gandhi Jayanti')->create();

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        $opened = CaseEvent::query()->where('case_id', $case->getKey())->where('type', 'case_opened')->sole();

        expect($opened->payload['counted_from_an_empty_calendar'])->toBeFalse();
    });
});

/*
| What is refused rather than answered with a wrong date
*/

it('leaves both deadlines empty on a process that carries no leaving date', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla));

        expect($case->statutory_from)->toBeNull()
            ->and($case->statutory_due_at)->toBeNull()
            ->and($case->gratuity_due_at)->toBeNull();
    });
});

it('refuses a leaving date on a process that is not about a person', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = exitCarryingDeadlines('none');

        expect(fn () => (new CaseEngine)->open($hiring, null, null, '2026-08-14'))
            ->toThrow(ProcessRefused::class, 'has no office calendar and cannot carry a legal deadline');

        expect(ProcessCase::query()->count())->toBe(0);
    });
});

it('refuses a leaving date when the person’s job row names no office', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = User::factory()->named('Rakesh Menon')->create();
        EmploymentRecord::factory()->forPerson($rakesh)->create();

        expect(fn () => (new CaseEngine)->open(exitCarryingDeadlines(), $rakesh, null, '2026-08-14'))
            ->toThrow(ProcessRefused::class, 'has no working-day calendar to count their legal deadline against');

        // Nothing was written. A case carrying a leaving date with no deadline beside it
        // would be a legal date nobody ever worked out.
        expect(ProcessCase::query()->count())->toBe(0);
    });
});

/*
| Amending the date the clocks count from — the only route by which a deadline moves
|
| Rakesh's notice is extended by a week, so his last working day moves from Friday 14
| August to Friday 21 August. Everything the statute counts from that date has to move
| behind it, everything the case says he *was* has to stay exactly as it is, and every
| department still holding a clearance has to be told.
*/

/** A leaver whose job row also names the designation and the manager the case will read. */
function leaverWithADesignationAndAManager(Office $office): User
{
    $rakesh = User::factory()->named('Rakesh Menon')->create();

    EmploymentRecord::factory()->forPerson($rakesh)
        ->basedAt($office)
        ->designated(Designation::factory()->named('Senior Manager')->create())
        ->reportingTo(User::factory()->named('Anjali Verma')->create())
        ->create(['joining_date' => '2019-04-01']);

    return $rakesh;
}

it('moves both deadlines when the leaver’s last working day is extended', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        expect($case->statutory_due_at->toDateString())->toBe('2026-08-18')
            ->and($case->gratuity_due_at->toDateString())->toBe('2026-09-13');

        (new CaseEngine)->amendTheDateTheClocksCountFrom(
            $case, '2026-08-21', User::factory()->named('Anjali Verma')->create(), 'Notice extended by a week'
        );

        // Two working days from the new Friday, and thirty ordinary days from it.
        expect($case->fresh()->statutory_from->toDateString())->toBe('2026-08-21')
            ->and($case->fresh()->statutory_due_at->toDateString())->toBe('2026-08-25')
            ->and($case->fresh()->gratuity_due_at->toDateString())->toBe('2026-09-20');
    });
});

it('leaves the job row the case is pinned to exactly as it was', function () {
    // The case reads Rakesh's designation and manager through that row, and the whole
    // reason the leaving date lives on the case is that the row must not move under it.
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $case = (new CaseEngine)->open(
            exitCarryingDeadlines(), leaverWithADesignationAndAManager($shimla), null, '2026-08-14'
        );

        $pinnedRow = $case->subjectEmploymentRecord;
        $before = [
            $pinnedRow->getKey(),
            $pinnedRow->recorded_designation_name,
            $pinnedRow->reportsTo->name,
        ];

        (new CaseEngine)->amendTheDateTheClocksCountFrom(
            $case, '2026-08-21', User::factory()->named('Priya Nair')->create(), 'Notice extended by a week'
        );

        $after = $case->fresh()->subjectEmploymentRecord;

        expect([$after->getKey(), $after->recorded_designation_name, $after->reportsTo->name])->toBe($before)
            ->and($after->recorded_designation_name)->toBe('Senior Manager')
            ->and($after->reportsTo->name)->toBe('Anjali Verma')
            // Still one job row. Nothing was inserted to carry the new date.
            ->and(EmploymentRecord::query()->where('user_id', $after->user_id)->count())->toBe(1);
    });
});

it('records the reason, both dates before and after, and everyone holding a clearance', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();
        ProcessStep::factory()->of($exit)->at(1, 1)->named('IT clearance')->clearance()->create();
        ProcessStep::factory()->of($exit)->at(2, 1)->named('Finance clearance')->clearance()->create();
        $exit->publish();

        $engine = new CaseEngine;
        $case = $engine->open($exit, leaverBasedAt($shimla), null, '2026-08-14');

        // Deepak has picked IT up. Nobody has touched Finance, which is exactly the step
        // a chase exists for and the one with no row to find it by.
        $deepak = User::factory()->named('Deepak Rao')->create();
        $engine->claim($case, 1, $deepak);

        $anjali = User::factory()->named('Anjali Verma')->create();
        $engine->amendTheDateTheClocksCountFrom($case, '2026-08-21', $anjali, 'Notice extended by a week');

        $amendment = CaseEvent::query()->where('type', 'case_amended')->sole();

        expect($amendment->actor_id)->toBe($anjali->getKey())
            ->and($amendment->payload['reason'])->toBe('Notice extended by a week')
            ->and($amendment->payload['counted_from'])->toEqual(['was' => '2026-08-14', 'now' => '2026-08-21'])
            ->and($amendment->payload['statutory_due_at'])->toEqual(['was' => '2026-08-18', 'now' => '2026-08-25'])
            ->and($amendment->payload['gratuity_due_at'])->toEqual(['was' => '2026-09-13', 'now' => '2026-09-20'])
            ->and($amendment->payload['counted_against'])->toBe('Shimla')
            // Both clearances are owed the news. The one nobody has picked up names
            // nobody, and module 03 turns the step's own rule into the people to tell.
            ->and($amendment->payload['open_steps'])->toEqual([
                ['sequence' => 1, 'step' => 'IT clearance', 'held_by' => $deepak->getKey()],
                ['sequence' => 2, 'step' => 'Finance clearance', 'held_by' => null],
            ]);
    });
});

it('asks again whether gratuity is owed, because the answer is about the same date', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        // Chandni's fifth anniversary is 14 August 2026, so leaving that day earns her
        // gratuity and leaving the day before does not.
        $chandni = leaverBasedAt($shimla, joined: '2021-08-14', called: 'Chandni Bhatt');
        $anjali = User::factory()->named('Anjali Verma')->create();

        $case = (new CaseEngine)->open(exitCarryingDeadlines(), $chandni, null, '2026-08-14');

        expect($case->gratuity_due_at->toDateString())->toBe('2026-09-13');

        // Brought forward by a day, and she is a day short of five years.
        (new CaseEngine)->amendTheDateTheClocksCountFrom($case, '2026-08-13', $anjali, 'Leaving a day earlier');

        expect($case->fresh()->gratuity_due_at)->toBeNull();

        // Put back, and it is owed again.
        (new CaseEngine)->amendTheDateTheClocksCountFrom($case, '2026-08-14', $anjali, 'Reverted, entered in error');

        expect($case->fresh()->gratuity_due_at->toDateString())->toBe('2026-09-13');
    });
});

it('counts the new deadline against the calendar as it stands now, holiday list corrected and all', function () {
    // Adding a holiday never moves a deadline on its own — that rule is proven above and
    // is what keeps a legal date still under a running case. Amending is the deliberate
    // act that is allowed to see the corrected list, and it is the only one.
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        // Somebody notices Independence Day was missing and adds the Monday after the new
        // leaving date too.
        OfficeHoliday::factory()->at($shimla)->on('2026-08-24', 'Local holiday')->create();

        expect($case->fresh()->statutory_due_at->toDateString())->toBe('2026-08-18');

        (new CaseEngine)->amendTheDateTheClocksCountFrom(
            $case, '2026-08-21', User::factory()->named('Anjali Verma')->create(), 'Notice extended by a week'
        );

        // Friday 21st, then the weekend, then the Monday is now closed: Tuesday and
        // Wednesday are the two working days.
        expect($case->fresh()->statutory_due_at->toDateString())->toBe('2026-08-26');
    });
});

it('says nothing at all when the date handed in is the one the case already has', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        (new CaseEngine)->amendTheDateTheClocksCountFrom(
            $case, '2026-08-14', User::factory()->named('Anjali Verma')->create(), 'Confirming the date'
        );

        // A line in the record a tribunal reads should mean something moved.
        expect(CaseEvent::query()->where('type', 'case_amended')->count())->toBe(0)
            ->and($case->fresh()->statutory_due_at->toDateString())->toBe('2026-08-18');
    });
});

it('will not move a deadline without saying why', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        (new CaseEngine)->amendTheDateTheClocksCountFrom(
            $case, '2026-08-21', User::factory()->named('Anjali Verma')->create(), '   '
        );
    });
})->throws(ProcessRefused::class, 'Moving the date a case counts its deadlines from has to say why');

it('will not move the deadline of an exit that has already ended', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $anjali = User::factory()->named('Anjali Verma')->create();
        $case = (new CaseEngine)->open(exitCarryingDeadlines(), leaverBasedAt($shimla), null, '2026-08-14');

        (new CaseEngine)->cancel($case, $anjali, 'Resignation withdrawn');

        (new CaseEngine)->amendTheDateTheClocksCountFrom($case, '2026-08-21', $anjali, 'Notice extended');
    });
})->throws(ProcessRefused::class, 'This case is cancelled, and nothing further can be recorded on it');

it('will not put a leaving date on a hiring request, which is about nobody', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = exitCarryingDeadlines('none');
        $case = (new CaseEngine)->open($hiring);

        (new CaseEngine)->amendTheDateTheClocksCountFrom(
            $case, '2026-08-21', User::factory()->named('Anjali Verma')->create(), 'Typed on the wrong case'
        );
    });
})->throws(ProcessRefused::class, 'has no office calendar and cannot carry a legal deadline');
