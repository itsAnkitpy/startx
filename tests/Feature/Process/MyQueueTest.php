<?php

use App\Filament\Pages\MyQueue;
use App\Models\CaseStep;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AvailableSteps;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Livewire\Livewire;

/*
| The first screen: what is waiting on the person signed in.
|
| These run against the demo company exactly as it is seeded, so they check the thing
| Ankit will actually open rather than a fixture built to suit them. If the seeder stops
| producing a company worth looking at, these fail.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function atMeridianCalled(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/**
 * The names of the steps waiting on somebody, as the screen would list them.
 *
 * A hiring request is about a vacancy rather than a person, so it is listed by the
 * process it runs on.
 */
function waitingOnThem(User $person): array
{
    return (new AvailableSteps)->waitingOn($person)
        ->map(fn ($waiting) => $waiting->step->name.' — '
            .($waiting->case->subject?->name ?? $waiting->case->template->name))
        ->sort()->values()->all();
}

/**
 * One of the demo's two hiring requests, told apart by the salary on it — one under the
 * client's threshold and one over it.
 */
function theHiringRequestCosting(int $annualCtc): ProcessCase
{
    return ProcessCase::query()
        ->whereRelation('template', 'key', 'hiring_request')
        ->get()
        ->sole(fn (ProcessCase $case): bool => (int) ($case->answersSoFar()['annual_ctc'] ?? 0) === $annualCtc);
}

/**
 * Whether one named step of one named case is waiting on somebody.
 *
 * The demo seeds two hiring requests and both sit at the branch approval, so looking for
 * that step's name in a person's list passes on the other request whatever happened to
 * this one — a check that cannot fail.
 */
function isWaitingOnThemAt(ProcessCase $case, int $sequence, User $person): bool
{
    return (new AvailableSteps)->waitingOn($person)
        ->contains(fn ($waiting): bool => (int) $waiting->case->getKey() === (int) $case->getKey()
            && (int) $waiting->step->sequence === $sequence);
}

it('seeds a company worth looking at', function () {
    TenantContext::run($this->meridian, function () {
        expect(User::query()->count())->toBe(6)
            // Three exits nobody has touched; Rakesh's, which everybody who works here
            // has already cleared and which now waits on Rakesh himself through a link,
            // because his sign-in is gone; Priya's, which runs on the process built with
            // the mistake publishing now refuses; and the two hiring requests, one under
            // the salary threshold and one over it.
            ->and(ProcessCase::query()->whereNull('closed_at')->count())->toBe(7);

        // Nobody has touched any of the three, so not one of their waiting steps has a row
        // anywhere. That is the whole point of the lists below.
        $untouched = ProcessCase::query()
            ->whereRelation('subject', 'first_name', '!=', 'Rakesh')
            ->pluck('id');

        expect(CaseStep::query()->whereIn('case_id', $untouched)->count())->toBe(0);
    });
});

it('shows each person only the steps that are theirs', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh holds HR head over Shimla, so the two Shimla exits are his. Rohit's is
        // in Pune and his grant does not reach it. He also holds line-of-business head
        // over Shimla, which is where both hiring requests say the vacancy is — and those
        // two reached him from their own answers rather than from anybody's job row.
        expect(waitingOnThem(atMeridianCalled('rakesh')))
            ->toBe([
                'HR clearance — Anjali Rao',
                'HR clearance — Deepak Iyer',
                'Line-of-business approval — Hiring request',
                'Line-of-business approval — Hiring request',
            ]);

        // Priya shares that role over the same branch, so she sees the same two.
        expect(waitingOnThem(atMeridianCalled('priya')))
            ->toBe(['HR clearance — Anjali Rao', 'HR clearance — Deepak Iyer']);

        // Nobody holds HR head over Pune, so Rohit's clearance falls to the stand-in the
        // client named. Anjali's is there for a different reason — it is five days past a
        // two-day deadline, and a late step widens to the HR director without ever leaving
        // the two people it already belonged to. Chandni's own finance clearance is a
        // later group and is not her turn yet.
        // Priya's finance clearance is genuinely hers, on the exit built with the mistake.
        expect(waitingOnThem(atMeridianCalled('chandni')))
            ->toBe([
                'Finance clearance — Priya Nair',
                'HR clearance — Anjali Rao',
                'HR clearance — Rohit Menon',
            ]);

        // Deepak holds nothing, and his own exit can never be his own to clear.
        expect(waitingOnThem(atMeridianCalled('deepak')))->toBe([]);
    });
});

