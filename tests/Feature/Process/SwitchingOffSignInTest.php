<?php

use App\Authorization\AdministratorFloor;
use App\Exceptions\ProcessRefused;
use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Models\CaseStep;
use App\Models\Delegation;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\Succession;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\CaseEngine;
use App\Process\StepLink;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Callout;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

/*
| Module 07 step 5: the account switches off at the end of the last working day.
|
| Seeded at nine on Monday 16 November 2026 on India's clock, so every time below is one
| somebody at Meridian would read. Rakesh's last working day is Monday 23 November, Rohit's
| 30 November, Deepak's 7 December and Anjali's 16 December. Midnight in India is half past
| six the evening before in UTC, so a pass reading the wrong clock would wait five and a
| half hours longer and these tests would catch it.
*/

beforeEach(function () {
    $this->travelTo(onMeridiansWallClock('2026-11-16 09:00'));

    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

function onMeridiansWallClock(string $wallTime): CarbonImmutable
{
    return CarbonImmutable::parse($wallTime, 'Asia/Kolkata');
}

function meridianEmployee(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

function meridianExitAbout(string $first): ProcessCase
{
    return ProcessCase::query()->where('subject_user_id', meridianEmployee($first)->getKey())->latest('id')->firstOrFail();
}

/** The every-minute pass, run once as the scheduler runs it, and what it printed for Meridian. */
function runTheEveryMinutePass(): string
{
    Artisan::call('steps:chase');

    return (string) collect(explode("\n", Artisan::output()))->first(fn (string $line): bool => str_starts_with($line, 'Meridian'));
}

/** Whether somebody at Meridian can sign in, read fresh. */
function canSignIn(string $first): bool
{
    return TenantContext::run(Tenant::query()->where('slug', MeridianSeeder::Slug)->sole(), fn (): bool => (bool) meridianEmployee($first)->active);
}

/** The exit's page as Chandni, an administrator, sees it. */
function chandniOpens(ProcessCase $exit): mixed
{
    return Livewire::actingAs(meridianEmployee('chandni'))->test(ViewCase::class, ['record' => $exit->getKey()]);
}

it('switches Rakesh\'s sign-in off once his last working day has ended on Meridian\'s clock, not a minute before, and never undoes HR turning it back on', function () {
    $rakeshs = TenantContext::run($this->meridian, fn () => meridianExitAbout('rakesh'));
    expect($rakeshs->statutory_from->toDateString())->toBe('2026-11-23');

    TenantContext::run($this->meridian, fn () => chandniOpens($rakeshs)->assertSee('Switches off once 23 November 2026 has ended'));

    $this->travelTo(onMeridiansWallClock('2026-11-23 23:59'));
    expect(runTheEveryMinutePass())->not->toContain('sign-in switched off')
        ->and(canSignIn('rakesh'))->toBeTrue();

    $this->travelTo(onMeridiansWallClock('2026-11-24 00:00'));
    expect(runTheEveryMinutePass())->toContain('sign-in switched off for 1')
        ->and(canSignIn('rakesh'))->toBeFalse();

    TenantContext::run($this->meridian, function () use ($rakeshs) {
        $rakesh = meridianEmployee('rakesh');

        // Off whether or not his exit has finished: it still waits on his own answer.
        expect($rakesh->deactivated_at)->not->toBeNull()
            ->and($rakesh->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
            ->and($rakeshs->fresh()->state)->toBe(ProcessCase::Open);

        chandniOpens($rakeshs)
            ->assertSee('Switched off at the end of 23 November 2026')
            ->assertSee('Sign-in switched off at the end of the last working day, 23 November 2026.');

        // His link needs no account, so he can still confirm his handover after he has gone.
        (new CaseEngine)->decideThroughALink(basename((new StepLink)->issue($rakeshs, 4)), 'approved');

        expect(CaseStep::query()->where('case_id', $rakeshs->getKey())->where('sequence', 4)->whereNull('superseded_at')->value('outcome'))->toBe('approved');

        // HR turns it back on by hand, for a day of tidying up.
        $rakesh->active = true;
        $rakesh->save();
    });

    $this->travelTo(onMeridiansWallClock('2026-11-24 00:01'));
    expect(runTheEveryMinutePass())->not->toContain('sign-in switched off')
        ->and(canSignIn('rakesh'))->toBeTrue();

    TenantContext::run($this->meridian, fn () => chandniOpens($rakeshs)
        ->assertSee('Switched off at the end of 23 November 2026, and turned back on since'));
});

it('switches Rakesh off again once a last working day moved later has ended, and says to turn him back on meanwhile', function () {
    $this->travelTo(onMeridiansWallClock('2026-11-24 09:00'));
    runTheEveryMinutePass();

    TenantContext::run($this->meridian, function () {
        $rakeshs = meridianExitAbout('rakesh');

        (new CaseEngine)->amendTheDateTheClocksCountFrom($rakeshs, '2026-11-30', meridianEmployee('chandni'), 'Staying a week to train his successor');

        chandniOpens($rakeshs)->assertSee('Switched off at the end of 23 November 2026, before the last working day moved to 30 November 2026. Turn it back on from the people screen if they are still working.');

        $rakesh = meridianEmployee('rakesh');
        $rakesh->active = true;
        $rakesh->save();

        chandniOpens($rakeshs)->assertSee('Switches off once 30 November 2026 has ended');
    });

    $this->travelTo(onMeridiansWallClock('2026-11-30 12:00'));
    runTheEveryMinutePass();
    expect(canSignIn('rakesh'))->toBeTrue();

    $this->travelTo(onMeridiansWallClock('2026-12-01 09:00'));
    runTheEveryMinutePass();
    expect(canSignIn('rakesh'))->toBeFalse();

    TenantContext::run($this->meridian, fn () => chandniOpens(meridianExitAbout('rakesh'))
        ->assertSee('Sign-in switched off at the end of the last working day, 23 November 2026.')
        ->assertSee('Sign-in switched off at the end of the last working day, 30 November 2026.'));
});

it('leaves Rakesh signed in when HR moves his last working day later in the same minute the pass found it', function () {
    $this->travelTo(onMeridiansWallClock('2026-11-24 09:00'));

    TenantContext::run($this->meridian, function () {
        $owed = (new CaseEngine)->signInsOwedSwitchingOff();

        (new CaseEngine)->amendTheDateTheClocksCountFrom(meridianExitAbout('rakesh'), '2026-11-30', meridianEmployee('chandni'), 'Staying a week to train his successor');

        foreach ($owed as $switchOff) {
            $switchOff();
        }

        expect(meridianEmployee('rakesh')->active)->toBeTrue();
    });
});

it('never switches off sign-in for somebody whose exit was withdrawn, and says nothing about it on the page', function () {
    $deepaks = TenantContext::run($this->meridian, function (): ProcessCase {
        $exit = meridianExitAbout('deepak');
        (new CaseEngine)->cancel($exit, meridianEmployee('chandni'), 'Deepak is staying');

        return $exit;
    });

    $this->travelTo(onMeridiansWallClock('2026-12-08 09:00'));
    runTheEveryMinutePass();

    expect(canSignIn('deepak'))->toBeTrue();

    TenantContext::run($this->meridian, fn () => chandniOpens($deepaks)->assertDontSee('Switches off once')->assertDontSee('Switched off'));
});

it('switches Deepak off on the day while finance is still holding his exit open, and leaves the hold where it is', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = meridianExitAbout('deepak');
        $chandni = meridianEmployee('chandni');

        (new CaseEngine)->claim($deepaks, 3, $chandni);
        (new CaseEngine)->decide($deepaks->fresh(), 3, 'held', $chandni, [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
        ], 'Imprest card not returned');
    });

    $this->travelTo(onMeridiansWallClock('2026-12-08 09:00'));
    runTheEveryMinutePass();

    expect(canSignIn('deepak'))->toBeFalse();

    TenantContext::run($this->meridian, function () {
        $held = CaseStep::query()->where('case_id', meridianExitAbout('deepak')->getKey())->where('sequence', 3)->sole();

        expect(meridianExitAbout('deepak')->state)->toBe(ProcessCase::Open)
            ->and($held->outcome)->toBe('held')
            ->and((int) $held->assignee_id)->toBe((int) meridianEmployee('chandni')->getKey());
    });
});

it('gives Deepak\'s finance clearance back to Chandni, hold and all, when Rakesh held it covering for her and then left', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = meridianEmployee('chandni');
        $priya = meridianEmployee('priya');
        $rakesh = meridianEmployee('rakesh');
        $rakeshs = meridianExitAbout('rakesh');
        $engine = new CaseEngine;

        Succession::handOver($rakeshs, $priya, $chandni);

        // Still covering finance for Chandni after the handover, he holds Deepak's.
        Delegation::factory()->covering($chandni, $rakesh)->create();
        $engine->claim(meridianExitAbout('deepak'), 3, $rakesh);
        $engine->decide(meridianExitAbout('deepak'), 3, 'held', $rakesh, [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
        ], 'Imprest card not returned');

        $engine->decideThroughALink(basename((new StepLink)->issue($rakeshs, 4)), 'approved');

        expect(fn () => (new CaseEngine)->decide($rakeshs->fresh(), 5, 'approved', $priya))
            ->toThrow(ProcessRefused::class, 'since then 1 step they have picked up or put on hold reached them');
    });

    $this->travelTo(onMeridiansWallClock('2026-11-24 09:00'));
    runTheEveryMinutePass();

    TenantContext::run($this->meridian, function () {
        $chandni = meridianEmployee('chandni');
        $deepaks = meridianExitAbout('deepak');
        $held = CaseStep::query()->where('case_id', $deepaks->getKey())->where('sequence', 3)->sole();

        expect((int) $held->assignee_id)->toBe((int) $chandni->getKey())
            ->and($held->outcome)->toBe('held')
            ->and($held->payload['recover_from_them'])->toBe(40000);

        chandniOpens($deepaks)
            ->assertSee('Moved from Rakesh Menon to Chandni Verma on')
            ->assertSee('Why: Rakesh Menon picked it up covering for Chandni Verma, and their sign-in was switched off at the end of their last working day.');

        chandniOpens(meridianExitAbout('rakesh'))
            ->assertSee('Sign-in switched off at the end of the last working day, 23 November 2026. 1 step they had picked up while covering for somebody went back to whoever they covered for.');

        // Nothing of Rakesh's is left, so Priya's final check finishes his exit, and Chandni
        // ends the hold as finance would have.
        (new CaseEngine)->decide(meridianExitAbout('rakesh'), 5, 'approved', meridianEmployee('priya'));
        (new CaseEngine)->resolveHold($deepaks, 3, 'closed_disputed', $chandni, 'Deepak disputes the figure', [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
        ]);

        expect(meridianExitAbout('rakesh')->state)->toBe(ProcessCase::Closed)
            ->and($held->fresh()->outcome)->toBe('closed_disputed');
    });
});

