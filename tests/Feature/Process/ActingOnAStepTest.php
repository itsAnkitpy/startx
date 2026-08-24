<?php

use App\Exceptions\ProcessRefused;
use App\Models\EmploymentRecord;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\CaseEngine;
use App\Settings\Settings;
use App\Tenancy\TenantContext;

/*
| Step 2 of module 03: the gate.
|
| Until now anybody with a login at a client company could decide any step of any case,
| because nothing asked whether the step was theirs. Every action now goes through the
| same answer module 03 gives a queue — worked out again at the moment somebody acts,
| never trusted from the page they were looking at.
|
| Meridian's exit through the whole file: one clearance, belonging to whoever holds the
| IT role, with Rakesh leaving.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

/** Meridian's exit, whose one clearance belongs to whoever holds the named role. */
function anExitClearedBy(string $roleKey): ProcessTemplate
{
    $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

    ProcessStep::factory()->of($exit)->at(1, 1)->named('IT clearance')
        ->heldByTheRoleAnywhere($roleKey)->clearance()->create();

    $exit->publish();

    return $exit;
}

/** Somebody with a job at Meridian, holding the role named or holding nothing. */
function atMeridian(string $called, ?string $roleKey = null): User
{
    $person = $roleKey === null
        ? User::factory()->named($called)->create()
        : User::factory()->named($called)->holdingTheRole($roleKey)->create();

    EmploymentRecord::factory()->forPerson($person)->create();

    return $person;
}

/*
| Who may act
*/

it('lets the person the step was worked out for act on it', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        expect((new CaseEngine)->decide($case, 1, 'approved', $deepak)->outcome)->toBe('approved');
    });
});

it('refuses somebody the step was never worked out for', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');
        $priya = atMeridian('Priya Sharma');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        // Priya has a login at Meridian and nothing else. Before this gate existed she
        // could have cleared Rakesh's exit, and the record would have said IT did.
        expect(fn () => (new CaseEngine)->decide($case, 1, 'approved', $priya))
            ->toThrow(ProcessRefused::class, '[IT clearance] is not yours to act on');

        expect(ProcessCase::find($case->getKey())->steps()->count())->toBe(0);
    });
});

it('refuses picking a step up as well as acting on it', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');
        $priya = atMeridian('Priya Sharma');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        // Otherwise the queue is closed and the door beside it is open: claiming a step
        // puts your name on it, which is what a case read a year later shows.
        expect(fn () => (new CaseEngine)->claim($case, 1, $priya))
            ->toThrow(ProcessRefused::class, '[IT clearance] is not yours to act on');
    });
});

it('lets any of a shared team act, and does not narrow the step to one of them', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');
        $anjali = atMeridian('Anjali Nair', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        // Anjali did not open the case and nobody assigned it to her. The whole IT team
        // holds it, and she is on the team.
        expect((new CaseEngine)->decide($case, 1, 'approved', $anjali)->outcome)->toBe('approved');
    });
});

it('never lets the person a case is about clear their own step, role or no role', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon', 'it_team');
        $deepak = atMeridian('Deepak Rao', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        // Rakesh is on the IT team and Rakesh is the one leaving. This is the signature
        // the product cannot afford to record.
        expect(fn () => (new CaseEngine)->decide($case, 1, 'approved', $rakesh))
            ->toThrow(ProcessRefused::class, '[IT clearance] is not yours to act on');
    });
});

/*
| Asked again at the moment of acting
*/

it('refuses somebody whose role was taken away after the page listed the step', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');
        $anjali = atMeridian('Anjali Nair', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        RoleAssignment::query()->where('user_id', $deepak->getKey())->delete();

        // The step was genuinely his when his queue loaded. It is not his now, and the
        // question is asked again rather than taken from the page he is looking at.
        expect(fn () => (new CaseEngine)->decide($case, 1, 'approved', $deepak))
            ->toThrow(ProcessRefused::class, '[IT clearance] is not yours to act on');

        expect((new CaseEngine)->decide($case, 1, 'approved', $anjali)->outcome)->toBe('approved');
    });
});

it('refuses somebody whose account was switched off while they held the step', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        (new CaseEngine)->claim($case, 1, $deepak);

        $deepak->update(['active' => false]);

        expect(fn () => (new CaseEngine)->decide($case->fresh(), 1, 'approved', $deepak))
            ->toThrow(ProcessRefused::class, '[IT clearance] is not yours to act on');
    });
});

/*
| Nobody holds it
*/

it('refuses everybody on a step nobody holds, rather than whoever is signed in', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $priya = atMeridian('Priya Sharma');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $priya);

        // Nobody holds the IT role and the client has named no stand-in. The clearance
        // stays open and waiting; it is not answerable by whoever happens to be logged in.
        expect(fn () => (new CaseEngine)->decide($case, 1, 'approved', $priya))
            ->toThrow(ProcessRefused::class, '[IT clearance] is not yours to act on');

        expect(ProcessCase::find($case->getKey())->state)->toBe(ProcessCase::Open);
    });
});

