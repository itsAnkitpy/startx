<?php

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\Designation;
use App\Models\EmployeeAsset;
use App\Models\EmploymentRecord;
use App\Models\FormField;
use App\Models\Office;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Settings\SettingDeclaration;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
| Step 3 of module 02: running a case.
|
| Whose turn it is, what the person holding a step may choose, what sending the case back
| does and does not undo, and how a case ends.
|
| The rule underneath all of it: nothing is written when a step becomes somebody's turn.
| A step nobody has touched has no row anywhere, and availability is worked out from what
| has already closed — so most of what follows is checking that a step with no row still
| behaves like a step.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

afterEach(function () {
    Settings::forgetDeclared();
});

function engine(): CaseEngine
{
    return new CaseEngine;
}

/** A live process, built as a draft and switched on the way the product does it. */
function liveProcess(callable $addSteps, string $about = 'employee'): ProcessTemplate
{
    $template = ProcessTemplate::factory()->named('exit', 'Exit')->about($about)->create();

    $addSteps($template);

    $template->publish();

    return $template;
}

/**
 * Meridian's exit: the manager approves, then IT and Finance clear side by side, then HR
 * closes.
 */
function meridiansExit(): ProcessTemplate
{
    return liveProcess(function (ProcessTemplate $exit) {
        ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')
            ->offering('approved', 'rejected', 'sent_back')->create();
        ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('IT clearance')
            ->collecting('assets_out', FormField::Number)->clearance()->dueIn(24)->create();
        ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(3, 2)->named('Finance clearance')->clearance()->dueIn(8)->create();
        ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(4, 3)->named('Close')->offering('approved')->create();
    });
}

/**
 * The leaver, with the job row that is true for him today and the office whose calendar
 * every clock on his exit is counted against.
 */
function rakesh(): User
{
    $rakesh = User::factory()->holdingTheRole('exit_team')->named('Rakesh Menon')->create();

    EmploymentRecord::factory()->forPerson($rakesh)
        ->basedAt(Office::factory()->create())
        ->create();

    return $rakesh;
}

/** The names of the steps whose turn it is right now. */
function whoseTurn(ProcessCase $case): array
{
    return (new AvailableSteps)->for($case)
        ->map(fn (AvailableStep $available) => $available->step->name)
        ->all();
}

/*
| Whose turn it is
*/

it('runs a case from end to end, one group at a time', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        expect(whoseTurn($case))->toBe(['Manager approval']);

        engine()->decide($case, 1, 'approved', $hr);

        // Both clearances become available in the same instant, because they run side by
        // side. Nothing was written to make that happen.
        expect(whoseTurn($case->fresh()))->toBe(['IT clearance', 'Finance clearance']);

        engine()->decide($case, 2, 'approved', $hr);
        engine()->decide($case, 3, 'approved', $hr);

        expect(whoseTurn($case->fresh()))->toBe(['Close']);

        engine()->decide($case, 4, 'approved', $hr);

        expect($case->fresh()->state)->toBe(ProcessCase::Closed);
        expect(whoseTurn($case->fresh()))->toBe([]);
    });
});

it('keeps a later group shut while any step in the group before it is still open', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);
        engine()->decide($case, 2, 'approved', $hr);

        // Finance has not cleared, so nothing after that group has opened.
        expect(whoseTurn($case->fresh()))->toBe(['Finance clearance']);

        expect(fn () => engine()->decide($case->fresh(), 4, 'approved', $hr))
            ->toThrow(ProcessRefused::class, 'Step 4 is not open on this case.');
    });
});

it('writes no row at all for a step nobody has touched', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        expect(CaseStep::query()->where('case_id', $case->getKey())->count())->toBe(0);

        engine()->decide($case, 1, 'approved', $hr);

        // One row for the step somebody acted on, and nothing for the two now waiting.
        expect(CaseStep::query()->where('case_id', $case->getKey())->count())->toBe(1);
        expect(whoseTurn($case->fresh()))->toHaveCount(2);
    });
});

it('measures a waiting step from when the step blocking it closed, with no row to read it off', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        $this->travelTo('2026-09-07 11:00:00');
        engine()->decide($case, 1, 'approved', $hr);

        $this->travelTo('2026-09-09 15:00:00');

        $waiting = (new AvailableSteps)->for($case->fresh())->firstWhere('step.name', 'IT clearance');

        expect($waiting->attempt)->toBeNull();
        expect($waiting->availableSince->toDateTimeString())->toBe('2026-09-07 11:00:00');
    });
});