it('gives Chandni back a step Rakesh picked up covering for her when his work is handed over, rather than to his successor', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = meridianEmployee('chandni');
        $rakesh = meridianEmployee('rakesh');
        $deepaks = meridianExitAbout('deepak');

        Delegation::factory()->covering($chandni, $rakesh)->create();
        (new CaseEngine)->claim($deepaks, 3, $rakesh);

        expect(Succession::whatWouldMove($rakesh))->toMatchArray(['steps_picked_up' => 0, 'steps_going_back' => 1]);

        $dialog = chandniOpens(meridianExitAbout('rakesh'))
            ->mountAction('handOverTheirWork')
            ->instance()->getMountedAction()->getModalDescription();

        expect($dialog)->toContain('1 step Rakesh Menon picked up while covering for a colleague goes back to whoever they were covering for.');

        Succession::handOver(meridianExitAbout('rakesh'), meridianEmployee('priya'), $chandni);

        // Given to Priya, it was hers to answer and she could not: it was never her role or
        // her cover, and Chandni was told somebody else had it (module 03's handover).
        $step = CaseStep::query()->where('case_id', $deepaks->getKey())->where('sequence', 3)->sole();

        expect((int) $step->assignee_id)->toBe((int) $chandni->getKey());

        chandniOpens($deepaks)
            ->assertSee('Why: Rakesh Menon picked it up covering for Chandni Verma, and left the company.');

        // And his own exit says where it went, not only Deepak's: in the warning that the
        // handover stays as it is, read off the open dialog.
        chandniOpens(meridianExitAbout('rakesh'))
            ->mountAction('withdraw')
            ->assertSchemaComponentExists('handover', checkComponentUsing: fn (Callout $warning): bool => str_contains(
                (string) $warning->getDescription(),
                '0 steps they had picked up or put on hold. 1 step they had picked up while covering for somebody went back to whoever they covered for.',
            ));

        (new CaseEngine)->decide($deepaks->fresh(), 3, 'approved', $chandni, ['imprest_card_returned' => true]);

        expect($step->fresh()->outcome)->toBe('approved');
    });
});

