<?php

use App\Filament\Pages\MyQueue;
use App\Models\ProcessCase;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Livewire\Livewire;

/*
| The hiring request, end to end, against the demo company exactly as it is seeded.
|
| The first process in this product that is not about a person. It exists to prove that
| the engine runs a whole flow with no code of its own: three steps, an approval chain
| scoped from the request's own answers, and a director who is only asked when the salary
| is over a figure the client can change.
|
| Two requests are already in flight — one under the threshold, one over — so both
| branches can be watched from the same seeded company.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Whichever of the seeded hiring requests offers this salary. */
function theRequestOffering(int $salary): ProcessCase
{
    return ProcessCase::query()->whereNull('subject_user_id')->get()
        ->sole(fn (ProcessCase $case) => ($case->answersSoFar()['annual_ctc'] ?? null) == $salary);
}

/** Somebody at the demo company, by first name. */
function whoWorksThere(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** The names of the steps waiting on somebody, whatever kind of case they belong to. */
function stepsWaitingOnThem(User $person): array
{
    return (new AvailableSteps)->waitingOn($person)
        ->map(fn ($waiting) => $waiting->step->name)
        ->sort()->values()->all();
}

/** Raise a fresh request offering this salary, and take it as far as the first approval. */
function aFurtherRequestOffering(int $salary): ProcessCase
{
    $engine = new CaseEngine;
    $anjali = whoWorksThere('anjali');
    $like = theRequestOffering(900000)->answersSoFar();

    $request = $engine->open(
        ProcessTemplate::query()->where('key', 'hiring_request')
            ->where('status', ProcessTemplate::Published)->sole(),
        by: $anjali,
    );

    $engine->decide($request, 1, 'approved', $anjali, [...$like, 'annual_ctc' => $salary]);

    return $request->fresh();
}

it('puts both requests in front of the branch manager and nobody else', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh holds line-of-business head over Shimla, and both requests name Shimla.
        // Neither of them is about a person, so nothing but the answers on the request
        // could have sent them to him.
        expect(stepsWaitingOnThem(whoWorksThere('rakesh')))
            ->toBe([
                'HR clearance',
                'HR clearance',
                'Line-of-business approval',
                'Line-of-business approval',
            ]);

        // Chandni holds director over the whole company, and neither request has reached
        // that step. Anjali raised both and holds nothing.
        expect(stepsWaitingOnThem(whoWorksThere('chandni')))->not->toContain('Director approval')
            ->and(stepsWaitingOnThem(whoWorksThere('anjali')))->toBe([]);
    });
});

it('approves a step that asks nothing, from the button on the screen', function () {
    TenantContext::run($this->meridian, function () {
        $request = theRequestOffering(900000);

        // The approval is only a decision — it asks no questions at all. Pressing the
        // button used to end on an error page, because the screen asked Livewire to check
        // an empty list of answers and Livewire treats that as a page with no rules
        // written on it rather than as nothing to check.
        Livewire::actingAs(whoWorksThere('rakesh'))->test(MyQueue::class)
            ->call('decide', $request->getKey(), 2, 'approved')
            ->assertHasNoErrors()
            ->assertOk();

        expect($request->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

it('finishes the cheaper request the moment the branch manager approves it', function () {
    TenantContext::run($this->meridian, function () {
        $request = theRequestOffering(900000);

        (new CaseEngine)->decide($request, 2, 'approved', whoWorksThere('rakesh'));

        // Nine lakh is under the client's threshold, so the director's step was never
        // wanted and the request is finished.
        expect($request->fresh()->state)->toBe(ProcessCase::Closed)
            ->and($request->fresh()->liveSteps()->where('sequence', 3)->count())->toBe(0)
            ->and(stepsWaitingOnThem(whoWorksThere('chandni')))->not->toContain('Director approval');
    });
});

it('hands the expensive request to the director, and cannot finish without them', function () {
    TenantContext::run($this->meridian, function () {
        $request = theRequestOffering(2400000);

        (new CaseEngine)->decide($request, 2, 'approved', whoWorksThere('rakesh'));

        expect($request->fresh()->state)->toBe(ProcessCase::Open)
            ->and(stepsWaitingOnThem(whoWorksThere('chandni')))->toContain('Director approval');

        (new CaseEngine)->decide($request->fresh(), 3, 'approved', whoWorksThere('chandni'));

        expect($request->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

it('moves the next request when the threshold changes, and leaves one in flight alone', function () {
    TenantContext::run($this->meridian, function () {
        $inFlight = theRequestOffering(2400000);

        // The client decides twenty-four lakh no longer needs the director. Nothing about
        // the request already raised changes: it froze the figure that was true when it
        // opened, so the director's step is still coming.
        app(Settings::class)->set('hiring_director_threshold', 3000000);

        (new CaseEngine)->decide($inFlight, 2, 'approved', whoWorksThere('rakesh'));

        expect($inFlight->fresh()->state)->toBe(ProcessCase::Open)
            ->and(stepsWaitingOnThem(whoWorksThere('chandni')))->toContain('Director approval');

        // The next one at the same salary skips the director entirely, and the process
        // itself was never touched.
        $next = aFurtherRequestOffering(2400000);

        (new CaseEngine)->decide($next, 2, 'approved', whoWorksThere('rakesh'));

        expect($next->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

it('ends a request the branch manager turns down, with the reason on the record', function () {
    TenantContext::run($this->meridian, function () {
        $request = theRequestOffering(2400000);

        (new CaseEngine)->decide(
            $request,
            2,
            'rejected',
            whoWorksThere('rakesh'),
            reason: 'The depot volumes do not justify a second manager yet.',
        );

        expect($request->fresh()->state)->toBe(ProcessCase::Closed)
            ->and($request->liveSteps()->where('sequence', 2)->sole()->outcome)->toBe('rejected')
            ->and(stepsWaitingOnThem(whoWorksThere('chandni')))->not->toContain('Director approval');

        expect($request->events()->where('type', 'step_acted')->get()
            ->pluck('payload.reason')->filter()->all())
            ->toContain('The depot volumes do not justify a second manager yet.');
    });
});