it('lets two steps in one group close at the same moment and opens the next group to both', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        $it = User::factory()->holdingTheRole('exit_team')->create();
        $finance = User::factory()->holdingTheRole('exit_team')->create();

        // Both hold their own step, and neither waits for the other to let go of
        // anything: there is no row for them to contend over.
        engine()->claim($case->fresh(), 2, $it);
        engine()->claim($case->fresh(), 3, $finance);

        engine()->decide($case->fresh(), 2, 'approved', $it);
        engine()->decide($case->fresh(), 3, 'approved', $finance);

        expect(CaseStep::query()->where('case_id', $case->getKey())->whereNotNull('outcome')->count())->toBe(3);
        expect(whoseTurn($case->fresh()))->toBe(['Close']);
    });
});

it('gives a shared queue exactly one winner and refuses the loser', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        $first = User::factory()->holdingTheRole('exit_team')->create();
        $second = User::factory()->holdingTheRole('exit_team')->create();

        engine()->claim($case->fresh(), 2, $first);

        expect(fn () => engine()->claim($case->fresh(), 2, $second))
            ->toThrow(ProcessRefused::class, 'has already been picked up by somebody else');
    });
});

it('refuses the loser of a genuinely simultaneous pick-up in words rather than a database error', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        $anjali = User::factory()->holdingTheRole('exit_team')->named('Anjali Rao')->create();
        $deepak = User::factory()->holdingTheRole('exit_team')->named('Deepak Iyer')->create();

        // Both read the queue and both see IT clearance free. Anjali's row lands between
        // Deepak's read and his write, which is the one order the test above cannot
        // produce and the only one the database itself has to refuse.
        $anjaliHasWritten = false;

        CaseStep::creating(function () use ($case, $anjali, &$anjaliHasWritten): void {
            if ($anjaliHasWritten) {
                return;
            }

            $anjaliHasWritten = true;

            CaseStep::create([
                'case_id' => $case->getKey(),
                'sequence' => 2,
                'assignee_id' => $anjali->getKey(),
            ]);
        });

        expect(fn () => engine()->claim($case->fresh(), 2, $deepak))
            ->toThrow(ProcessRefused::class, '[IT clearance] has already been picked up by somebody else');

        // Deepak's attempt leaves nothing behind. Anjali's row goes with it here only
        // because forcing the two into one process puts both inside Deepak's transaction;
        // across two real requests hers is committed before his ever starts.
        expect(CaseStep::query()->where('case_id', $case->getKey())->where('assignee_id', $deepak->getKey())->count())
            ->toBe(0);
    });
});

it('says nothing in the history when somebody picks up a step that is already theirs', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $deepak = User::factory()->holdingTheRole('exit_team')->named('Deepak Iyer')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        engine()->claim($case->fresh(), 2, $deepak);
        engine()->claim($case->fresh(), 2, $deepak);

        $claims = CaseEvent::query()->where('case_id', $case->getKey())->where('type', 'step_claimed')->count();

        expect($claims)->toBe(1);
    });
});

/*
| What the person holding a step may choose
*/

it('refuses a rejection on a step that only offers approve and hold', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        expect(fn () => engine()->decide($case->fresh(), 2, 'rejected', $hr))
            ->toThrow(ProcessRefused::class, '[IT clearance] does not offer [rejected]');
    });
});

it('will not record a hold without a reason', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        expect(fn () => engine()->decide($case->fresh(), 2, 'held', $hr))
            ->toThrow(ProcessRefused::class, 'Holding [IT clearance] has to say why.');
    });
});

it('keeps a held step open and keeps the case open behind it', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);
        engine()->decide($case->fresh(), 2, 'held', $hr, reason: 'Laptop still out.');
        engine()->decide($case->fresh(), 3, 'approved', $hr);

        expect(whoseTurn($case->fresh()))->toBe(['IT clearance']);
        expect($case->fresh()->state)->toBe(ProcessCase::Open);
    });
});

it('records a hold turned into a disputed settlement line as exactly that, and lets the case move on', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $finance = User::factory()->holdingTheRole('exit_team')->named('Finance Officer')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);
        engine()->decide($case->fresh(), 3, 'approved', $hr);
        engine()->claim($case->fresh(), 2, $finance);
        engine()->decide($case->fresh(), 2, 'held', $finance, reason: 'Recovery of 40,000 disputed.');

        $resolved = engine()->resolveHold(
            $case->fresh(), 2, 'closed_disputed', $finance, 'Raised as a disputed recovery line.'
        );

        // The permanent record must not say Finance approved a clearance Finance refused.
        expect($resolved->outcome)->toBe('closed_disputed');
        expect(whoseTurn($case->fresh()))->toBe(['Close']);
    });
});

