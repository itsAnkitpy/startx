<?php

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\FormDefinition;
use App\Models\FormField;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Process\FlatFile;
use App\Process\PublishCheck;
use App\Process\StepLink;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/*
| What happens when a step blows its deadline, and how somebody with no login answers one.
|
| Run against the demo company as it is seeded, like the queue screen's own tests, so what
| is checked here is the thing Ankit can open rather than a fixture built to agree with it.
| Anjali's exit is five days old against a two-day clearance, and Rakesh's is cleared by
| everybody who works there and waiting on Rakesh himself through a link.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function whoWorksAtMeridian(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** An exit by the first name of whoever it is about. */
function theExitOf(string $first): ProcessCase
{
    return ProcessCase::query()->whereRelation('subject', 'first_name', $first)->sole();
}

/**
 * The names of the steps waiting on somebody. A hiring request is about a vacancy rather
 * than a person, so it is listed by the process it runs on.
 */
function stepsWaitingOn(User $person): array
{
    return (new AvailableSteps)->waitingOn($person)
        ->map(fn ($waiting) => $waiting->step->name.' — '
            .($waiting->case->subject?->name ?? $waiting->case->template->name))
        ->sort()->values()->all();
}

/** A fresh link for a step, reduced to the token in its address. */
function tokenForStep(ProcessCase $case, int $sequence): string
{
    return basename((new StepLink)->issue($case, $sequence));
}

/**
 * Every message the application actually sent.
 *
 * Read off the mailer the test environment already uses rather than through `Mail::fake()`,
 * which only collects messages built as their own class. A link is one line of text sent
 * straight out, because letters and their delivery log belong to module 06.
 *
 * @return list<string>
 */
function addressesWrittenTo(): array
{
    return Mail::mailer()->getSymfonyTransport()->messages()
        ->map(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress())
        ->all();
}

/** That client company's own address, with a path on it. */
function atMeridiansAddress(string $path): string
{
    return 'http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/'.ltrim($path, '/');
}

it('adds the escalation target to an overdue step and takes it from nobody', function () {
    TenantContext::run($this->meridian, function () {
        // Anjali's clearance is five days past a two-day target, so it widens to the HR
        // director over the whole company.
        expect(stepsWaitingOn(whoWorksAtMeridian('chandni')))
            ->toContain('HR clearance — Anjali Rao');

        // And the two people it belonged to still have it. Nobody escapes an overdue step
        // by escalating it, which is the whole rule.
        expect(stepsWaitingOn(whoWorksAtMeridian('rakesh')))
            ->toContain('HR clearance — Anjali Rao')
            ->and(stepsWaitingOn(whoWorksAtMeridian('priya')))
            ->toContain('HR clearance — Anjali Rao');

        // Widening the list is not a decoration: the engine lets her act on it, asked the
        // same question again at the moment of acting.
        $decided = (new CaseEngine)->decide(
            theExitOf('Anjali'), 1, 'approved', whoWorksAtMeridian('chandni'),
            ['id_card_returned' => true]
        );

        expect($decided->outcome)->toBe('approved');
    });
});

it('leaves a step alone until its deadline has actually passed', function () {
    TenantContext::run($this->meridian, function () {
        // Deepak's exit was opened just now, so its clearance is nobody's but the two who
        // hold HR head in Shimla. A widening that happened on every step would prove
        // nothing at all about the widening above.
        expect(stepsWaitingOn(whoWorksAtMeridian('chandni')))
            ->not->toContain('HR clearance — Deepak Iyer');

        expect(fn () => (new CaseEngine)->decide(
            theExitOf('Deepak'), 1, 'approved', whoWorksAtMeridian('chandni')
        ))->toThrow(ProcessRefused::class, 'is not yours to act on');
    });
});

it('does not fall to the company stand-in when the escalation finds nobody', function () {
    TenantContext::run($this->meridian, function () {
        // Nobody is HR director any more, so Anjali's overdue clearance has nowhere to
        // widen to. Chandni is the company's stand-in, and an overdue step must not reach
        // her that way — the stand-in is for a step nobody holds, and two people hold this.
        RoleAssignment::query()->whereRelation('role', 'key', 'hr_director')->delete();

        expect(stepsWaitingOn(whoWorksAtMeridian('chandni')))
            ->not->toContain('HR clearance — Anjali Rao')
            ->and(stepsWaitingOn(whoWorksAtMeridian('rakesh')))
            ->toContain('HR clearance — Anjali Rao');
    });
});

