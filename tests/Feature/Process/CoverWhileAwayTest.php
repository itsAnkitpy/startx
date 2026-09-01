<?php

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\Delegation;
use App\Models\EmploymentRecord;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;

/*
| Step 3 of module 03: cover while somebody is away.
|
| Rakesh is on leave for a fortnight and Priya holds his exits while he is. Nothing moves
| and nothing is repaired afterwards — the cover is read every time the product works out
| whose job a step is, so it starts and stops on its own dates.
|
| The four rules, one test each: cover cannot be passed on, it only reaches the processes
| it names, it stops when its dates run out, and anything done under it reads in both
| names.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

/** A live process of Meridian's whose one step belongs to whoever is named by work address. */
function aProcessHeldBy(string $key, string $label, User $person): ProcessTemplate
{
    $template = ProcessTemplate::factory()->named($key, $label)->about('employee')->create();

    ProcessStep::factory()->of($template)->at(1, 1)->named("{$label} approval")
        ->heldBy($person->work_email)->offering('approved')->create();

    $template->publish();

    return $template;
}

/** Somebody with a job at Meridian. */
function workingAt(string $called): User
{
    $person = User::factory()->named($called)->create();

    EmploymentRecord::factory()->forPerson($person)->create();

    return $person;
}

it('lets whoever is covering act on the step, and says so in both names', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = workingAt('Rakesh Menon');
        $priya = workingAt('Priya Nair');
        $leaver = workingAt('Chandni Verma');

        $exit = aProcessHeldBy('exit', 'Exit', $rakesh);

        Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')->create();

        $case = (new CaseEngine)->open($exit, $leaver, $rakesh);

        expect((new CaseEngine)->decide($case, 1, 'approved', $priya)->outcome)->toBe('approved');

        // "Priya approved this, covering for Rakesh" — not "Priya approved this", and not
        // "Rakesh approved this" either.
        $acted = CaseEvent::query()->where('case_id', $case->getKey())->where('type', 'step_acted')->sole();

        expect((int) $acted->actor_id)->toBe((int) $priya->getKey());
        expect($acted->payload['covering_for'])
            ->toEqual(['id' => (int) $rakesh->getKey(), 'name' => 'Rakesh Menon']);
    });
});

it('leaves the person who is away able to act on their own step', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = workingAt('Rakesh Menon');
        $priya = workingAt('Priya Nair');
        $leaver = workingAt('Chandni Verma');

        $exit = aProcessHeldBy('exit', 'Exit', $rakesh);

        Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')->create();

        $case = (new CaseEngine)->open($exit, $leaver, $rakesh);

        // Rakesh is away, not locked out. Naming a cover adds Priya rather than taking him
        // off, and his own approval reads as his own with no cover beside it.
        expect((new CaseEngine)->decide($case, 1, 'approved', $rakesh)->outcome)->toBe('approved');

        $acted = CaseEvent::query()->where('case_id', $case->getKey())->where('type', 'step_acted')->sole();

        expect($acted->payload)->not->toHaveKey('covering_for');
    });
});

it('does not hand over a process the cover never named', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = workingAt('Rakesh Menon');
        $priya = workingAt('Priya Nair');
        $joiner = workingAt('Anjali Rao');

        $hiring = aProcessHeldBy('hiring', 'Hiring', $rakesh);

        // "Priya covers my exits for a fortnight" must not also hand her the hiring
        // approvals, which is the whole reason a cover names the process it covers.
        Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')->create();

        $case = (new CaseEngine)->open($hiring, $joiner, $rakesh);

        expect(fn () => (new CaseEngine)->decide($case, 1, 'approved', $priya))
            ->toThrow(ProcessRefused::class, 'is not yours to act on');
    });
});

it('stops delivering the step once the cover’s dates have run out', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = workingAt('Rakesh Menon');
        $priya = workingAt('Priya Nair');
        $leaver = workingAt('Chandni Verma');

        $exit = aProcessHeldBy('exit', 'Exit', $rakesh);

        Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')
            ->between(now()->subWeeks(3)->toDateString(), now()->subWeek()->toDateString())
            ->create();

        $case = (new CaseEngine)->open($exit, $leaver, $rakesh);

        // The step is still open and the cover has expired underneath it. Priya can no
        // longer touch it, and Rakesh holds it exactly as if there had never been a cover.
        expect(fn () => (new CaseEngine)->decide($case, 1, 'approved', $priya))
            ->toThrow(ProcessRefused::class, 'is not yours to act on');

        expect((new CaseEngine)->decide($case, 1, 'approved', $rakesh)->outcome)->toBe('approved');
    });
});

it('does not carry a cover into another process asked about in the same breath', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = workingAt('Rakesh Menon');
        $chandni = workingAt('Chandni Verma');

        // Rakesh's exit, which Chandni holds while he is away. And a kit request that is
        // Chandni's own work, nothing to do with Rakesh.
        $exit = aProcessHeldBy('exit', 'Exit', $rakesh);
        $kit = aProcessHeldBy('kit_request', 'Kit request', $chandni);

        Delegation::factory()->covering($rakesh, $chandni)->create(['process_key' => 'exit']);

        // One resolver for both questions, which is how the menu asks them — it looks at
        // every live process in turn and remembers what it has already worked out.
        $resolver = new AssigneeResolver;

        $onTheExit = $resolver->whoCanRaiseIt($exit->steps()->first(), 'exit');
        $onTheKit = $resolver->whoCanRaiseIt($kit->steps()->first(), 'kit_request');

        $coveringOnTheExit = $onTheExit->first(fn (User $person) => $person->is($chandni));
        $onHerOwnRequest = $onTheKit->first(fn (User $person) => $person->is($chandni));

        // On the exit she is there because she is covering Rakesh, and the queue says so.
        expect($coveringOnTheExit)->not->toBeNull()
            ->and($coveringOnTheExit->coveringFor->is($rakesh))->toBeTrue();

        // On her own kit request she is there in her own right, and must not be shown as
        // covering for Rakesh — that would put his name on an approval she gave herself.
        expect($onHerOwnRequest)->not->toBeNull()
            ->and($onHerOwnRequest->relationLoaded('coveringFor'))->toBeFalse();
    });
});