it('records an override by HR against both HR and the department that was holding', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->named('Anjali Rao')->create();
        $it = User::factory()->holdingTheRole('exit_team')->named('Deepak Iyer')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);
        engine()->decide($case->fresh(), 3, 'approved', $hr);
        engine()->claim($case->fresh(), 2, $it);
        engine()->decide($case->fresh(), 2, 'held', $it, reason: 'Laptop still out.');

        $resolved = engine()->resolveHold($case->fresh(), 2, 'force_closed', $hr, 'Deepak is on leave.');

        expect($resolved->outcome)->toBe('force_closed');
        expect((int) $resolved->assignee_id)->toBe((int) $it->getKey());

        $event = CaseEvent::query()->where('case_id', $case->getKey())->latest('id')->first();

        expect((int) $event->actor_id)->toBe((int) $hr->getKey());
        expect($event->payload['held_by'])->toBe((int) $it->getKey());
        expect(whoseTurn($case->fresh()))->toBe(['Close']);
    });
});

it('keeps the reason a hold ended out of the answers the case branches on', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->named('Anjali Rao')->create();
        $it = User::factory()->holdingTheRole('exit_team')->named('Deepak Iyer')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);
        engine()->decide($case->fresh(), 3, 'approved', $hr);
        engine()->claim($case->fresh(), 2, $it);
        engine()->decide($case->fresh(), 2, 'held', $it, reason: 'Laptop still out.', payload: ['assets_out' => 1]);

        $resolved = engine()->resolveHold($case->fresh(), 2, 'force_closed', $hr, 'Deepak is on leave.');

        // A step's payload is what its own form collected, and later steps read their
        // conditions out of it. Anything we put there would be answerable by a condition.
        expect($resolved->payload)->toEqual(['assets_out' => 1]);

        $event = CaseEvent::query()->where('case_id', $case->getKey())->latest('id')->first();

        expect($event->payload['reason'])->toBe('Deepak is on leave.');
    });
});

it('refuses both hold endings on a step that was never held', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        expect(fn () => engine()->resolveHold($case->fresh(), 2, 'closed_disputed', $hr, 'No hold here.'))
            ->toThrow(ProcessRefused::class, '[IT clearance] is not on hold');

        expect(fn () => engine()->resolveHold($case->fresh(), 2, 'force_closed', $hr, 'No hold here.'))
            ->toThrow(ProcessRefused::class, '[IT clearance] is not on hold');
    });
});

it('will not let either hold ending be chosen from an ordinary step form', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        expect(fn () => engine()->decide($case, 1, 'closed_disputed', $hr))
            ->toThrow(ProcessRefused::class, 'is not something anyone chooses at a step');

        expect(fn () => engine()->decide($case, 1, 'force_closed', $hr))
            ->toThrow(ProcessRefused::class, 'is not something anyone chooses at a step');
    });
});

it('treats an approval on a held step as releasing the hold', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $it = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);
        engine()->claim($case->fresh(), 2, $it);
        engine()->decide($case->fresh(), 2, 'held', $it, reason: 'Laptop still out.');
        engine()->decide($case->fresh(), 2, 'approved', $it);

        expect(CaseStep::query()->where('case_id', $case->getKey())->where('sequence', 2)->count())->toBe(1);
        expect(whoseTurn($case->fresh()))->toBe(['Finance clearance']);
    });
});

it('will not let a colleague act on a step somebody else is holding', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->named('Anjali Rao')->create();
        $deepak = User::factory()->holdingTheRole('exit_team')->named('Deepak Iyer')->create();
        $colleague = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);
        engine()->claim($case->fresh(), 2, $deepak);
        engine()->decide($case->fresh(), 2, 'held', $deepak, reason: 'Laptop still out.');

        // Deepak is on leave. A teammate quietly approving in his name is the record
        // saying the wrong thing; the route is HR overriding it, which names both.
        expect(fn () => engine()->decide($case->fresh(), 2, 'approved', $colleague))
            ->toThrow(ProcessRefused::class, 'has already been picked up by somebody else');

        $overridden = engine()->resolveHold($case->fresh(), 2, 'force_closed', $hr, 'Deepak is on leave.');

        expect($overridden->outcome)->toBe('force_closed');
        expect((int) $overridden->assignee_id)->toBe((int) $deepak->getKey());
    });
});