it('refuses to publish a step that goes nowhere real when it runs late', function () {
    TenantContext::run($this->meridian, function () {
        $draft = ProcessTemplate::factory()->named('nonsense', 'Nonsense')->about('employee')->create();

        ProcessStep::factory()->of($draft)->at(1, 1)->named('Clearance')
            ->heldByTheRole('hr_head')->dueIn(24)
            ->escalatingTo(['kind' => 'whoever_is_around'])->create();

        // And a second one whose escalation could never fire, because the step has no time
        // limit for it to be late against.
        ProcessStep::factory()->of($draft)->at(2, 2)->named('Sign-off')
            ->heldByTheRole('hr_head')
            ->escalatingTo(['kind' => 'role_global', 'role' => 'hr_director'])->create();

        $problems = (new PublishCheck($draft->fresh()))->problems();

        expect($problems)->toHaveCount(2)
            ->and($problems[0])->toContain('which is not one of the ways a step can find its people')
            ->and($problems[1])->toContain('has no time limit of its own');
    });
});

it('refuses to publish a step that both waits on a link and escalates to an employee', function () {
    TenantContext::run($this->meridian, function () {
        $draft = ProcessTemplate::factory()->named('nonsense', 'Nonsense')->about('employee')->create();

        ProcessStep::factory()->of($draft)->at(1, 1)->named('Candidate confirms')
            ->external()->dueIn(24)
            ->escalatingTo(['kind' => 'role_global', 'role' => 'hr_director'])->create();

        expect((new PublishCheck($draft->fresh()))->problems()[0])
            ->toContain('Only the person the link was sent to can answer it');
    });
});

it('registers no scheduled pass of its own', function () {
    // The one scheduled pass in the whole system belongs to module 06, and two passes
    // walking the same overdue work means either two chase-ups or each assuming the other
    // is doing it. Read from the source rather than from a list that only fills in once
    // something has registered.
    $written = [];

    foreach (['app', 'routes', 'bootstrap', 'database'] as $where) {
        exec('grep -rlE "Schedule::|->schedule\(|withSchedule" '.base_path($where).' 2>/dev/null', $written, $ignored);
    }

    expect($written)->toBeEmpty();

    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('No scheduled tasks have been defined');
});

it('sends a link to the leaver personal address and lets them answer once', function () {
    $sent = TenantContext::run($this->meridian, function () {
        $rakeshs = theExitOf('Rakesh');

        // Everybody who works there has already cleared it, and the last step is his own —
        // by which point he has no sign-in left.
        expect(stepsWaitingOn(whoWorksAtMeridian('chandni')))
            ->not->toContain('Leaver confirms the handover — Rakesh Menon');

        return [tokenForStep($rakeshs, 4), $rakeshs->getKey()];
    });

    // Two, because seeding the demo company already sent him one — the point is that both
    // went to his own address and neither needed anybody to type it.
    expect(addressesWrittenTo())->toBe(['rakesh@personal.example', 'rakesh@personal.example']);

    $this->get(atMeridiansAddress('step/'.$sent[0]))
        ->assertOk()
        ->assertSee('Leaver confirms the handover');

    $this->post(atMeridiansAddress('step/'.$sent[0]), ['outcome' => 'approved', 'note' => 'All handed over.'])
        ->assertOk()
        ->assertSee('your answer is recorded');

    TenantContext::run($this->meridian, function () use ($sent) {
        $answered = CaseStep::query()->where('case_id', $sent[1])->where('sequence', 4)
            ->whereNull('superseded_at')->sole();

        // Recorded against the address and never against an employee, which is what the
        // history has to be able to say a year later.
        expect($answered->outcome)->toBe('approved')
            ->and($answered->assignee_id)->toBeNull()
            ->and($answered->external_assignee['email'])->toBe('rakesh@personal.example')
            ->and($answered->payload['note'])->toBe('All handed over.');

        // The last thing recorded against the case, which is his answer — the three
        // clearances in front of it were recorded against the employees who gave them.
        $line = CaseEvent::query()->where('case_id', $sent[1])->where('type', 'step_acted')
            ->latest('id')->first();

        expect($line->actor_id)->toBeNull()
            ->and($line->payload['answered_by']['email'])->toBe('rakesh@personal.example');

        // Nothing was left outstanding, so the exit closed on his answer.
        expect(ProcessCase::query()->whereKey($sent[1])->sole()->closed_at)->not->toBeNull();
    });

    // The second try, whoever is holding the address by then.
    $this->post(atMeridiansAddress('step/'.$sent[0]), ['outcome' => 'approved'])
        ->assertForbidden()
        ->assertSee('This link no longer opens');
});