it('keeps on Rakesh a step he picked up in his own right, and one he picked up covering for Chandni once she has gone too', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = meridianEmployee('chandni');
        $rakesh = meridianEmployee('rakesh');

        // HR head over Shimla, so Deepak's HR clearance is his own to pick up.
        (new CaseEngine)->claim(meridianExitAbout('deepak'), 2, $rakesh);

        Delegation::factory()->covering($chandni, $rakesh)->create();
        (new CaseEngine)->claim(meridianExitAbout('anjali'), 3, $rakesh);

        $chandni->active = false;
        $chandni->save();
    });

    $this->travelTo(onMeridiansWallClock('2026-11-24 09:00'));
    runTheEveryMinutePass();

    TenantContext::run($this->meridian, function () {
        $rakesh = meridianEmployee('rakesh');

        expect($rakesh->active)->toBeFalse()
            ->and(CaseStep::query()->where('case_id', meridianExitAbout('deepak')->getKey())->where('sequence', 2)->value('assignee_id'))->toBe($rakesh->getKey())
            ->and(CaseStep::query()->where('case_id', meridianExitAbout('anjali')->getKey())->where('sequence', 3)->value('assignee_id'))->toBe($rakesh->getKey());
    });
});

it('resolves nothing to Rakesh once he is switched off, so Deepak\'s HR clearance waits on Priya alone', function () {
    $whoHoldsDeepaksHrClearance = fn (): array => TenantContext::run($this->meridian, function (): array {
        $deepaks = meridianExitAbout('deepak');
        $hr = ProcessStep::query()->where('template_id', $deepaks->template_id)->where('sequence', 2)->sole();

        return (new AssigneeResolver)->resolve($deepaks, $hr)->map(fn (User $person): string => $person->name)->sort()->values()->all();
    });

    expect($whoHoldsDeepaksHrClearance())->toBe(['Priya Nair', 'Rakesh Menon']);

    $this->travelTo(onMeridiansWallClock('2026-11-24 09:00'));
    runTheEveryMinutePass();

    expect($whoHoldsDeepaksHrClearance())->toBe(['Priya Nair']);
});