it('refuses passing cover on to a third person', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = workingAt('Rakesh Menon');
        $priya = workingAt('Priya Nair');
        $chandni = workingAt('Chandni Verma');

        Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')
            ->between('2026-09-01', '2026-09-14')->create();

        // Priya is holding Rakesh's exits. She cannot hand them on: the person accountable
        // for an approval has to be findable in one hop, not walked to.
        expect(fn () => Delegation::factory()->covering($priya, $chandni)->forTheProcess('exit')
            ->between('2026-09-08', '2026-09-20')->create())
            ->toThrow(ProcessRefused::class, 'cover cannot be passed on to a third person');

        // Her own fortnight afterwards is her own business, and is allowed.
        expect(Delegation::factory()->covering($priya, $chandni)->forTheProcess('exit')
            ->between('2026-09-15', '2026-09-30')->create()->exists)->toBeTrue();
    });
});

it('does not carry a cover for somebody who has left the company', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = workingAt('Rakesh Menon');
        $priya = workingAt('Priya Nair');
        $leaver = workingAt('Chandni Verma');

        $exit = aProcessHeldBy('exit', 'Exit', $rakesh);

        Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')->create();

        $case = (new CaseEngine)->open($exit, $leaver, $rakesh);

        $rakesh->update(['active' => false]);

        // Rakesh's account is off, so Rakesh no longer holds the step — and an authority
        // that came from his queue cannot outlive it. The step climbs instead.
        expect(fn () => (new CaseEngine)->decide($case, 1, 'approved', $priya))
            ->toThrow(ProcessRefused::class, 'is not yours to act on');
    });
});

it('never lets a cover clear the exit of the person it is covering for', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = workingAt('Rakesh Menon');
        $priya = workingAt('Priya Nair');

        $exit = aProcessHeldBy('exit', 'Exit', $rakesh);

        Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')->create();

        // Rakesh's own exit, on a step that belongs to Rakesh. He cannot clear it, so
        // there is no queue of his for Priya to be holding — a cover is not a way round
        // the one signature this product cannot record.
        $case = (new CaseEngine)->open($exit, $rakesh, $priya);

        expect(fn () => (new CaseEngine)->decide($case, 1, 'approved', $priya))
            ->toThrow(ProcessRefused::class, 'is not yours to act on');
    });
});

it('refuses the chain however round it is written', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = workingAt('Rakesh Menon');
        $priya = workingAt('Priya Nair');
        $chandni = workingAt('Chandni Verma');

        // Chandni is holding Priya's exits first. Rakesh naming Priya over the same dates
        // is the same chain as before, written from the other end: his exits would sit
        // with two people who are both away, and the product would report them covered.
        Delegation::factory()->covering($priya, $chandni)->forTheProcess('exit')
            ->between('2026-09-01', '2026-09-14')->create();

        expect(fn () => Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')
            ->between('2026-09-08', '2026-09-20')->create())
            ->toThrow(ProcessRefused::class, 'cover cannot be passed on to a third person');

        // Once Priya is back, she can hold Rakesh's exits as anybody else would.
        expect(Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')
            ->between('2026-09-15', '2026-09-30')->create()->exists)->toBeTrue();
    });
});

it('names one definite person when somebody is covering two of them at once', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = User::factory()->holdingTheRole('exit_team')->named('Rakesh Menon')->create();
        $deepak = User::factory()->holdingTheRole('exit_team')->named('Deepak Iyer')->create();
        $priya = workingAt('Priya Nair');
        $leaver = workingAt('Anjali Rao');

        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();
        ProcessStep::factory()->of($exit)->at(1, 1)->named('Exit approval')
            ->heldByTheRoleAnywhere('exit_team')->offering('approved')->create();
        $exit->publish();

        // Both of the exit team are away and Priya is holding both queues. The step was
        // never one person's, so there is no single person she stood in for.
        Delegation::factory()->covering($rakesh, $priya)->forTheProcess('exit')->create();
        Delegation::factory()->covering($deepak, $priya)->forTheProcess('exit')->create();

        $case = (new CaseEngine)->open($exit, $leaver, $rakesh);
        $acted = (new CaseEngine)->decide($case, 1, 'approved', $priya);

        // One name, and always the same one, so two identical steps never read as two
        // different arrangements a year later.
        $event = CaseEvent::query()->where('case_id', $case->getKey())->where('type', 'step_acted')->sole();

        expect($event->payload['covering_for'])
            ->toEqual(['id' => (int) $rakesh->getKey(), 'name' => 'Rakesh Menon']);

        // And the row still shows the whole picture: both of the people who held the step
        // and Priya beside them, so the one name above reads as a shorthand rather than as
        // the only person involved.
        expect(collect($acted->candidates_at_claim)->pluck('name')->all())
            ->toEqual(['Rakesh Menon', 'Deepak Iyer', 'Priya Nair']);
    });
});