it('stops working once it has been opened its permitted number of times', function () {
    $token = TenantContext::run($this->meridian, fn () => tokenForStep(theExitOf('Rakesh'), 4));

    for ($opened = 0; $opened < StepLink::Opens; $opened++) {
        $this->get(atMeridiansAddress('step/'.$token))->assertOk();
    }

    $this->get(atMeridiansAddress('step/'.$token))
        ->assertForbidden()
        ->assertSee('This link no longer opens');

    // Out of opens and still inside its time limit, so it is the count that stopped it.
    $this->post(atMeridiansAddress('step/'.$token), ['outcome' => 'approved'])
        ->assertForbidden();
});

it('stops working once its time is up', function () {
    $token = TenantContext::run($this->meridian, function () {
        $token = tokenForStep(theExitOf('Rakesh'), 4);

        $link = CaseStep::query()->where('sequence', 4)->whereNull('superseded_at')->sole();
        $held = $link->external_assignee;
        $held['expires_at'] = now()->subMinute()->toIso8601String();
        $link->forceFill(['external_assignee' => $held])->save();

        return $token;
    });

    // Never opened, so it is the clock that stopped it and not the count.
    $this->get(atMeridiansAddress('step/'.$token))
        ->assertForbidden()
        ->assertSee('This link no longer opens')
        ->assertSee('Send me a new link');
});

it('issues a fresh link to the same address without an employee touching the case', function () {
    $dead = TenantContext::run($this->meridian, function () {
        $token = tokenForStep(theExitOf('Rakesh'), 4);

        $link = CaseStep::query()->where('sequence', 4)->whereNull('superseded_at')->sole();
        $held = $link->external_assignee;
        $held['expires_at'] = now()->subMinute()->toIso8601String();
        $link->forceFill(['external_assignee' => $held])->save();

        return $token;
    });

    $this->post(atMeridiansAddress('step/'.$dead.'/again'))
        ->assertOk()
        ->assertSee('A new link is on its way');

    // Every message so far to the address already on the record — the seeded one, the one
    // this test sent, and the one asked for from a dead link. Nobody typed an address and
    // no employee opened the case to change anything.
    expect(addressesWrittenTo())->toBe(array_fill(0, 3, 'rakesh@personal.example'));

    TenantContext::run($this->meridian, function () {
        $live = CaseStep::query()->where('sequence', 4)->whereNull('superseded_at')->sole();

        expect($live->external_assignee['email'])->toBe('rakesh@personal.example')
            ->and(CaseStep::query()->where('sequence', 4)->whereNotNull('superseded_at')->count())->toBe(2);
    });

    // The dead one stays dead, so asking again never leaves two working links for one
    // answer.
    $this->get(atMeridiansAddress('step/'.$dead))->assertForbidden();
});

it('lets only the newest link ask for another one', function () {
    // Two have gone out since the seeded one, so the middle token is a copy that has been
    // replaced — the shape an old message forwarded on, or left in an archive, arrives in.
    $replaced = TenantContext::run($this->meridian, function () {
        $rakeshs = theExitOf('Rakesh');
        $replaced = tokenForStep($rakeshs, 4);
        tokenForStep($rakeshs, 4);

        return $replaced;
    });

    // It opens nothing, and it cannot post another one to Rakesh's inbox either. Left
    // open, whoever held it could replace his working link as fast as they could press
    // the button, and the one person the exit is waiting on could never answer it.
    $this->get(atMeridiansAddress('step/'.$replaced))->assertForbidden();

    $this->post(atMeridiansAddress('step/'.$replaced.'/again'))
        ->assertForbidden()
        ->assertSee('A newer link for this step has already been sent');

    // Three: the seeded one and the two this test asked for. Nothing further was sent.
    expect(addressesWrittenTo())->toHaveCount(3);

    TenantContext::run($this->meridian, function () {
        // And his live link is still live, which is the whole point of refusing.
        $live = CaseStep::query()->where('sequence', 4)->whereNull('superseded_at')->sole();

        expect(fn () => (new StepLink)->refuseUnlessItStillWorks($live))->not->toThrow(ProcessRefused::class);
    });
});

it('never lets an employee answer a step that waits on a link', function () {
    TenantContext::run($this->meridian, function () {
        $rakeshs = theExitOf('Rakesh');
        $step = $rakeshs->template->steps->firstWhere('sequence', 4);

        // No way of finding people returns anybody for it, so it appears in no queue —
        // including when it is overdue, because there is nothing for it to widen to.
        expect((new AssigneeResolver)->resolve($rakeshs, $step))->toBeEmpty()
            ->and((new AssigneeResolver)->resolve($rakeshs, $step, true))->toBeEmpty();

        // And the door is shut in words rather than by the step being absent from a screen.
        expect(fn () => (new CaseEngine)->decide($rakeshs, 4, 'approved', whoWorksAtMeridian('priya')))
            ->toThrow(ProcessRefused::class, 'Nobody signed in can answer it for them.');
    });
});