it('lets the client’s stand-in act on a step nobody else holds', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $rohit = atMeridian('Rohit Bansal');

        app(Settings::class)->set(AssigneeResolver::StandInSetting, (int) $rohit->getKey());

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $rohit);

        expect((new CaseEngine)->decide($case, 1, 'approved', $rohit)->outcome)->toBe('approved');
    });
});

/*
| Somebody with no account
*/

it('refuses every account holder on a step answered by somebody with no account', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $anjali = atMeridian('Anjali Nair', 'it_team');

        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Handover note')
            ->external()->offering('approved')->create();

        $exit->publish();

        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        // Not even the client's own HR team. Permission to answer it is the signed link
        // sent to the person's own address, so an employee acting here would be filing a
        // candidate's answers under an employee's name.
        expect(fn () => (new CaseEngine)->decide($case, 1, 'approved', $anjali))
            ->toThrow(ProcessRefused::class, 'Nobody signed in can answer it for them.');
    });
});

/*
| Ending a hold
*/

it('lets only the department that is holding turn its hold into a disputed line', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');
        $anjali = atMeridian('Anjali Nair', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        (new CaseEngine)->claim($case, 1, $deepak);
        (new CaseEngine)->decide($case->fresh(), 1, 'held', $deepak, reason: 'Laptop still out.');

        // Anjali is on the same team and could have cleared this step before Deepak took
        // it. Recording his argument as settled is his decision, not hers — the route for
        // Deepak being away is HR overriding it, which names them both.
        expect(fn () => (new CaseEngine)->resolveHold($case->fresh(), 1, 'closed_disputed', $anjali, 'Writing it off.'))
            ->toThrow(ProcessRefused::class, 'has already been picked up by somebody else');

        $resolved = (new CaseEngine)->resolveHold($case->fresh(), 1, 'closed_disputed', $deepak, 'Raised as a recovery line.');

        expect($resolved->outcome)->toBe('closed_disputed');
    });
});

/*
| The person the case is about, on the acts that sit outside a step
*/

it('will not let the person a case is about cancel it', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        // Rakesh cannot clear his own exit and cannot make it go away either.
        expect(fn () => (new CaseEngine)->cancel($case, $rakesh, 'Changed my mind.'))
            ->toThrow(ProcessRefused::class, 'when the case is about you');

        expect(ProcessCase::find($case->getKey())->state)->toBe(ProcessCase::Open);
    });
});

it('will not let the person a case is about move the date its deadlines count from', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        // Pushing his own last working day out moves the settlement deadline with it, and
        // past a fifth anniversary it hands him a gratuity he was not owed.
        expect(fn () => (new CaseEngine)->amendTheDateTheClocksCountFrom(
            $case, '2027-01-31', $rakesh, 'Serving a longer notice.'
        ))->toThrow(ProcessRefused::class, 'when the case is about you');
    });
});

it('will not let the person a case is about override a hold on their own step', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        (new CaseEngine)->claim($case, 1, $deepak);
        (new CaseEngine)->decide($case->fresh(), 1, 'held', $deepak, reason: 'Laptop still out.');

        expect(fn () => (new CaseEngine)->resolveHold($case->fresh(), 1, 'force_closed', $rakesh, 'Waiving it.'))
            ->toThrow(ProcessRefused::class, 'when the case is about you');
    });
});

/*
| Who was being asked, written down
*/

it('records everybody who could have decided a step, not only who did', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');
        $anjali = atMeridian('Anjali Nair', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        $decided = (new CaseEngine)->decide($case, 1, 'approved', $deepak);

        // Deepak signed it. The record also says Anjali could have, which is the question
        // nobody can answer afterwards once the IT team is different people.
        expect($decided->candidates_at_claim)->toEqual([
            ['id' => (int) $deepak->getKey(), 'name' => 'Deepak Rao'],
            ['id' => (int) $anjali->getKey(), 'name' => 'Anjali Nair'],
        ]);
    });
});

it('leaves that record alone when the team changes underneath it', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridian('Rakesh Menon');
        $deepak = atMeridian('Deepak Rao', 'it_team');
        $anjali = atMeridian('Anjali Nair', 'it_team');

        $case = (new CaseEngine)->open(anExitClearedBy('it_team'), $rakesh, $deepak);

        (new CaseEngine)->claim($case, 1, $deepak);

        // Anjali moves off IT and a new person joins it before Deepak gets round to
        // signing. Neither changes who was being asked when the clearance was picked up.
        RoleAssignment::query()->where('user_id', $anjali->getKey())->delete();
        atMeridian('Chandni Verma', 'it_team');

        $decided = (new CaseEngine)->decide($case->fresh(), 1, 'approved', $deepak);

        expect($decided->candidates_at_claim)->toEqual([
            ['id' => (int) $deepak->getKey(), 'name' => 'Deepak Rao'],
            ['id' => (int) $anjali->getKey(), 'name' => 'Anjali Nair'],
        ]);
    });
});