it('ends the case when a step is rejected', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'rejected', $hr, reason: 'Resignation not accepted.');

        expect($case->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

/*
| Sending the case back
*/

it('reopens the one step a send-back names and leaves every sibling clearance closed', function () {
    TenantContext::run($this->meridian, function () {
        // Seven parallel clearances, the shape that made the old rule expensive: Finance
        // is the last of them and sends the case back two groups.
        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')
                ->offering('approved', 'rejected', 'sent_back')->create();

            foreach (['IT', 'Admin', 'Payroll', 'Legal', 'Facilities', 'Security', 'Finance'] as $index => $department) {
                ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at($index + 2, 2)->named("{$department} clearance")
                    ->offering('approved', 'held', 'sent_back')->create();
            }

            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(9, 3)->named('HR verification')->offering('approved')->create();
        });

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        foreach ([2, 3, 4, 5, 6, 7] as $sequence) {
            engine()->decide($case->fresh(), $sequence, 'approved', $hr);
        }

        engine()->decide($case->fresh(), 8, 'sent_back', $hr, reason: 'Wrong last working day.', sendBackTo: 1);

        // Six departments' correct work is not thrown away because the engine guessed it
        // might be stale.
        $stillClosed = CaseStep::query()
            ->where('case_id', $case->getKey())
            ->whereIn('sequence', [2, 3, 4, 5, 6, 7])
            ->whereNull('superseded_at')
            ->where('outcome', 'approved')
            ->count();

        expect($stillClosed)->toBe(6);

        // The manager's step is the only one open, and his previous attempt is still
        // readable behind it.
        expect(whoseTurn($case->fresh()))->toBe(['Manager approval']);
        expect(CaseStep::query()->where('case_id', $case->getKey())->where('sequence', 1)
            ->whereNotNull('superseded_at')->where('outcome', 'approved')->count())->toBe(1);
    });
});

it('brings the step that sent the case back round again once the redo is done', function () {
    TenantContext::run($this->meridian, function () {
        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')
                ->collecting('recovery')->offering('approved', 'sent_back')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Finance clearance')
                ->offering('approved', 'sent_back')->create();
        });

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);
        engine()->decide($case->fresh(), 2, 'sent_back', $hr, reason: 'Wrong figure.', sendBackTo: 1);

        expect(whoseTurn($case->fresh()))->toBe(['Manager approval']);

        engine()->decide($case->fresh(), 1, 'approved', $hr);

        expect(whoseTurn($case->fresh()))->toBe(['Finance clearance']);

        // Finance's earlier attempt is replaced rather than overwritten, and both are
        // readable.
        engine()->decide($case->fresh(), 2, 'approved', $hr);

        expect(CaseStep::query()->where('case_id', $case->getKey())->where('sequence', 2)->count())->toBe(2);
        expect($case->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

it('re-does a step that had already been claimed', function () {
    TenantContext::run($this->meridian, function () {
        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')
                ->collecting('recovery')->offering('approved', 'sent_back')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Finance clearance')
                ->offering('approved', 'sent_back')->create();
        });

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $manager = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->claim($case, 1, $manager);
        engine()->decide($case->fresh(), 1, 'approved', $manager);
        engine()->decide($case->fresh(), 2, 'sent_back', $hr, reason: 'Wrong figure.', sendBackTo: 1);

        $second = engine()->claim($case->fresh(), 1, $manager);

        expect($second->getKey())->not->toBe($case->steps()->where('sequence', 1)->first()->getKey());
        expect(CaseStep::query()->where('case_id', $case->getKey())->where('sequence', 1)->count())->toBe(2);
    });
});

it('makes a step the new answer needs appear on its own after a redo', function () {
    TenantContext::run($this->meridian, function () {
        declareDirectorThresholdForRunning();

        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')
                ->collecting('recovery')->offering('approved', 'sent_back')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Director approval')
                ->happensWhen([['source' => 'payload', 'field' => 'recovery', 'operator' => '>', 'value' => 100000]])
                ->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(3, 3)->named('Finance clearance')
                ->offering('approved', 'sent_back')->create();
        });

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr, payload: ['recovery' => 5000]);

        // Nothing to send to the director on a small figure.
        expect(whoseTurn($case->fresh()))->toBe(['Finance clearance']);

        engine()->decide($case->fresh(), 3, 'sent_back', $hr, reason: 'Recovery understated.', sendBackTo: 1);
        engine()->decide($case->fresh(), 1, 'approved', $hr, payload: ['recovery' => 400000]);

        expect(whoseTurn($case->fresh()))->toBe(['Director approval']);
    });
});

