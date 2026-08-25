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

/** The names of the steps waiting on somebody, as the screen would list them. */
function waitingOnThem(User $person): array
{
    return (new AvailableSteps)->waitingOn($person)
        ->map(fn ($waiting) => $waiting->step->name.' — '.$waiting->case->subject->name)
        ->sort()->values()->all();
}

it('seeds a company worth looking at', function () {
    TenantContext::run($this->meridian, function () {
        expect(User::query()->count())->toBe(6)
            // Three exits nobody has touched, and Rakesh's, which everybody who works
            // here has already cleared and which now waits on Rakesh himself through a
            // link, because his sign-in is gone.
            ->and(ProcessCase::query()->whereNull('closed_at')->count())->toBe(4);

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
        // in Pune and his grant does not reach it.
        expect(waitingOnThem(atMeridianCalled('rakesh')))
            ->toBe(['HR clearance — Anjali Rao', 'HR clearance — Deepak Iyer']);

        // Priya shares that role over the same branch, so she sees the same two.
        expect(waitingOnThem(atMeridianCalled('priya')))
            ->toBe(['HR clearance — Anjali Rao', 'HR clearance — Deepak Iyer']);

        // Nobody holds HR head over Pune, so Rohit's clearance falls to the stand-in the
        // client named. Anjali's is there for a different reason — it is five days past a
        // two-day deadline, and a late step widens to the HR director without ever leaving
        // the two people it already belonged to. Chandni's own finance clearance is a
        // later group and is not her turn yet.
        expect(waitingOnThem(atMeridianCalled('chandni')))
            ->toBe(['HR clearance — Anjali Rao', 'HR clearance — Rohit Menon']);

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
            ->toBe(['HR clearance — Anjali Rao', 'HR clearance — Deepak Iyer'])
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
        expect(waitingOnThem($rakesh))->toBe(['HR clearance — Deepak Iyer'])
            ->and(waitingOnThem($chandni))
            ->toBe(['Finance clearance — Anjali Rao', 'HR clearance — Rohit Menon']);
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
            ->firstWhere(fn ($waiting) => $waiting->case->subject->first_name === 'Anjali');

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
        ->assertSee('Anjali Rao');
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
        expect(waitingOnThem($chandni))->toBe(['HR clearance — Anjali Rao'])
            ->and(waitingOnThem(atMeridianCalled('priya')))
            ->toContain('HR clearance — Rohit Menon');
    });
});