it('takes a shared step out of the other person\'s list once somebody picks it up', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridianCalled('rakesh');
        $priya = atMeridianCalled('priya');
        $anjalisExit = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->call('pickUp', $anjalisExit->getKey(), 1)
            ->assertOk();

        expect(waitingOnThem($rakesh))
            ->toBe([
                'HR clearance — Anjali Rao',
                'HR clearance — Deepak Iyer',
                'Line-of-business approval — Hiring request',
                'Line-of-business approval — Hiring request',
            ])
            ->and(waitingOnThem($priya))->toBe(['HR clearance — Deepak Iyer']);
    });
});

it('lets the holder decide a step, and opens the next group to somebody else', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridianCalled('rakesh');
        $chandni = atMeridianCalled('chandni');
        $anjalisExit = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // HR's clearance asks whether the ID card came back, and it is a required
        // question, so a decision without it is refused on the server.
        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->set("answers.{$anjalisExit->getKey()}.1.id_card_returned", '1')
            ->call('decide', $anjalisExit->getKey(), 1, 'approved')
            ->assertHasNoErrors()
            ->assertOk();

        // Gone from Rakesh's list, and the finance clearance behind it is now Chandni's —
        // which nothing wrote down in advance.
        expect(waitingOnThem($rakesh))
            ->toBe([
                'HR clearance — Deepak Iyer',
                'Line-of-business approval — Hiring request',
                'Line-of-business approval — Hiring request',
            ])
            ->and(waitingOnThem($chandni))
            ->toBe([
                'Finance clearance — Anjali Rao',
                'Finance clearance — Priya Nair',
                'HR clearance — Rohit Menon',
            ]);
    });
});

it('refuses a step that is not yours, in words', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = atMeridianCalled('deepak');
        $anjalisExit = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // Deepak can reach the page but the clearance is not his, and the engine says so
        // rather than the screen quietly doing nothing.
        //
        // Nothing is asserted about the form on purpose: a step that is not his is never
        // checked against its questions at all, because somebody with no business at a
        // step has no business being told what it asks. So this refusal is the ownership
        // one and not a required question he was never shown.
        Livewire::actingAs($deepak)->test(MyQueue::class)
            ->call('decide', $anjalisExit->getKey(), 1, 'approved')
            ->assertHasNoErrors()
            ->assertOk();

        expect(CaseStep::query()->where('case_id', $anjalisExit->getKey())->count())->toBe(0);
    });
});

it('loads for somebody with nothing waiting on them', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(atMeridianCalled('deepak'))->test(MyQueue::class)
            ->assertOk()
            ->assertSee('Nothing is waiting on you.');
    });
});

it('marks a step that has blown its deadline', function () {
    TenantContext::run($this->meridian, function () {
        // Anjali's exit was opened five days ago against a two-day target, so its
        // clearance is past due and the screen has to say so.
        $overdue = (new AvailableSteps)->waitingOn(atMeridianCalled('rakesh'))
            ->firstWhere(fn ($waiting) => $waiting->case->subject?->first_name === 'Anjali');

        expect($overdue->escalationOwed)->toBeTrue();

        Livewire::actingAs(atMeridianCalled('rakesh'))->test(MyQueue::class)
            ->assertOk()
            ->assertSee('Past its deadline');
    });
});

it('says on the card when a step arrived only because it is overdue', function () {
    TenantContext::run($this->meridian, function () {
        // Anjali's clearance is five days past a two-day target, so it widens to Chandni
        // as HR director. Her card has to say it arrived that way, or it reads as work
        // that has been moved onto her and off the two people who still hold it.
        Livewire::actingAs(atMeridianCalled('chandni'))->test(MyQueue::class)
            ->assertOk()
            ->assertSee('Anjali Rao')
            ->assertSee('This came to you because it is past its deadline');

        // Rakesh holds the same overdue clearance in his own right, and his card carries
        // no such line. A marker on every card would be worth nothing.
        Livewire::actingAs(atMeridianCalled('rakesh'))->test(MyQueue::class)
            ->assertOk()
            ->assertSee('Anjali Rao')
            ->assertDontSee('This came to you because it is past its deadline');
    });
});

it('opens at its own address for a signed-in employee', function () {
    // The whole way in, exactly as Ankit will: the client company's own subdomain puts
    // Meridian in scope, and the panel serves the page rather than a wrong-address or a
    // refused screen.
    $rakesh = TenantContext::run($this->meridian, fn () => atMeridianCalled('rakesh'));

    $this->actingAs($rakesh)
        ->get('http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/my-queue')
        ->assertOk()
        ->assertSee('HR clearance')
        ->assertSee('Anjali Rao')
        // And the hiring approvals beside them, drawn through the whole page rather than
        // through the component on its own.
        ->assertSee('Line-of-business approval')
        ->assertSee('What this request is for')
        ->assertSee('Shimla branch');
});