it('will not send the case back with nothing said about why', function () {
    TenantContext::run($this->meridian, function () {
        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Finance clearance')
                ->offering('approved', 'sent_back')->create();
        });

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        // The reason is the whole message. A request that comes back with no words on it
        // tells the person who has to correct it only that somebody was unhappy — and
        // every product that has this outcome at all demands the comment.
        expect(fn () => engine()->decide($case->fresh(), 2, 'sent_back', $hr, sendBackTo: 1))
            ->toThrow(ProcessRefused::class, 'has to say why');

        expect(whoseTurn($case->fresh()))->toBe(['Finance clearance']);
    });
});

it('will not send the case sideways to a step running at the same time', function () {
    TenantContext::run($this->meridian, function () {
        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('IT clearance')
                ->offering('approved', 'sent_back')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(3, 2)->named('Finance clearance')->offering('approved')->create();
        });

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        expect(fn () => engine()->decide($case->fresh(), 2, 'sent_back', $hr, reason: 'The last working day is wrong.', sendBackTo: 3))
            ->toThrow(ProcessRefused::class, 'does not run before it');

        expect(fn () => engine()->decide($case->fresh(), 2, 'sent_back', $hr, reason: 'The last working day is wrong.'))
            ->toThrow(ProcessRefused::class, 'has to name the step it goes back to');
    });
});

it('will not send the case back to a step this case never needed', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $gurgaon = Office::factory()->named('Gurgaon')->create();

        $exit = liveProcess(function (ProcessTemplate $exit) use ($gurgaon) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Gurgaon facilities')
                ->happensWhen([['source' => 'subject', 'field' => 'office_id', 'operator' => '=', 'value' => $gurgaon->getKey()]])
                ->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Finance clearance')
                ->offering('approved', 'sent_back')->create();
        });

        $rakesh = User::factory()->holdingTheRole('exit_team')->named('Rakesh Menon')->create();
        EmploymentRecord::factory()->forPerson($rakesh)->basedAt($shimla)->create();

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, $rakesh, $hr);

        // Rakesh works in Shimla, so the Gurgaon step never ran on this case. Sending it
        // back there would reopen nothing while the history said a step was reopened.
        expect(fn () => engine()->decide($case->fresh(), 2, 'sent_back', $hr, reason: 'Nothing was checked in Gurgaon.', sendBackTo: 1))
            ->toThrow(ProcessRefused::class, 'which this case never needed');

        expect(CaseEvent::query()->where('case_id', $case->getKey())->where('type', 'step_reopened')->count())->toBe(0);
        expect(whoseTurn($case->fresh()))->toBe(['Finance clearance']);
    });
});

/*
| How a case ends
*/

it('closes an exit at once when every step on it is skipped', function () {
    TenantContext::run($this->meridian, function () {
        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Handover of the leaver\'s team')
                ->happensWhen([['source' => 'subject', 'field' => 'manages_anyone', 'operator' => '=', 'value' => true]])
                ->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Return the laptop')
                ->happensWhen([['source' => 'subject', 'field' => 'equipment_issued', 'operator' => '=', 'value' => true]])
                ->offering('approved')->create();
        });

        $hr = User::factory()->holdingTheRole('exit_team')->create();

        // Rakesh manages nobody and holds nothing, so this exit is genuinely finished the
        // moment it opens. Left running it would sit in the queue for ever, breach its
        // statutory deadline and have no step for anybody to chase.
        $case = engine()->open($exit, rakesh(), $hr);

        expect(whoseTurn($case->fresh()))->toBe([]);
        expect($case->fresh()->state)->toBe(ProcessCase::Closed);

        expect(CaseEvent::query()->where('case_id', $case->getKey())->pluck('type')->all())
            ->toBe(['case_opened', 'case_closed']);
    });
});

it('will not record anything on a closed case', function () {
    TenantContext::run($this->meridian, function () {
        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Close')->offering('approved')->create();
        });

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->decide($case, 1, 'approved', $hr);

        expect($case->fresh()->state)->toBe(ProcessCase::Closed);

        expect(fn () => engine()->decide($case->fresh(), 1, 'approved', $hr))
            ->toThrow(ProcessRefused::class, 'This case is closed');

        expect(fn () => engine()->cancel($case->fresh(), $hr, 'Changed his mind.'))
            ->toThrow(ProcessRefused::class, 'This case is closed');
    });
});

it('cancels a withdrawn resignation, keeps what was done, and can never close it afterwards', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $manager = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->claim($case, 1, $manager);
        engine()->cancel($case->fresh(), $hr, 'Rakesh withdrew his resignation.');

        $case = $case->fresh();

        expect($case->state)->toBe(ProcessCase::Cancelled);
        expect($case->cancellation_reason)->toBe('Rakesh withdrew his resignation.');
        expect(CaseStep::query()->where('case_id', $case->getKey())->count())->toBe(1);
        expect(whoseTurn($case))->toBe([]);

        expect(fn () => engine()->decide($case, 1, 'approved', $manager))
            ->toThrow(ProcessRefused::class, 'This case is cancelled');
    });
});