it('switches off Priya, one of the two administrators, on her day, and Meridian still has two once her work was handed over', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = meridianEmployee('chandni');

        $priyas = (new CaseEngine)->open(meridianExitAbout('deepak')->template, meridianEmployee('priya'), $chandni, '2026-11-20', 'resignation');
        Succession::handOver($priyas, meridianEmployee('rakesh'), $chandni);
    });

    $this->travelTo(onMeridiansWallClock('2026-11-21 09:00'));
    runTheEveryMinutePass();

    expect(canSignIn('priya'))->toBeFalse()
        ->and(TenantContext::run($this->meridian, fn (): int => AdministratorFloor::count()))->toBe(2);
});

it('never switches Deepak off once Priya has turned his exit down, and says nothing about it on the page', function () {
    TenantContext::run($this->meridian, function () {
        (new CaseEngine)->decide(meridianExitAbout('deepak'), 2, 'rejected', meridianEmployee('priya'), [], 'We talked him round and he is staying');

        chandniOpens(meridianExitAbout('deepak'))->assertDontSee('Switches off once');
    });

    $this->travelTo(onMeridiansWallClock('2026-12-08 09:00'));
    runTheEveryMinutePass();

    expect(canSignIn('deepak'))->toBeTrue();
});

it('still switches Rakesh off when he turned his own exit down from his link', function () {
    TenantContext::run($this->meridian, function () {
        $rakeshs = meridianExitAbout('rakesh');

        (new CaseEngine)->decideThroughALink(basename((new StepLink)->issue($rakeshs, 4)), 'rejected');

        chandniOpens($rakeshs)->assertSee('Switches off once 23 November 2026 has ended');
    });

    $this->travelTo(onMeridiansWallClock('2026-11-24 09:00'));
    runTheEveryMinutePass();

    expect(canSignIn('rakesh'))->toBeFalse();
});

it('leaves Deepak signed in when Priya turns his exit down in the same minute the pass found it', function () {
    $this->travelTo(onMeridiansWallClock('2026-12-08 09:00'));

    TenantContext::run($this->meridian, function () {
        $owed = (new CaseEngine)->signInsOwedSwitchingOff();
        expect($owed)->not->toBeEmpty();

        (new CaseEngine)->decide(meridianExitAbout('deepak'), 2, 'rejected', meridianEmployee('priya'), [], 'We talked him round and he is staying');

        foreach ($owed as $switchOff) {
            $switchOff();
        }

        expect(meridianEmployee('deepak')->active)->toBeTrue();
    });
});

it('says sign-in is already off on Deepak\'s exit when HR switched him off by hand before his day', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = meridianEmployee('deepak');
        $deepak->active = false;
        $deepak->save();

        chandniOpens(meridianExitAbout('deepak'))
            ->assertSee('Already switched off')
            ->assertDontSee('Switches off once');
    });
});