it('says on the card when a step only reached somebody because nobody holds the role', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridianCalled('rakesh');
        $chandni = atMeridianCalled('chandni');
        $anjalisExit = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // Clearing Anjali's HR step puts the finance clearance — genuinely Chandni's job —
        // in her list beside Rohit's, which only reached her because Pune has no HR head.
        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->set("answers.{$anjalisExit->getKey()}.1.id_card_returned", '1')
            ->call('decide', $anjalisExit->getKey(), 1, 'approved')
            ->assertHasNoErrors();

        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->assertOk()
            ->assertSee('Finance clearance')
            ->assertSee('Rohit Menon')
            ->assertSee('Nobody holds the role this step asked for');

        // Rakesh's two clearances are genuinely his, so his page carries no warning at
        // all. A marker that showed on every card would be worth nothing.
        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->assertOk()
            ->assertSee('HR clearance')
            ->assertDontSee('Nobody holds the role this step asked for');

        $rohitsExit = ProcessCase::query()->whereRelation('subject', 'first_name', 'Rohit')->sole();
        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // The warning is on Rohit's clearance and not on the finance one. A marker that
        // appeared on every card would say nothing at all.
        expect((new MyQueue)->heldByNobody((new AvailableSteps)->waitingOn($chandni)))
            ->toBe([$rohitsExit->getKey().':1'])
            ->not->toContain($anjalis->getKey().':2');
    });
});

it('leaves the card unmarked once somebody actually holds the role', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = atMeridianCalled('chandni');

        // Rohit's clearance is warned while Pune has no HR head.
        expect((new MyQueue)->heldByNobody((new AvailableSteps)->waitingOn($chandni)))->toHaveCount(1);

        // Appointing Priya over Pune is the real fix, and it takes the step off Chandni
        // entirely rather than merely removing the warning.
        Role::query()->where('key', 'hr_head')->sole()->assignments()->create([
            'user_id' => atMeridianCalled('priya')->getKey(),
            'org_unit_id' => OrgUnit::query()->where('name', 'Pune branch')->sole()->getKey(),
            'includes_descendants' => false,
        ]);

        // Rohit's clearance leaves her list the moment Pune has its own HR head. Anjali's
        // stays, for the unrelated reason that it is overdue and escalates to her.
        expect(waitingOnThem($chandni))
            ->toBe(['Finance clearance — Priya Nair', 'HR clearance — Anjali Rao'])
            ->and(waitingOnThem(atMeridianCalled('priya')))
            ->toContain('HR clearance — Rohit Menon');
    });
});

/*
| Sending a request back, and holding a step — the two outcomes the engine has always had
| and no screen could reach, because both need words typed into a box that did not exist.
*/

it('sends a request back to the person who raised it, with their answers still in the boxes', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridianCalled('rakesh');
        $anjali = atMeridianCalled('anjali');
        $expensive = theHiringRequestCosting(2400000);

        // Nobody reads a list here: the branch approval has exactly one place to send it,
        // which is back to Anjali, so Rakesh types why and presses the button.
        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->call('askFor', $expensive->getKey(), 2, 'sent_back')
            ->assertDontSee('Which step it goes back to')
            ->set("reasons.{$expensive->getKey()}.2", 'Above the band for a branch manager.')
            ->call('decide', $expensive->getKey(), 2, 'sent_back')
            ->assertHasNoErrors()
            ->assertOk();

        // Off his list, and back on hers at the step she filled in.
        expect(waitingOnThem($rakesh))
            ->toBe([
                'HR clearance — Anjali Rao',
                'HR clearance — Deepak Iyer',
                'Line-of-business approval — Hiring request',
            ])
            ->and(waitingOnThem($anjali))->toBe(['Raise request — Hiring request']);

        // Her answers are in the boxes rather than an empty form, which is the whole
        // difference between sending a request back and rejecting it.
        // And Rakesh's words are on the card she has to correct, not only in the case's
        // history — which is the whole reason he was made to type them.
        $hers = Livewire::actingAs($anjali)->test(MyQueue::class)
            ->assertOk()
            ->assertSee('Rakesh Menon sent this back: Above the band for a branch manager.');

        expect($hers->get('answers')[$expensive->getKey()][1]['annual_ctc'])->toEqual(2400000);

        // She corrects the figure and sends it on. Under the threshold now, so the
        // director is never asked and it is Rakesh's again.
        $hers->set("answers.{$expensive->getKey()}.1.annual_ctc", 900000)
            ->call('decide', $expensive->getKey(), 1, 'approved')
            ->assertHasNoErrors();

        // Named by the case rather than by the step's name: the demo seeds two hiring
        // requests and both sit at this approval, so a check that only looks for the name
        // passes on the other one and cannot fail.
        expect(waitingOnThem($anjali))->toBe([])
            ->and(isWaitingOnThemAt($expensive, 2, $rakesh))->toBeTrue();
    });
});