it('will not record an approval on a case cancelled a moment earlier', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->named('Anjali Rao')->create();
        $manager = User::factory()->holdingTheRole('exit_team')->named('Priya Nair')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        // Priya's screen was drawn while the case was still running. Rakesh withdraws his
        // resignation, HR cancels, and a second later Priya presses approve on the page
        // she already had open.
        $priyasScreen = ProcessCase::query()->whereKey($case->getKey())->first();

        engine()->cancel($case->fresh(), $hr, 'Rakesh withdrew his resignation.');

        expect(fn () => engine()->decide($priyasScreen, 1, 'approved', $manager))
            ->toThrow(ProcessRefused::class, 'This case is cancelled');

        expect(CaseStep::query()->where('case_id', $case->getKey())->count())->toBe(0);
    });
});

it('will not cancel with a reason too long for the record to keep', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        expect(fn () => engine()->cancel($case, $hr, str_repeat('a', 256)))
            ->toThrow(ProcessRefused::class, 'A reason can be at most 255 characters long.');

        expect($case->fresh()->state)->toBe(ProcessCase::Open);
    });
});

it('keeps a cancellation reason typed with stray spaces around it', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        engine()->cancel($case, $hr, "  Rakesh withdrew his resignation.\n");

        expect($case->fresh()->cancellation_reason)->toBe('Rakesh withdrew his resignation.');
    });
});

it('will not cancel without a reason', function () {
    TenantContext::run($this->meridian, function () {
        $case = engine()->open(meridiansExit(), rakesh(), User::factory()->holdingTheRole('exit_team')->create());

        expect(fn () => engine()->cancel($case, User::factory()->holdingTheRole('exit_team')->create(), '  '))
            ->toThrow(ProcessRefused::class, 'Cancelling a case has to say why.');
    });
});

/*
| Opening a case, and what it freezes
*/

it('refuses to open an exit for somebody with no current job row', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $stranger = User::factory()->holdingTheRole('exit_team')->create();

        expect(fn () => engine()->open($exit, $stranger))
            ->toThrow(ProcessRefused::class, 'has no current job row');

        expect(fn () => engine()->open($exit))
            ->toThrow(ProcessRefused::class, 'is about an employee, so a case cannot be opened without one');
    });
});

it('opens a hiring request about nobody with both subject columns empty', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = liveProcess(function (ProcessTemplate $template) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($template)->at(1, 1)->named('Director approval')->create();
        }, about: 'none');

        $case = engine()->open($hiring);

        expect($case->subject_user_id)->toBeNull();
        expect($case->subject_employment_record_id)->toBeNull();

        expect(fn () => engine()->open($hiring, rakesh()))
            ->toThrow(ProcessRefused::class, 'so a case on it cannot name a person');
    });
});

it('will not open a case on a draft or a retired version', function () {
    TenantContext::run($this->meridian, function () {
        $draft = ProcessTemplate::factory()->named('exit', 'Exit')->create();
        ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($draft)->at(1, 1)->create();

        expect(fn () => engine()->open($draft, rakesh()))
            ->toThrow(ProcessRefused::class, 'a case can only be opened on a live process');
    });
});

it('pins the case to the job row that was true when it opened', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $rakesh = rakesh();
        $before = EmploymentRecord::query()->where('user_id', $rakesh->getKey())->first();

        $case = engine()->open($exit, $rakesh);

        // A promotion the next day writes a new row and ends the old one; the case still
        // reads the department and designation it opened against.
        $before->update(['effective_to' => '2026-09-05']);
        EmploymentRecord::factory()->forPerson($rakesh)->effective('2026-09-06')->create();

        expect((int) $case->fresh()->subject_employment_record_id)->toBe((int) $before->getKey());
    });
});

/*
| Branching, and why it reads frozen answers
*/

