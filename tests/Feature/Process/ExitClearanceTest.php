<?php

use App\Exceptions\ProcessRefused;
use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Process\StepLink;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Database\Seeders\VertexSeeder;

/*
| Module 07: the exit Meridian really runs, as the demo seeds it.
|
| The leaver's manager accepts it, every clearance opens at once, and HR checks the whole
| thing last. Two claims from the module's finishing list are checked here against the
| seeded company rather than a fixture: how many clearances run is the number of rows a
| client wrote, and HR's final check cannot open while any one of them is outstanding.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody at Meridian, by first name. */
function leavingFromMeridian(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** The seeded exit about one person, on Meridian's real exit process. */
function meridiansExitAbout(string $first): ProcessCase
{
    return ProcessCase::query()
        ->whereRelation('subject', 'work_email', $first.'@meridian.test')
        ->whereRelation('template', 'key', 'exit')
        ->sole();
}

/**
 * The names of the steps waiting on this case right now, in the client's order.
 *
 * @return list<string>
 */
function waitingOnTheExit(ProcessCase $case): array
{
    $case->unsetRelation('liveSteps');

    return (new AvailableSteps)->for($case)
        ->map(fn (AvailableStep $step): string => $step->step->name)
        ->values()
        ->all();
}

it('opens every clearance the client wrote at once, three at Meridian and seven at Vertex', function () {
    TenantContext::run($this->meridian, function () {
        // Every seeded exit is already past its manager, so Deepak's is withdrawn and he
        // resigns again, which starts a fresh one from the beginning.
        $deepak = leavingFromMeridian('deepak');
        $seeded = meridiansExitAbout('deepak');

        (new CaseEngine)->cancel($seeded, leavingFromMeridian('chandni'), 'Withdrew the resignation');

        $again = (new CaseEngine)->open($seeded->template, $deepak, $deepak, now()->addMonth()->toDateString(), 'resignation');

        // Raised, and waiting only on his manager.
        expect(waitingOnTheExit($again))->toBe(['Manager approval']);

        (new CaseEngine)->decide($again, 1, 'approved', leavingFromMeridian('rakesh'));

        // Every clearance Meridian wrote, all at once, and HR's final check not yet.
        expect(waitingOnTheExit($again))
            ->toBe(['HR clearance', 'Finance clearance', 'Leaver confirms the handover']);
    });

    // The same engine with Vertex's rows opens seven. Nothing in the code counts them.
    $this->seed(VertexSeeder::class);
    $vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    TenantContext::run($vertex, function () {
        expect(waitingOnTheExit(ProcessCase::query()->whereRelation('template', 'key', 'exit')->sole()))
            ->toHaveCount(7);
    });
});

it('keeps HR\'s final check closed while one clearance is still outstanding', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh's manager, HR and finance have all answered. Only his own confirmation,
        // through the link to his personal address, is left.
        $rakeshs = meridiansExitAbout('rakesh');
        $priya = leavingFromMeridian('priya');

        expect(waitingOnTheExit($rakeshs))->toBe(['Leaver confirms the handover']);

        // Priya holds HR head over Shimla, so the final check will be hers — and it is not
        // in her list and cannot be answered, however it is asked.
        expect((new AvailableSteps)->waitingOn($priya)
            ->contains(fn (AvailableStep $waiting) => $waiting->case->is($rakeshs)))->toBeFalse()
            ->and(fn () => (new CaseEngine)->decide($rakeshs, 5, 'approved', $priya))
            ->toThrow(ProcessRefused::class, 'Step 5 is not open on this case.');

        // The token is the last part of the address the link is sent as.
        $token = basename((new StepLink)->issue($rakeshs, 4));

        (new CaseEngine)->decideThroughALink($token, 'approved', ['note' => 'All handed over.']);

        // The last clearance in, and only now does the final check open.
        expect(waitingOnTheExit($rakeshs))->toBe(['HR final check']);

        (new CaseEngine)->decide($rakeshs, 5, 'approved', $priya);

        expect($rakeshs->fresh()->state)->toBe(ProcessCase::Closed);
    });
});
