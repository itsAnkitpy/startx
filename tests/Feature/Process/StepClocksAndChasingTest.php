<?php

use App\Exceptions\ProcessRefused;
use App\Models\CaseStep;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\OfficeHoliday;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
| Step 4 of module 02, second piece: each step's own clock, and the chasing on it.
|
| Separate from the two legal dates on the case. Meridian promises itself that its IT
| clearance is done within a day and its Finance clearance within four hours, and neither
| promise has a legal consequence. What they have is the chasing: a nudge halfway through,
| another at three-quarters, and at the end the chase goes above whoever is holding it —
| while there is still statutory time left to act.
|
| Three rules underneath all of it. The clock starts when the step became somebody's turn
| and not when the case opened. It counts against the calendar of the office the *subject*
| worked in, so it does not move when somebody claims the step. And it never, ever pauses.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

/**
 * A live exit: the manager approves, then the IT clearance with a target of its own.
 *
 * @param  list<float>|null  $nudgeAt  fractions replacing the usual half and three-quarters
 */
function exitWithATimedClearance(int $hours = 24, ?array $nudgeAt = null): ProcessTemplate
{
    $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

    ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')
        ->offering('approved', 'sent_back')->create();

    $clearance = ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('IT clearance')->clearance()->dueIn($hours);

    if ($nudgeAt !== null) {
        $clearance = $clearance->nudgingAt(...$nudgeAt);
    }

    $clearance->create();

    $exit->publish();

    return $exit;
}

/** The leaver, based at an office whose calendar every clock on his exit answers to. */
function leaverAt(Office $office, string $called = 'Rakesh Menon'): User
{
    $person = User::factory()->holdingTheRole('exit_team')->named($called)->create();

    EmploymentRecord::factory()->forPerson($person)->basedAt($office)->create();

    return $person;
}

/** The one step whose turn it is, read the way the reminders and the queue read it. */
function theOpenStep(ProcessCase $case): AvailableStep
{
    return (new AvailableSteps)->for($case->fresh())->sole();
}

/*
| When a step's own clock starts, and what stops it
*/

it('starts a step’s clock when that step became somebody’s turn, not when the case opened', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 24);

        $this->travelTo('2026-08-12 09:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');

        // The manager sits on it for two days. The clearance behind him has not started.
        $this->travelTo('2026-08-14 15:00:00');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);

        // One working day from Friday afternoon: Saturday and Sunday do not tick, so it
        // runs out on Monday afternoon rather than on the Saturday.
        expect(theOpenStep($case)->dueAt->toDateTimeString())->toBe('2026-08-17 15:00:00');
    });
});

it('stops a step’s clock for the weekend and for a holiday at the subject’s office', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        OfficeHoliday::factory()->at($shimla)->on('2026-08-17', 'A Shimla holiday')->create();

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 8);

        $this->travelTo('2026-08-14 21:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);

        // Three hours left in Friday, then nothing at all for Saturday, Sunday and the
        // Monday holiday, and the last five hours land on the Tuesday morning.
        expect(theOpenStep($case)->dueAt->toDateTimeString())->toBe('2026-08-18 05:00:00');
    });
});

it('gives a step the same deadline before and after somebody in another office claims it', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        OfficeHoliday::factory()->at($shimla)->on('2026-08-17', 'A Shimla holiday')->create();

        $gurgaon = Office::factory()->named('Gurgaon')->create();
        $deepak = leaverAt($gurgaon, called: 'Deepak Rao');

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 24);

        $this->travelTo('2026-08-14 10:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);

        $unclaimed = theOpenStep($case)->dueAt;

        // Deepak works in Gurgaon, which is open on the Monday. Picking the step up must
        // not move the date onto his calendar — under the rule this replaced he would have
        // lost a day by helping, and gained one when the holiday was his own office's.
        (new CaseEngine)->claim($case->fresh(), 2, $deepak);

        expect(theOpenStep($case)->dueAt->toDateTimeString())
            ->toBe($unclaimed->toDateTimeString())
            ->toBe('2026-08-18 10:00:00');
    });
});

it('keeps a held step’s clock running, and the clock of a step waiting on somebody outside', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 8);

        $this->travelTo('2026-08-12 09:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);

        $running = theOpenStep($case)->dueAt->toDateTimeString();

        // Finance puts the clearance on hold because Rakesh has not returned a laptop.
        // Every service desk in the field would pause here and we refuse to, because the
        // statutory clock underneath the case does not pause either.
        (new CaseEngine)->decide($case, 2, 'held', $hr, reason: 'Waiting on the laptop');

        $held = theOpenStep($case);

        expect($held->attempt->outcome)->toBe('held')
            ->and($held->dueAt->toDateTimeString())->toBe($running);

        // And it goes on running: five hours later it is overdue rather than frozen.
        $this->travelTo('2026-08-12 18:00:00');

        expect(theOpenStep($case)->escalationOwed)->toBeTrue();
    });
});

