<?php

use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TemplateCatalogue;
use App\Notifications\Wording;
use App\Process\AssigneeResolver;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Process\StepLink;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
| The first messages this product actually sends, and the record of having sent them.
|
| Run against the demo company as it is seeded, so what is checked is the thing Ankit can
| watch happen: Rakesh's exit is cleared by everybody who works there and waiting on Rakesh
| himself, one link answer away from finishing.
|
| Two claims are worth naming before the tests. The record holds the words as they actually
| went out, so a client rewriting their wording afterwards cannot rewrite what a case says
| it told somebody. And the screen never says a message was delivered — only that our mail
| server took it, which is the only thing an email without a provider's webhook can prove.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    // The demo opens four exits and eight hiring requests, every one of which now writes
    // to somebody. Cleared here so each test counts only its own messages.
    Mail::mailer()->getSymfonyTransport()->flush();
    TenantContext::run($this->meridian, fn () => NotificationDelivery::query()->delete());
});

/** Somebody from the demo company, by first name. */
function whoIsOnMeridiansStaff(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** The client's own live exit process. */
function theExitMeridianRuns(): ProcessTemplate
{
    return ProcessTemplate::query()->where('key', 'exit')->where('status', ProcessTemplate::Published)->sole();
}

/** Rakesh's exit, which is waiting on his own answer through a link. */
function rakeshsSeededExit(): ProcessCase
{
    return ProcessCase::query()
        ->whereRelation('subject', 'work_email', 'rakesh@meridian.test')
        ->whereRelation('template', 'key', 'exit')
        ->sole();
}

/**
 * Every message that actually left, as the address it went to and its subject line.
 *
 * Read off the mailer the test environment already uses rather than through `Mail::fake()`,
 * which only collects messages built as their own class.
 *
 * @return list<string>
 */
function whatActuallyLeft(): array
{
    return Mail::mailer()->getSymfonyTransport()->messages()
        ->map(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress()
            .' — '.$sent->getOriginalMessage()->getSubject())
        ->values()->all();
}

/** The body of the one message that left, or of the nth. */
function whatOneOfThemSaid(int $which = 0): string
{
    return (string) Mail::mailer()->getSymfonyTransport()->messages()[$which]
        ->getOriginalMessage()->getTextBody();
}

/*
| Putting the values in — a wall rather than a template engine
*/

it('puts a value in place of a named token, however the client spaced it', function () {
    expect(Wording::filledIn('Dear {{ person.name }}, {{company.name}} wrote.', [
        'person.name' => 'Rakesh Kumar',
        'company.name' => 'Meridian Logistics',
    ]))->toBe('Dear Rakesh Kumar, Meridian Logistics wrote.');
});

it('renders a token it has no value for as nothing at all', function () {
    // What makes a message safe to send when a fact about the case is genuinely absent:
    // the sentence closes up rather than reading a token name at somebody.
    expect(Wording::filledIn('Still waiting on you{{ step.on_behalf_of }}.', []))
        ->toBe('Still waiting on you.');
});

it('leaves anything that is not a token name exactly as the client typed it', function () {
    // The point of the whole class. Grav shipped a remote-code-execution advisory for
    // rendering email wording through a real template engine, and PortSwigger escapes the
    // sandboxes built for the job — so none of this is interpreted, and a plain-text email
    // renders none of it either. A client sees their own mistake, which costs nothing.
    $typed = '{{ 1 + 1 }} {{ $person->email }} {!! x !!} <?php echo 1; ?> {{ config("app.key") }}';

    expect(Wording::filledIn($typed, ['person.name' => 'Rakesh']))->toBe($typed);
});

it('never reads a value it has just put in', function () {
    // Somebody whose name is a token does not become the company. True by construction —
    // the replacement is never scanned again — and checked because it is the shape every
    // substitution bug in this class would take.
    expect(Wording::filledIn('Dear {{ person.name }}.', [
        'person.name' => '{{ company.name }}',
        'company.name' => 'Meridian Logistics',
    ]))->toBe('Dear {{ company.name }}.');
});

it('treats a value that looks like a pattern as ordinary text', function () {
    expect(Wording::filledIn('Reason: {{ step.reason }}', ['step.reason' => '$1 and \\0']))
        ->toBe('Reason: $1 and \\0');
});

/*
| A case opening
*/

it('writes to the person an exit is about the moment it opens, and records having done it', function () {
    TenantContext::run($this->meridian, function () {
        $case = (new CaseEngine)->open(theExitMeridianRuns(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        // One message, and only one. Chandni raised it a second ago and is looking at the
        // screen; this module's own research is a complaint about exactly that mail.
        expect(whatActuallyLeft())->toBe([
            'rohit@personal.example — Your Exit at Meridian Logistics has been started',
        ]);

        $sent = NotificationDelivery::query()->where('case_id', $case->getKey())->sole();

        expect((int) $sent->recipient_user_id)->toBe((int) whoIsOnMeridiansStaff('rohit')->getKey())
            ->and($sent->template_key)->toBe(TemplateCatalogue::CaseOpenedSubject)
            ->and($sent->status)->toBe(NotificationDelivery::Sent)
            ->and($sent->sent_at)->not->toBeNull()
            ->and($sent->failure_reason)->toBeNull()
            ->and($sent->step_sequence)->toBeNull();
    });
});

it('writes to the person a case is about at the address that outlives their job', function () {
    TenantContext::run($this->meridian, function () {
        $rohit = whoIsOnMeridiansStaff('rohit');

        (new CaseEngine)->open(theExitMeridianRuns(), $rohit, whoIsOnMeridiansStaff('chandni'));

        // His work account goes with the job, and an exit is the case most likely to
        // finish after it has gone. Everybody else is written to at work, which the
        // closing test below relies on.
        expect(whatActuallyLeft()[0])->toStartWith($rohit->personal_email)
            ->and($rohit->personal_email)->not->toBe($rohit->work_email);
    });
});

it('sends the client own wording with the facts of the case in it', function () {
    TenantContext::run($this->meridian, function () {
        NotificationTemplate::query()->where('key', TemplateCatalogue::CaseOpenedSubject)->sole()->update([
            'subject' => 'We have started something about you',
            'body' => "{{ person.name }} at {{ company.name }}, reference {{ case.number }}. Nothing about {{ nobody.knows }}.\n",
        ]);

        $case = (new CaseEngine)->open(theExitMeridianRuns(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        expect(whatActuallyLeft())->toBe(['rohit@personal.example — We have started something about you'])
            ->and(whatOneOfThemSaid())->toBe(
                'Rohit Menon at Meridian Logistics, reference #'.$case->number.'. Nothing about .'
            );
    });
});

it('keeps the words a message went out in when the client rewrites the message afterwards', function () {
    TenantContext::run($this->meridian, function () {
        $case = (new CaseEngine)->open(theExitMeridianRuns(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        $asItWentOut = NotificationDelivery::query()->where('case_id', $case->getKey())->sole();

        NotificationTemplate::query()->where('key', TemplateCatalogue::CaseOpenedSubject)->sole()->update([
            'subject' => 'Something completely different',
            'body' => "Nothing like what we sent.\n",
        ]);

        // The one fact on a case that could still be rewritten afterwards, and the one a
        // tribunal reads. Every other fact on a case is already frozen.
        // Stored as it read in his inbox, tokens and all already filled in — not as the
        // template said, which is the thing the client has just rewritten.
        expect($asItWentOut->fresh()->subject)->toBe('Your Exit at Meridian Logistics has been started')
            ->and($asItWentOut->fresh()->body)->toContain('Meridian Logistics has started your Exit');
    });
});

/*
| A case finishing
*/

it('writes to the person it is about and to whoever raised it when a case finishes', function () {
    TenantContext::run($this->meridian, function () {
        $rakeshs = rakeshsSeededExit();
        $token = basename((new StepLink)->issue($rakeshs, 4));

        // Asking for the link is itself a message, so it is cleared before the answer that
        // finishes the exit. The link's own record is checked in its own test below.
        Mail::mailer()->getSymfonyTransport()->flush();

        (new CaseEngine)->decideThroughALink($token, 'approved', ['note' => 'All handed over.']);

        expect($rakeshs->fresh()->closed_at)->not->toBeNull();

        // Rakesh at the address that outlives his job, and Chandni at work — she raised it
        // and had to go and look for this until now.
        expect(whatActuallyLeft())->toBe([
            'rakesh@personal.example — Your Exit at Meridian Logistics is complete',
            'chandni@meridian.test — #'.$rakeshs->number.' is finished',
        ]);

        expect(NotificationDelivery::query()->where('case_id', $rakeshs->getKey())
            ->pluck('template_key')->all())
            ->toEqualCanonicalizing([
                TemplateCatalogue::LinkToAnswerAStep,
                TemplateCatalogue::CaseClosedSubject,
                TemplateCatalogue::CaseClosedInitiator,
            ]);
    });
});

/** An exit whose only step is skipped for anybody who manages nobody, so it finishes as it opens. */
function anExitThatFinishesAsItOpens(): ProcessTemplate
{
    $skippable = ProcessTemplate::factory()->named('quick_exit', 'Quick exit')->about('employee')->create();

    ProcessStep::factory()->of($skippable)->at(1, 1)->named('Handover')
        ->heldByTheRoleAnywhere('hr_head')->offering('approved')
        ->happensWhen([['source' => 'subject', 'field' => 'manages_anyone', 'operator' => '=', 'value' => true]])
        ->create();

    $skippable->publish();

    return $skippable;
}

it('writes once, not twice, to somebody who raised their own case when it finishes', function () {
    TenantContext::run($this->meridian, function () {
        $rohit = whoIsOnMeridiansStaff('rohit');

        $case = (new CaseEngine)->open(anExitThatFinishesAsItOpens(), $rohit, $rohit);

        expect($case->fresh()->closed_at)->not->toBeNull()
            ->and(NotificationDelivery::query()->where('case_id', $case->getKey())->pluck('template_key')->all())
            ->toEqualCanonicalizing([TemplateCatalogue::CaseOpenedSubject, TemplateCatalogue::CaseClosedSubject]);
    });
});

it('dates a finished case on the client own calendar rather than the server one', function () {
    TenantContext::run($this->meridian, function () {
        // Half past one in the morning in India on 2 December, and still 1 December in UTC.
        $this->travelTo('2026-12-01 20:00:00');

        $case = (new CaseEngine)->open(anExitThatFinishesAsItOpens(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        expect(NotificationDelivery::query()->where('case_id', $case->getKey())
            ->where('template_key', TemplateCatalogue::CaseClosedSubject)->sole()->body)
            ->toContain('completed on 2 December 2026');
    });
});

it('dates a finished case on the recipient own calendar where they have one of their own', function () {
    TenantContext::run($this->meridian, function () {
        // 2 December in India, where Meridian is, and still 1 December in New York.
        $this->travelTo('2026-12-01 20:00:00');

        $rohit = whoIsOnMeridiansStaff('rohit');
        $rohit->update(['timezone' => 'America/New_York']);

        $case = (new CaseEngine)->open(anExitThatFinishesAsItOpens(), $rohit, whoIsOnMeridiansStaff('chandni'));

        expect(NotificationDelivery::query()->where('case_id', $case->getKey())
            ->where('template_key', TemplateCatalogue::CaseClosedSubject)->sole()->body)
            ->toContain('completed on 1 December 2026');
    });
});

it('tells nobody anything when a case is turned down rather than finished', function () {
    TenantContext::run($this->meridian, function () {
        $rakeshs = rakeshsSeededExit();
        $token = basename((new StepLink)->issue($rakeshs, 4));

        Mail::mailer()->getSymfonyTransport()->flush();

        (new CaseEngine)->decideThroughALink($token, 'rejected', ['note' => 'Not agreed.']);

        // It has ended, and nothing that reads like "your exit is complete" may go out
        // about it. The message for a case turned down needs the step and the reason
        // given, and both arrive with the step that tells whoever raised a case about
        // every turn it takes.
        expect($rakeshs->fresh()->closed_at)->not->toBeNull()
            ->and(whatActuallyLeft())->toBe([]);
    });
});

/*
| The link a leaver answers through
*/

it('records the link a leaver answers through against the case and against the leaver', function () {
    TenantContext::run($this->meridian, function () {
        $rakeshs = rakeshsSeededExit();

        (new StepLink)->issue($rakeshs, 4);

        $sent = NotificationDelivery::query()->where('case_id', $rakeshs->getKey())->sole();

        // Rakesh answers this step through a link, and signs in every day for everything
        // else. Recorded as no account at all, the case's list named him beside his other
        // messages and called him somebody with no account beside this one.
        expect((int) $sent->recipient_user_id)->toBe((int) whoIsOnMeridiansStaff('rakesh')->getKey())
            ->and($sent->recipient_address)->toBe('rakesh@personal.example')
            ->and($sent->template_key)->toBe(TemplateCatalogue::LinkToAnswerAStep)
            ->and((int) $sent->step_sequence)->toBe(4)
            ->and($sent->status)->toBe(NotificationDelivery::Sent)
            ->and($sent->body)->toContain('/step/')
            ->and($sent->name())->toBe('A link to answer one step');
    });
});

/*
| When the mail server will not take it
*/

it('records a refused send against the case and does not undo the case', function () {
    TenantContext::run($this->meridian, function () {
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Connection could not be established.'));

        $case = (new CaseEngine)->open(theExitMeridianRuns(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        // The exit is open. A mail server that is down must not undo a case somebody just
        // raised, and it must not be quiet about it either — retrying, and telling whoever
        // raised the case, is the step that owns failed sends.
        expect($case->fresh()->opened_at)->not->toBeNull();

        $sent = NotificationDelivery::query()->where('case_id', $case->getKey())->sole();

        expect($sent->status)->toBe(NotificationDelivery::Failed)
            ->and($sent->sent_at)->toBeNull()
            ->and($sent->failure_reason)->toBe('Connection could not be established.')
            ->and($sent->howItWent())->toBe('Did not send');
    });
});

it('says nothing about a message it has no words for, and lets the case open anyway', function () {
    TenantContext::run($this->meridian, function () {
        // A client company that existed before a message did and never got the migration
        // that adds it. The only way this is reachable, and not a reason to fail the exit
        // somebody just raised.
        // Straight off the table, because the row itself refuses to be deleted — a step
        // sends a message by naming it, so a client must never be able to leave one with
        // no words. This is the state a client company left behind by a migration would be
        // in, reached the only way it can be reached.
        DB::table('notification_templates')
            ->where('key', TemplateCatalogue::CaseOpenedSubject)->delete();

        $case = (new CaseEngine)->open(theExitMeridianRuns(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        expect($case->fresh()->opened_at)->not->toBeNull()
            ->and(whatActuallyLeft())->toBe([])
            ->and(NotificationDelivery::query()->where('case_id', $case->getKey())->count())->toBe(0);
    });
});

it('cuts a subject line the facts have made too long, and records it as it was cut', function () {
    TenantContext::run($this->meridian, function () {
        // Within the 255 a client may type, and longer than that once the links are in.
        $template = NotificationTemplate::query()->where('key', TemplateCatalogue::CaseOpenedSubject)->sole();
        $template->subject = str_repeat('x', 200).' {{case.link}} {{case.link}}';
        $template->save();

        $case = (new CaseEngine)->open(theExitMeridianRuns(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        $sent = NotificationDelivery::query()->where('case_id', $case->getKey())->sole();

        expect(mb_strlen($sent->subject))->toBe(255)
            ->and(whatActuallyLeft())->toBe(['rohit@personal.example — '.$sent->subject]);
    });
});

it('sends nothing at all where the client wording renders away to nothing', function () {
    TenantContext::run($this->meridian, function () {
        // A subject line made only of tokens, none of which has a value. The table refuses
        // a record with no words in it, and an error page on the exit somebody just raised
        // is a worse answer than sending nothing.
        $template = NotificationTemplate::query()->where('key', TemplateCatalogue::CaseOpenedSubject)->sole();
        $template->subject = '{{ nobody.knows }}';
        $template->save();

        $case = (new CaseEngine)->open(theExitMeridianRuns(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        expect($case->fresh()->opened_at)->not->toBeNull()
            ->and(whatActuallyLeft())->toBe([])
            ->and(NotificationDelivery::query()->where('case_id', $case->getKey())->count())->toBe(0);
    });
});

/*
| Nothing is sent from inside the write
*/

it('sends nothing while the write that earned the message is still open', function () {
    TenantContext::run($this->meridian, function () {
        // The engine takes row locks while it writes, and a send is a conversation with a
        // mail server. Proven by watching for the send from inside the transaction the
        // engine itself opens: a message that left in there would be a lock held open
        // across a network call, and a commit that then failed would have written to
        // somebody about a case that does not exist.
        $sentWhileOpen = 0;

        // The most mail ever seen at any point while the engine's own transaction was
        // open, and not merely the first reading — a send happens between two queries, so
        // sampling once catches the moment before it and proves nothing.
        DB::listen(function () use (&$sentWhileOpen) {
            if (DB::transactionLevel() > 1) {
                $sentWhileOpen = max($sentWhileOpen, Mail::mailer()->getSymfonyTransport()->messages()->count());
            }
        });

        (new CaseEngine)->open(theExitMeridianRuns(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        expect($sentWhileOpen)->toBe(0)
            ->and(whatActuallyLeft())->toHaveCount(1);
    });
});

it('tells whoever raised a request about nobody that it has finished, and tells nobody else', function () {
    TenantContext::run($this->meridian, function () {
        // A hiring request is about a vacancy rather than a person, so there is nobody for
        // the other closing message to go to — and it must not go to whoever raised it
        // instead, which is how a person ends up reading "your hiring request is complete"
        // about somebody else's vacancy.
        $request = ProcessCase::query()
            ->whereRelation('template', 'key', 'hiring_request')
            ->whereNull('closed_at')->whereNull('cancelled_at')
            ->orderBy('number')->firstOrFail();

        $reader = new AvailableSteps;
        $resolver = new AssigneeResolver;
        $engine = new CaseEngine;

        while (($next = $reader->for($request->fresh())->first()) !== null) {
            $engine->decide(
                $request, $next->step->sequence, 'approved',
                $resolver->resolve($request, $next->step)->first(),
            );
        }

        expect($request->fresh()->closed_at)->not->toBeNull()
            ->and($request->subject)->toBeNull();

        // Anjali raised it, and the heading says which vacancy without naming a process —
        // read off the questions the client's own form asks.
        expect(whatActuallyLeft())->toBe(['anjali@meridian.test — #'.$request->number.' is finished'])
            ->and(whatOneOfThemSaid())->toContain('Anjali Rao,')
            ->and(whatOneOfThemSaid())->toContain('/admin/cases/'.$request->getKey());
    });
});

it('never carries a message from a write that came apart into the next one', function () {
    TenantContext::run($this->meridian, function () {
        // An exit whose only step is skipped for anybody who manages nobody, so opening it
        // and closing it are one write — which is the only shape where anything at all
        // happens after a message has been earned and before the write is finished.
        $skippable = ProcessTemplate::factory()->named('quick_exit', 'Quick exit')->about('employee')->create();

        ProcessStep::factory()->of($skippable)->at(1, 1)->named('Handover')
            ->heldByTheRoleAnywhere('hr_head')->offering('approved')
            ->happensWhen([['source' => 'subject', 'field' => 'manages_anyone', 'operator' => '=', 'value' => true]])
            ->create();

        $skippable->publish();

        // Rohit manages nobody, so this exit finishes the moment it opens. Made to come
        // apart at the closing write, by which point the opening message has already been
        // earned and is waiting.
        Event::listen('eloquent.updated: '.ProcessCase::class, function (): void {
            throw new RuntimeException('The write came apart.');
        });

        $engine = new CaseEngine;

        expect(fn () => $engine->open($skippable, whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni')))
            ->toThrow(RuntimeException::class, 'The write came apart.');

        expect(whatActuallyLeft())->toBe([])
            ->and(NotificationDelivery::query()->count())->toBe(0);

        // The same engine used again, which is how the demo data is built and how anything
        // written from the command line runs. Every screen makes its own, so none of this
        // is reachable by clicking — and a message about a case that was never created is
        // the exact failure this whole module exists to stop, so it is closed rather than
        // written down as a curiosity.
        Event::forget('eloquent.updated: '.ProcessCase::class);

        $worked = $engine->open(theExitMeridianRuns(), whoIsOnMeridiansStaff('rohit'), whoIsOnMeridiansStaff('chandni'));

        expect(whatActuallyLeft())->toBe([
            'rohit@personal.example — Your Exit at Meridian Logistics has been started',
        ])->and(NotificationDelivery::query()->where('case_id', $worked->getKey())->count())->toBe(1);
    });
});