it('carries where a step goes when it runs late through the client spreadsheet', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::query()->where('key', 'exit')->latest('version')->first();

        $file = tempnam(sys_get_temp_dir(), 'exit').'.csv';
        file_put_contents($file, (new FlatFile)->write($exit));

        $typed = (new FlatFile)->read($file);
        unlink($file);

        // A client types it in the same shorthand they type who a step belongs to, and it
        // comes back as the same rule — otherwise exporting a process and importing it
        // again would quietly drop every chase-up the client had set up.
        expect($typed[0]['escalate_to'])->toBe(['kind' => 'role_global', 'role' => 'hr_director'])
            ->and($typed[3]['escalate_to'])->toBeNull();
    });
});

it('opens nothing on the platform own address', function () {
    $token = TenantContext::run($this->meridian, fn () => tokenForStep(theExitOf('Rakesh'), 4));

    // The same address without the company on the front of it. Somebody whose mail client
    // mangled the link is owed the refusal page, not a server error.
    $this->get('http://'.config('tenancy.central_domain').'/step/'.$token)
        ->assertForbidden()
        ->assertSee('This link no longer opens');
});

it('refuses to send a link where there is no address to send it to', function () {
    TenantContext::run($this->meridian, function () {
        whoWorksAtMeridian('rakesh')->forceFill(['personal_email' => null])->save();

        expect(fn () => (new StepLink)->issue(theExitOf('Rakesh'), 4))
            ->toThrow(ProcessRefused::class, 'no personal email address recorded');
    });
});

it('holds an answer sent through a link to the form the client wrote', function () {
    TenantContext::run($this->meridian, function () {
        $rakeshs = theExitOf('Rakesh');
        $token = tokenForStep($rakeshs, 4);

        // Meridian allows two thousand characters on the one question the leaver is asked.
        // Nothing on this path had ever been checked against it: the queue screen was the
        // only thing in the product reading a form's rules, and there is no queue screen
        // behind a link, so this door recorded whatever it was handed.
        //
        // The page in front of it carries its own looser copy of the same limit, which is
        // what module 10 replaces when it draws the step's real form there. This is the
        // writer underneath, which is the half every other door also goes through.
        expect(fn () => (new CaseEngine)->decideThroughALink($token, 'approved', [
            'note' => str_repeat('a', 2001),
        ]))->toThrow(ProcessRefused::class, 'Anything you want on the record');

        // Rakesh's exit is still waiting on him and has not closed on an answer that was
        // refused.
        expect(CaseStep::query()->where('case_id', $rakeshs->getKey())->where('sequence', 4)
            ->whereNull('superseded_at')->sole()->outcome)->toBeNull()
            ->and($rakeshs->fresh()->closed_at)->toBeNull();

        // The same link, an answer that fits, and it goes through — so this is the client's
        // own limit and not a door that has stopped opening.
        (new CaseEngine)->decideThroughALink($token, 'approved', ['note' => 'All handed over.']);

        expect($rakeshs->fresh()->closed_at)->not->toBeNull();
    });
});

it('draws a refusal over the form rather than telling a live link it is dead', function () {
    $token = TenantContext::run($this->meridian, function () {
        // A second process of Meridian's whose one step is answered through a link and
        // whose form insists on an answer. Their exit's handover question is optional, so
        // it cannot show this — and a client whose external step asks something required
        // is ordinary, not exotic.
        $form = FormDefinition::factory()->named('return_confirmation', 'Return confirmation')->create();

        FormField::factory()->on($form)->at(1)->required()
            ->asking('everything_returned', 'Everything returned', FormField::Boolean)->create();

        $form->publish();

        $template = ProcessTemplate::factory()->named('asset_return', 'Asset return')->about('employee')->create();

        ProcessStep::factory()->of($template)->at(1, 1)->named('Leaver confirms what came back')
            ->asking($form)->external()->offering('approved', 'rejected')->create();

        $template->publish();

        $started = (new CaseEngine)->open(
            $template->fresh(), whoWorksAtMeridian('rakesh'), whoWorksAtMeridian('chandni')
        );

        return tokenForStep($started, 1);
    });

    // The page in front of this draws one fixed box for a note and cannot ask the question
    // at all, which is module 10's screen to fix. What matters here is where the refusal
    // lands: not on the page that says the link is finished, whose only way forward is a
    // new link — that replaces the answer being given, hands over the same box again and
    // spends one of the link's opens each time round.
    $this->post(atMeridiansAddress('step/'.$token), ['outcome' => 'approved'])
        ->assertStatus(422)
        ->assertSee('Leaver confirms what came back')
        ->assertSee('Everything returned')
        ->assertDontSee('This link no longer opens');
});