it('leaves a step with no target of its own with nothing due and nothing chased', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();
        ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('IT clearance')->clearance()->create();
        $exit->publish();

        $case = (new CaseEngine)->open($exit, leaverAt($shimla), null, '2026-08-14');

        $step = theOpenStep($case);

        expect($step->dueAt)->toBeNull()
            ->and($step->nudgesOwed)->toBe(0)
            ->and($step->escalationOwed)->toBeFalse();
    });
});

/*
| The chasing — half, three-quarters, and above the holder at the end
*/

it('owes one nudge past half the target, two past three-quarters, and the escalation at the end', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 8);

        // Wednesday, so nothing in this test bumps into a weekend.
        $this->travelTo('2026-08-12 09:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);

        expect(theOpenStep($case)->dueAt->toDateTimeString())->toBe('2026-08-12 17:00:00');

        $this->travelTo('2026-08-12 12:00:00');
        expect(theOpenStep($case))
            ->nudgesOwed->toBe(0)
            ->escalationOwed->toBeFalse();

        // Half of eight hours.
        $this->travelTo('2026-08-12 13:00:00');
        expect(theOpenStep($case))
            ->nudgesOwed->toBe(1)
            ->escalationOwed->toBeFalse();

        // Three-quarters.
        $this->travelTo('2026-08-12 15:00:00');
        expect(theOpenStep($case))
            ->nudgesOwed->toBe(2)
            ->escalationOwed->toBeFalse();

        // The moment it runs out — not a minute after it, because on a two-working-day
        // statutory clock a chase that lands after the breach is worth nothing.
        $this->travelTo('2026-08-12 17:00:00');
        expect(theOpenStep($case))
            ->nudgesOwed->toBe(2)
            ->escalationOwed->toBeTrue();
    });
});

it('does not chase somebody over the weekend just because half the calendar has gone by', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 8);

        // The clearance opens at nine on Friday evening: three working hours left in the
        // day and the other five waiting for Monday, so it is due at five on Monday
        // morning.
        $this->travelTo('2026-08-14 21:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);

        expect(theOpenStep($case)->dueAt->toDateTimeString())->toBe('2026-08-17 05:00:00');

        // Sunday lunchtime is halfway between those two moments on an ordinary calendar,
        // and it is Deepak's day off. Only three of his eight hours have gone, so nothing
        // is owed — this is the assertion that fails if the stages are worked out by
        // dividing the days rather than the work.
        $this->travelTo('2026-08-16 13:00:00');
        expect(theOpenStep($case)->nudgesOwed)->toBe(0);

        // Half his working time is up an hour into Monday.
        $this->travelTo('2026-08-17 00:30:00');
        expect(theOpenStep($case)->nudgesOwed)->toBe(0);

        $this->travelTo('2026-08-17 01:00:00');
        expect(theOpenStep($case)->nudgesOwed)->toBe(1);
    });
});

it('chases at the fractions a step names for itself', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 10, nudgeAt: [0.9]);

        $this->travelTo('2026-08-12 09:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);

        // Nothing at half, because this step asked to be left alone until nine-tenths.
        $this->travelTo('2026-08-12 15:00:00');
        expect(theOpenStep($case)->nudgesOwed)->toBe(0);

        $this->travelTo('2026-08-12 18:00:00');
        expect(theOpenStep($case)->nudgesOwed)->toBe(1);
    });
});

it('sends the escalation to the manager of whoever is holding the step, looked up now', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 8);

        $priya = User::factory()->holdingTheRole('exit_team')->named('Priya Nair')->create();
        $deepak = User::factory()->holdingTheRole('exit_team')->named('Deepak Rao')->create();
        EmploymentRecord::factory()->forPerson($deepak)->basedAt($shimla)->reportingTo($priya)->create();

        $this->travelTo('2026-08-12 09:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);
        (new CaseEngine)->claim($case->fresh(), 2, $deepak);

        // Before it runs out nobody is escalated to at all.
        expect(theOpenStep($case)->escalateTo)->toBeNull();

        $this->travelTo('2026-08-12 17:00:00');

        expect(theOpenStep($case)->escalateTo->name)->toBe('Priya Nair');
    });
});