it('skips a step whose condition about the person is not met, with no form collecting it', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $gurgaon = Office::factory()->named('Gurgaon')->create();

        $exit = liveProcess(function (ProcessTemplate $exit) use ($gurgaon) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Gurgaon facilities clearance')
                ->happensWhen([['source' => 'subject', 'field' => 'office_id', 'operator' => '=', 'value' => $gurgaon->getKey()]])
                ->offering('approved')->create();
        });

        $rakesh = User::factory()->holdingTheRole('exit_team')->named('Rakesh Menon')->create();
        EmploymentRecord::factory()->forPerson($rakesh)->basedAt($shimla)->create();

        $case = engine()->open($exit, $rakesh);

        engine()->decide($case, 1, 'approved', User::factory()->holdingTheRole('exit_team')->create());

        // Shimla, so the Gurgaon step is not wanted and the case is simply finished.
        expect($case->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

it('keeps the handover step the case opened with, even after the exit moves the reports away', function () {
    TenantContext::run($this->meridian, function () {
        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Handover of the leaver\'s team')
                ->happensWhen([['source' => 'subject', 'field' => 'manages_anyone', 'operator' => '=', 'value' => true]])
                ->offering('approved')->create();
        });

        $rakesh = rakesh();
        $chandni = User::factory()->holdingTheRole('exit_team')->named('Chandni Verma')->create();

        // Three people report to Rakesh when he resigns.
        foreach (range(1, 3) as $ignored) {
            $report = User::factory()->holdingTheRole('exit_team')->create();
            EmploymentRecord::factory()->forPerson($report)->reportingTo($rakesh)->create();
        }

        $case = engine()->open($exit, $rakesh);

        engine()->decide($case, 1, 'approved', User::factory()->holdingTheRole('exit_team')->create());

        expect(whoseTurn($case->fresh()))->toBe(['Handover of the leaver\'s team']);

        // Day two: the exit does its own job and moves the three onto Chandni. Asked
        // again, Rakesh manages nobody — and the handover step would vanish with nothing
        // on the record saying it was ever expected.
        EmploymentRecord::query()->where('reports_to_id', $rakesh->getKey())
            ->update(['reports_to_id' => $chandni->getKey()]);

        expect(whoseTurn($case->fresh()))->toBe(['Handover of the leaver\'s team']);
    });
});

it('keeps the equipment clearance after IT marks the laptop returned', function () {
    TenantContext::run($this->meridian, function () {
        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('IT clearance')
                ->happensWhen([['source' => 'subject', 'field' => 'equipment_issued', 'operator' => '=', 'value' => true]])
                ->offering('approved')->create();
        });

        $rakesh = rakesh();

        $laptop = EmployeeAsset::create([
            'user_id' => $rakesh->getKey(),
            'asset_type' => 'laptop',
            'identifier' => 'MBP-1174',
            'issued_at' => '2024-04-01',
        ]);

        $case = engine()->open($exit, $rakesh);

        engine()->decide($case, 1, 'approved', User::factory()->holdingTheRole('exit_team')->create());

        expect(whoseTurn($case->fresh()))->toBe(['IT clearance']);

        $laptop->update(['returned_at' => '2026-09-06']);

        expect(whoseTurn($case->fresh()))->toBe(['IT clearance']);
    });
});

it('opens a step when only its second group of conditions holds', function () {
    TenantContext::run($this->meridian, function () {
        $senior = Designation::factory()->create(['name' => 'Vice President']);

        $exit = liveProcess(function (ProcessTemplate $exit) use ($senior) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')->collecting('annual_ctc')->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Director approval')
                ->happensWhen(
                    [['source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>', 'value' => 1500000]],
                    [['source' => 'subject', 'field' => 'designation_id', 'operator' => '=', 'value' => $senior->getKey()]],
                )
                ->offering('approved')->create();
        });

        $rakesh = User::factory()->holdingTheRole('exit_team')->named('Rakesh Menon')->create();
        EmploymentRecord::factory()->forPerson($rakesh)->designated($senior)->create();

        $case = engine()->open($exit, $rakesh);

        // The pay figure is nowhere near the threshold; the designation carries it.
        engine()->decide($case, 1, 'approved', User::factory()->holdingTheRole('exit_team')->create(), payload: ['annual_ctc' => 600000]);

        expect(whoseTurn($case->fresh()))->toBe(['Director approval']);
    });
});