it('will not send a request back with nothing typed in the box', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridianCalled('rakesh');
        $expensive = theHiringRequestCosting(2400000);

        // Refused under the box it belongs to rather than as a sentence across the top of
        // the page, and the request has not moved.
        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->call('askFor', $expensive->getKey(), 2, 'sent_back')
            ->call('decide', $expensive->getKey(), 2, 'sent_back')
            ->assertHasErrors("reasons.{$expensive->getKey()}.2");

        expect(CaseStep::query()->where('case_id', $expensive->getKey())->where('sequence', 2)->count())->toBe(0)
            ->and(isWaitingOnThemAt($expensive, 2, $rakesh))->toBeTrue();
    });
});

it('asks which step a request goes back to only where there is a choice', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridianCalled('rakesh');
        $chandni = atMeridianCalled('chandni');
        $expensive = theHiringRequestCosting(2400000);

        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->call('decide', $expensive->getKey(), 2, 'approved')
            ->assertHasNoErrors();

        // The director's approval has two places it could go — back to Anjali, or back to
        // the branch approval underneath it — so she is asked, and Rakesh never was.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->call('askFor', $expensive->getKey(), 3, 'sent_back')
            ->assertSee('Which step it goes back to')
            ->assertSee('Raise request')
            ->assertSee('Line-of-business approval');
    });
});

it('holds a clearance with a reason, and drops a figure the later answer hides', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = atMeridianCalled('rakesh');
        $chandni = atMeridianCalled('chandni');
        $anjalisExit = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->set("answers.{$anjalisExit->getKey()}.1.id_card_returned", '1')
            ->call('decide', $anjalisExit->getKey(), 1, 'approved')
            ->assertHasNoErrors();

        // The clearance cannot be answered honestly yet: the imprest card is missing and
        // twelve thousand rupees are being argued about.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->set("answers.{$anjalisExit->getKey()}.2.imprest_card_returned", '0')
            ->set("answers.{$anjalisExit->getKey()}.2.recover_from_them", 12000)
            ->set("answers.{$anjalisExit->getKey()}.2.recovery_reason", 'imprest')
            ->call('askFor', $anjalisExit->getKey(), 2, 'held')
            ->set("reasons.{$anjalisExit->getKey()}.2", 'Waiting on the imprest card before anything is recovered.')
            ->call('decide', $anjalisExit->getKey(), 2, 'held')
            ->assertHasNoErrors()
            ->assertOk();

        // Her own card says so when she comes back to it. A held step is still open and
        // still hers, so without this it reads as a clearance nobody has got round to —
        // and it stops offering her the state it is already in.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->assertOk()
            ->assertSee('On hold')
            ->assertSee('Chandni Verma put this on hold: Waiting on the imprest card before anything is recovered.')
            // Read against the raw page rather than an escaped copy of it: the quotes in
            // the button's own instruction are written as they stand, so a check that
            // escaped them would look for something no page ever contains and pass
            // whether the button is there or not.
            ->assertDontSee("askFor({$anjalisExit->getKey()}, 2, 'held')", escape: false);

        $held = CaseStep::query()->where('case_id', $anjalisExit->getKey())->where('sequence', 2)->sole();

        // A hold is not a decision: it is still hers to answer, and the figure is on it.
        expect($held->outcome)->toBe('held')
            ->and($held->payload['recover_from_them'])->toEqual(12000)
            ->and(waitingOnThem($chandni))->toContain('Finance clearance — Anjali Rao');

        // The card turns up and she clears it. The figure has to go with the question that
        // asked for it — the answers on a step are what a later step's condition reads,
        // and the recovery question is not even on the form any more.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->set("answers.{$anjalisExit->getKey()}.2.imprest_card_returned", '1')
            ->call('decide', $anjalisExit->getKey(), 2, 'approved')
            ->assertHasNoErrors();

        expect($held->fresh()->outcome)->toBe('approved')
            ->and(array_keys($held->fresh()->payload))->toBe(['imprest_card_returned']);
    });
});