it('follows a change of manager rather than the manager who was there when the case opened', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 8);

        $priya = User::factory()->holdingTheRole('exit_team')->named('Priya Nair')->create();
        $anjali = User::factory()->holdingTheRole('exit_team')->named('Anjali Verma')->create();
        $deepak = User::factory()->holdingTheRole('exit_team')->named('Deepak Rao')->create();

        $wasUnderPriya = EmploymentRecord::factory()->forPerson($deepak)->basedAt($shimla)
            ->reportingTo($priya)->effective('2024-04-01')->create();

        $this->travelTo('2026-08-12 09:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);
        (new CaseEngine)->claim($case->fresh(), 2, $deepak);

        // Deepak moves under Anjali the same day. Module 01 ends the old row and writes a
        // new one rather than editing it, and the chase has to follow the new one: the
        // question a chase asks is who is above him today.
        $wasUnderPriya->update(['effective_to' => '2026-08-11']);
        EmploymentRecord::factory()->forPerson($deepak)->basedAt($shimla)
            ->reportingTo($anjali)->effective('2026-08-12')->create();

        $this->travelTo('2026-08-12 17:00:00');

        expect(theOpenStep($case)->escalateTo->name)->toBe('Anjali Verma');
    });
});

it('names nobody to escalate to on a step nobody has picked up, leaving the group it was meant for', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 8);

        $this->travelTo('2026-08-12 09:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);

        $this->travelTo('2026-08-12 17:00:00');

        $step = theOpenStep($case);

        // This is the step most likely to breach and it has no row anywhere. It is still
        // found, it is still overdue, and there is no holder and so no manager above one —
        // so the chase goes to the group the step names, which is what every product that
        // runs a clock does and what module 03 turns into people.
        expect($step->attempt)->toBeNull()
            ->and($step->escalationOwed)->toBeTrue()
            ->and($step->escalateTo)->toBeNull()
            ->and($step->step->assignee_rule)->not->toBeNull();
    });
});

it('names nobody to escalate to when the holder has nobody above them', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 8);

        $chandni = User::factory()->holdingTheRole('exit_team')->named('Chandni Bhatt')->create();
        EmploymentRecord::factory()->forPerson($chandni)->basedAt($shimla)->create();

        $this->travelTo('2026-08-12 09:00:00');
        $case = (new CaseEngine)->open($exit, leaverAt($shimla), $hr, '2026-08-14');
        (new CaseEngine)->decide($case, 1, 'approved', $hr);
        (new CaseEngine)->claim($case->fresh(), 2, $chandni);

        $this->travelTo('2026-08-12 17:00:00');

        expect(theOpenStep($case)->escalateTo)->toBeNull();
    });
});

it('finds every overdue step across many cases without asking the database per case', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $exit = exitWithATimedClearance(hours: 8);
        $rakesh = leaverAt($shimla);
        $record = EmploymentRecord::query()->where('user_id', $rakesh->getKey())->first();

        $this->travelTo('2026-08-12 09:00:00');

        ProcessCase::factory()->count(40)->on($exit)->about($rakesh, $record)->create([
            'opened_at' => now(),
            'statutory_from' => '2026-08-14',
        ]);

        // Every one of them past its first step, so forty clearances are running at once.
        ProcessCase::query()->pluck('id')->each(function (int $caseId) use ($hr) {
            CaseStep::factory()->create([
                'case_id' => $caseId,
                'sequence' => 1,
                'assignee_id' => $hr->getKey(),
                'outcome' => 'approved',
                'acted_at' => now(),
            ]);
        });

        $this->travelTo('2026-08-12 17:00:00');

        $open = ProcessCase::query()->whereNull('closed_at')->whereNull('cancelled_at')->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $available = (new AvailableSteps)->forAll($open);
        $queries = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        expect($available)->toHaveCount(40)
            ->and($available->every(fn (AvailableStep $step) => $step->escalationOwed))->toBeTrue();

        // Six for the reading itself and not one more for the chasing, because nobody
        // holds any of these steps so no manager has to be looked up.
        expect($queries)->toBe(6);
    });
});

/*
| What publishing refuses, so a reminder that could never fire never goes live
*/

it('refuses to publish a step that is chased but has no time limit of its own', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();
        ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('IT clearance')->clearance()
            ->nudgingAt(0.5)->create();

        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'has no time limit of its own');
    });
});

it('refuses to publish a nudge that does not land inside the time limit', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();
        ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('IT clearance')->clearance()
            ->dueIn(8)->nudgingAt(1.5)->create();

        // A nudge at one and a half times the target lands after the escalation that has
        // already gone to the holder's manager, so it can only ever be noise or nothing.
        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'which is not part of the way through it');
    });
});

it('lets a step with an ordinary time limit and no reminder rule of its own go live', function () {
    TenantContext::run($this->meridian, function () {
        $exit = exitWithATimedClearance(hours: 24);

        expect($exit->fresh()->status)->toBe(ProcessTemplate::Published);
    });
});