it('leaves a running case alone when the client raises a threshold, and follows the new figure on the next one', function () {
    TenantContext::run($this->meridian, function () {
        declareDirectorThresholdForRunning();

        $exit = liveProcess(function (ProcessTemplate $exit) {
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Manager approval')->collecting('annual_ctc')->offering('approved')->create();
            ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(2, 2)->named('Director approval')
                ->happensWhen([[
                    'source' => 'payload', 'field' => 'annual_ctc',
                    'operator' => '>', 'setting' => 'hiring_director_threshold',
                ]])
                ->offering('approved')->create();
        });

        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $running = engine()->open($exit, rakesh(), $hr);

        engine()->decide($running, 1, 'approved', $hr, payload: ['annual_ctc' => 2000000]);

        expect(whoseTurn($running->fresh()))->toBe(['Director approval']);

        // Anjali raises the threshold above the figure. The open case would otherwise
        // close approved with nobody having approved it.
        app(Settings::class)->set('hiring_director_threshold', 5000000);

        expect(whoseTurn($running->fresh()))->toBe(['Director approval']);

        $next = engine()->open($exit, rakesh(), $hr);

        engine()->decide($next, 1, 'approved', $hr, payload: ['annual_ctc' => 2000000]);

        expect($next->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

it('freezes only the questions the process actually asks', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();

        $case = engine()->open($exit, rakesh());

        expect($case->subject_facts_snapshot)->toBe([]);
        expect($case->settings_snapshot)->toBe([]);
    });
});

it('refuses a question about the person that this system cannot answer about anybody', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        ProcessStep::factory()->heldByTheRoleAnywhere('exit_team')->of($exit)->at(1, 1)->named('Director approval')
            ->happensWhen([['source' => 'subject', 'field' => 'grade', 'operator' => '=', 'value' => 'M4']])
            ->create();

        // Named in the words a client reads, not in ours.
        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'It knows their department, their designation, their office, '
                .'whether they manage anybody, whether they hold any equipment.');
    });
});

it('takes the case row before it reads what is still outstanding', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $case = engine()->open($exit, rakesh(), $hr);

        $fresh = $case->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();
        engine()->decide($fresh, 1, 'approved', $hr);
        $queries = collect(DB::getRawQueryLog())->pluck('raw_query');
        DB::disableQueryLog();

        // IT and Finance approving the last two clearances in the same second is what
        // this stops: each would ask whether anything is outstanding while the other's
        // approval was written and not yet committed, both would see one step still open,
        // and the exit would sit finished-but-open for ever. The effect itself needs two
        // database connections to show, which a test running inside one transaction does
        // not have — so what is asserted is that the row is taken, and taken first.
        $tookTheRow = $queries->search(fn (string $query) => str_contains($query, 'for update'));
        $readTheSteps = $queries->search(fn (string $query) => str_contains($query, 'from "case_steps"'));

        expect($tookTheRow)->not->toBeFalse();
        expect($tookTheRow)->toBeLessThan($readTheSteps);
    });
});

/*
| Speed
*/

it('answers whose turn it is across five hundred open cases in a fixed number of queries and under 300 milliseconds', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExit();
        $hr = User::factory()->holdingTheRole('exit_team')->create();
        $rakesh = rakesh();
        $record = EmploymentRecord::query()->where('user_id', $rakesh->getKey())->first();

        ProcessCase::factory()->count(500)->on($exit)->about($rakesh, $record)->create();

        // A third of them past their first step, so the walk has real rows to subtract
        // rather than an empty table.
        ProcessCase::query()->take(170)->pluck('id')->each(function (int $caseId) use ($hr) {
            CaseStep::factory()->create([
                'case_id' => $caseId,
                'sequence' => 1,
                'assignee_id' => $hr->getKey(),
                'outcome' => 'approved',
                'acted_at' => now(),
            ]);
        });

        $open = ProcessCase::query()->whereNull('closed_at')->whereNull('cancelled_at')->get();

        expect($open)->toHaveCount(500);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $startedAt = hrtime(true);
        $available = (new AvailableSteps)->forAll($open);
        $milliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $queries = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        // 330 cases waiting on the manager, 170 waiting on two clearances each.
        expect($available)->toHaveCount(330 + (170 * 2));

        // Six, and six however many cases there are: the frozen versions, their steps,
        // the live rows, and the three that fetch the one calendar the clocks count
        // against — the job rows the cases are pinned to, their offices, and those
        // offices' holidays. Not one per case, which is the whole promise.
        expect($queries)->toBe(6);

        // The wall clock is the one that must not be traded away — a fixed query count
        // with the walking done in PHP over five hundred cases is exactly how Airflow's
        // own derived scheduler hit its limit.
        expect($milliseconds)->toBeLessThan(300);
    });
});

/**
 * The sort of switch module 05 will really declare: the salary above which a request
 * needs the director.
 */
function declareDirectorThresholdForRunning(): void
{
    Settings::declare(new SettingDeclaration(
        key: 'hiring_director_threshold',
        label: 'Salary above which a hire needs the director',
        type: 'integer',
        default: 1500000,
        rule: 'integer|min:0',
        help: 'Hires above this annual figure need the director.',
    ));
}
