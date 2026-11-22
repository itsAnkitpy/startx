<?php

use App\Authorization\AdministratorFloor;
use App\Exceptions\ProcessRefused;
use App\Filament\Pages\MyQueue;
use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Models\CaseStep;
use App\Models\Delegation;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Succession;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Process\StepLink;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Filament\Schemas\Components\Callout;
use Livewire\Livewire;

/*
| Module 07 step 4: nobody leaves with work still on them.
|
| HR's final check cannot be answered while the leaver still holds a role, has anybody
| reporting to them, has a step picked up or on hold, or is the one a step nobody holds
| falls to — the four things "Hand over their work" moves. It holds the case, never the
| person.
|
| Rakesh's seeded exit is cleared by everybody who works here and waits on his own link.
| He holds HR head and line-of-business head over Shimla, and Deepak and Anjali report to
| him, so once he answers it, Priya's final check is the one that would finish it.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

function hereAtMeridian(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

function theOpenExitOf(string $first): ProcessCase
{
    return ProcessCase::query()->where('subject_user_id', hereAtMeridian($first)->getKey())
        ->whereNull('closed_at')->whereNull('cancelled_at')->sole();
}

/** The leaver answers their own clearance through the link sent to them. */
function answerTheLeaversLink(ProcessCase $exit, int $sequence): void
{
    (new CaseEngine)->decideThroughALink(basename((new StepLink)->issue($exit, $sequence)), 'approved');
}

/** Chandni, an administrator, hands somebody's work to somebody else from their exit's page. */
function chandniHandsTheirWorkTo(ProcessCase $exit, User $successor): void
{
    Livewire::actingAs(hereAtMeridian('chandni'))->test(ViewCase::class, ['record' => $exit->getKey()])
        ->callAction('handOverTheirWork', [
            'successor' => $successor->getKey(),
            'effective_at' => CarbonImmutable::now()->toDateString(),
        ])
        ->assertHasNoActionErrors();
}

it('refuses Priya\'s final check on Rakesh\'s exit while his work is on him, and lets it finish once it is handed over', function () {
    TenantContext::run($this->meridian, function () {
        $rakeshs = theOpenExitOf('rakesh');
        $priya = hereAtMeridian('priya');
        $before = $rakeshs->only(['statutory_from', 'statutory_due_at', 'gratuity_due_at']);

        answerTheLeaversLink($rakeshs, 4);

        Livewire::actingAs($priya)->test(MyQueue::class)
            ->call('decide', $rakeshs->getKey(), 5, 'approved')
            ->assertNotified('Rakesh Menon still has 2 roles and 2 people reporting to them, and all of it has to be handed over before this exit can finish. Press "Hand over their work" on this exit\'s page, or ask somebody who can.');

        // Nothing of the answer was kept, and the person's own dates did not move.
        $rakeshs->refresh();
        expect($rakeshs->state)->toBe(ProcessCase::Open)
            ->and(CaseStep::query()->where('case_id', $rakeshs->getKey())->where('sequence', 5)->whereNotNull('outcome')->exists())->toBeFalse()
            ->and($rakeshs->only(['statutory_from', 'statutory_due_at', 'gratuity_due_at']))->toEqual($before);

        chandniHandsTheirWorkTo($rakeshs, $priya);

        Livewire::actingAs($priya)->test(MyQueue::class)
            ->call('decide', $rakeshs->getKey(), 5, 'approved')
            ->assertNotified('Recorded.');

        expect($rakeshs->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

it('finishes an exit with nothing to hand over as it does today', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = theOpenExitOf('deepak');
        $engine = new CaseEngine;

        $engine->decide($deepaks, 2, 'approved', hereAtMeridian('rakesh'), ['id_card_returned' => true]);
        $engine->decide($deepaks->fresh(), 3, 'approved', hereAtMeridian('chandni'), ['imprest_card_returned' => true]);
        answerTheLeaversLink($deepaks->fresh(), 4);
        $engine->decide($deepaks->fresh(), 5, 'approved', hereAtMeridian('rakesh'));

        expect($deepaks->fresh()->state)->toBe(ProcessCase::Closed)
            ->and(Succession::query()->count())->toBe(0);
    });
});

it('holds Priya\'s exit while she is one of the two administrators, and finishes it once Administrator has moved on', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = hereAtMeridian('chandni');
        $rakesh = hereAtMeridian('rakesh');
        $engine = new CaseEngine;

        $priyas = $engine->open(theOpenExitOf('deepak')->template, hereAtMeridian('priya'), $chandni, now()->addDays(20)->toDateString(), 'resignation');
        $engine->decide($priyas, 1, 'approved', $chandni);
        $engine->decide($priyas->fresh(), 2, 'approved', $rakesh, ['id_card_returned' => true]);
        $engine->decide($priyas->fresh(), 3, 'approved', $chandni, ['imprest_card_returned' => true]);
        answerTheLeaversLink($priyas->fresh(), 4);

        // Administrator over the whole company and HR head over Shimla.
        expect(fn () => $engine->decide($priyas->fresh(), 5, 'approved', $rakesh))
            ->toThrow(ProcessRefused::class, 'Priya Nair still has 2 roles, and all of it has to be handed over');

        // Rakesh is not an administrator yet, so the role moves to him and the company
        // keeps two.
        chandniHandsTheirWorkTo($priyas, $rakesh);

        $engine->decide($priyas->fresh(), 5, 'approved', $rakesh);

        expect($priyas->fresh()->state)->toBe(ProcessCase::Closed)
            ->and(AdministratorFloor::count())->toBe(2)
            ->and($rakesh->roleAssignments()->whereRelation('role', 'key', Role::AdministratorKey)->exists())->toBeTrue();
    });
});

it('moves a clearance the leaver is holding to her successor, hold and all, so the successor ends it', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = hereAtMeridian('chandni');
        $rakesh = hereAtMeridian('rakesh');
        $engine = new CaseEngine;
        $deepaks = theOpenExitOf('deepak');

        $engine->claim($deepaks, 3, $chandni);
        $engine->decide($deepaks->fresh(), 3, 'held', $chandni, ['imprest_card_returned' => false, 'recover_from_them' => 40000], 'Imprest card not returned');

        $chandnis = $engine->open($deepaks->template, $chandni, hereAtMeridian('priya'), now()->addDays(20)->toDateString(), 'resignation');

        expect(Succession::whatStillHasToMove($chandnis))->toContain('1 step they have picked up or put on hold');

        Succession::handOver($chandnis, $rakesh, hereAtMeridian('priya'));

        $held = CaseStep::query()->where('case_id', $deepaks->getKey())->where('sequence', 3)->sole();

        expect($held->assignee_id)->toBe($rakesh->getKey())
            ->and($held->outcome)->toBe('held')
            ->and($held->payload['recover_from_them'])->toEqual(40000)
            ->and((new AvailableSteps)->waitingOn($rakesh)->contains(
                fn ($waiting) => $waiting->case->is($deepaks) && $waiting->step->sequence === 3
            ))->toBeTrue();

        // His to end now, as finance would have. A fresh engine, as every request gets: one
        // remembers who held each role for as long as it lives.
        (new CaseEngine)->resolveHold($deepaks->fresh(), 3, 'closed_disputed', $rakesh, 'Deepak disputes the figure');

        expect($held->fresh()->outcome)->toBe('closed_disputed');
    });
});

it('hands the one a step nobody holds falls to over to the successor, and says so before it does', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = hereAtMeridian('chandni');
        $rakesh = hereAtMeridian('rakesh');
        $priya = hereAtMeridian('priya');

        $chandnis = (new CaseEngine)->open(theOpenExitOf('deepak')->template, $chandni, $priya, now()->addDays(20)->toDateString(), 'resignation');

        expect(Succession::whatStillHasToMove($chandnis))->toContain('and the steps nobody else holds');

        $page = Livewire::actingAs($priya)->test(ViewCase::class, ['record' => $chandnis->getKey()])
            ->mountAction('handOverTheirWork');

        expect($page->instance()->getMountedAction()->getModalDescription())
            ->toContain('They also become the one who picks up a step nobody holds.');

        Succession::handOver($chandnis, $rakesh, $priya);

        // Nobody holds HR head over Pune, so Rohit's HR clearance is the step it falls to.
        expect(app(Settings::class)->get(AssigneeResolver::StandInSetting))->toBe($rakesh->getKey())
            ->and((new AvailableSteps)->waitingOn($rakesh)->contains(
                fn ($waiting) => $waiting->case->is(theOpenExitOf('rohit')) && $waiting->step->name === 'HR clearance'
            ))->toBeTrue()
            ->and(Succession::whatStillHasToMove($chandnis->fresh()))->toBeNull();

        // And withdrawing her exit afterwards says the setting moved too, and where to put
        // it back.
        Livewire::actingAs($priya)->test(ViewCase::class, ['record' => $chandnis->getKey()])
            ->mountAction('withdraw')
            ->assertSchemaComponentExists('handover', checkComponentUsing: fn (Callout $warning): bool => $warning->isVisible()
                && str_contains((string) $warning->getDescription(), 'They also became the one who picks up a step nobody holds.')
                && str_contains((string) $warning->getDescription(), 'and who picks up a step nobody holds on the settings screen.'));
    });
});

it('hands Rakesh\'s work over again on a later exit, after an earlier one was withdrawn once it had moved', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = hereAtMeridian('rakesh');
        $chandni = hereAtMeridian('chandni');
        $priya = hereAtMeridian('priya');
        $first = theOpenExitOf('rakesh');

        chandniHandsTheirWorkTo($first, $priya);
        (new CaseEngine)->cancel($first->fresh(), $chandni, 'He stays after all');

        // Staying, he is given a role back by hand. Months later he resigns for real.
        $director = RoleAssignment::query()->create([
            'role_id' => Role::query()->where('key', 'director')->sole()->getKey(),
            'user_id' => $rakesh->getKey(),
            'org_unit_id' => null,
            'includes_descendants' => false,
        ]);

        $second = (new CaseEngine)->open($first->template, $rakesh, $chandni, now()->addDays(20)->toDateString(), 'resignation');

        expect(Succession::whatStillHasToMove($second))
            ->toStartWith('Rakesh Menon still has 1 role, and all of it has to be handed over before this exit can finish. Press "Hand over their work"');

        chandniHandsTheirWorkTo($second, $priya);

        expect(Succession::query()->where('case_id', $second->getKey())->exists())->toBeTrue()
            ->and($director->fresh()->user_id)->toBe($priya->getKey())
            ->and(Succession::whatStillHasToMove($second->fresh()))->toBeNull();
    });
});

it('does not hold Rakesh\'s exit for a step he picked up on an exit that was then withdrawn', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = hereAtMeridian('rakesh');
        $chandni = hereAtMeridian('chandni');
        $rakeshs = theOpenExitOf('rakesh');
        $deepaks = theOpenExitOf('deepak');

        chandniHandsTheirWorkTo($rakeshs, hereAtMeridian('priya'));

        // Covering finance for Chandni, he picks up Deepak's finance clearance, and then
        // Deepak decides to stay. Nobody can answer a step on a withdrawn exit.
        Delegation::factory()->covering($chandni, $rakesh)->create();
        (new CaseEngine)->claim($deepaks, 3, $rakesh);
        (new CaseEngine)->cancel($deepaks->fresh(), $chandni, 'Deepak stays');

        answerTheLeaversLink($rakeshs->fresh(), 4);
        (new CaseEngine)->decide($rakeshs->fresh(), 5, 'approved', hereAtMeridian('priya'));

        expect($rakeshs->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

it('finishes an exit for somebody whose only role is the starter Employee role, which grants nothing', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = theOpenExitOf('deepak');
        $engine = new CaseEngine;

        // Every company created from the platform screen gets it; Meridian's demo does not.
        RoleAssignment::query()->create([
            'role_id' => Role::factory()->create(['key' => Role::EmployeeKey, 'name' => 'Employee'])->getKey(),
            'user_id' => hereAtMeridian('deepak')->getKey(),
            'org_unit_id' => null,
            'includes_descendants' => false,
        ]);

        $engine->decide($deepaks, 2, 'approved', hereAtMeridian('rakesh'), ['id_card_returned' => true]);
        $engine->decide($deepaks->fresh(), 3, 'approved', hereAtMeridian('chandni'), ['imprest_card_returned' => true]);
        answerTheLeaversLink($deepaks->fresh(), 4);
        $engine->decide($deepaks->fresh(), 5, 'approved', hereAtMeridian('rakesh'));

        expect($deepaks->fresh()->state)->toBe(ProcessCase::Closed);
    });
});

it('still refuses after the handover when work has reached the leaver since, and says where to move it by hand', function () {
    TenantContext::run($this->meridian, function () {
        $rakeshs = theOpenExitOf('rakesh');
        $priya = hereAtMeridian('priya');

        chandniHandsTheirWorkTo($rakeshs, $priya);

        // Given a role again by mistake, after his work had gone.
        RoleAssignment::query()->create([
            'role_id' => Role::query()->where('key', 'director')->sole()->getKey(),
            'user_id' => hereAtMeridian('rakesh')->getKey(),
            'org_unit_id' => null,
            'includes_descendants' => false,
        ]);

        answerTheLeaversLink($rakeshs->fresh(), 4);

        expect(fn () => (new CaseEngine)->decide($rakeshs->fresh(), 5, 'approved', $priya))
            ->toThrow(ProcessRefused::class, "Rakesh Menon's work was handed to Priya Nair on ".now()->format('j F Y').', and since then 1 role reached them. That has to move on before this exit can finish: roles on the roles screen');

        // And the button is not offered a second time.
        Livewire::actingAs(hereAtMeridian('chandni'))->test(ViewCase::class, ['record' => $rakeshs->getKey()])
            ->assertActionHidden('handOverTheirWork')
            ->assertSee('since then 1 role reached them');
    });
});

it('refuses the answer that would finish an exit through the leaver\'s link and through either way out of a hold', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = hereAtMeridian('chandni');
        $engine = new CaseEngine;

        // An exit whose last answer can arrive through a link or a hold, which Meridian's
        // own cannot, because its last step is HR's final check alone.
        $exit = ProcessTemplate::factory()->named('short_exit', 'Short exit')->about('employee')->create();
        ProcessStep::factory()->of($exit)->at(1, 1)->named('Finance clearance')->heldBy('chandni@meridian.test')->offering('approved', 'held')->create();
        ProcessStep::factory()->of($exit)->at(2, 1)->named('Leaver confirms')->external()->offering('approved')->create();
        $exit->publish();

        // Rakesh's seeded exit stepped out of the way: a person has one open exit at a time.
        $engine->cancel(theOpenExitOf('rakesh'), $chandni, 'Raised again on the short exit');
        $rakeshs = $engine->open($exit->fresh(), hereAtMeridian('rakesh'), $chandni, now()->addDays(20)->toDateString(), 'resignation');

        // Finance answered, so the link is the last answer.
        $engine->decide($rakeshs, 1, 'approved', $chandni);

        expect(fn () => answerTheLeaversLink($rakeshs->fresh(), 2))
            ->toThrow(ProcessRefused::class, 'Rakesh Menon still has 2 roles');

        // Finance held instead, the link answered, so ending the hold is the last answer.
        $engine->cancel($rakeshs->fresh(), $chandni, 'Raised again');
        $again = $engine->open($exit->fresh(), hereAtMeridian('rakesh'), $chandni, now()->addDays(20)->toDateString(), 'resignation');
        $engine->claim($again, 1, $chandni);
        $engine->decide($again->fresh(), 1, 'held', $chandni, [], 'Imprest card not returned');
        answerTheLeaversLink($again->fresh(), 2);

        expect(fn () => $engine->resolveHold($again->fresh(), 1, 'closed_disputed', $chandni, 'Still disputed'))
            ->toThrow(ProcessRefused::class, 'Rakesh Menon still has 2 roles')
            ->and(fn () => $engine->resolveHold($again->fresh(), 1, 'force_closed', hereAtMeridian('priya'), 'Chandni is away'))
            ->toThrow(ProcessRefused::class, 'Rakesh Menon still has 2 roles')
            ->and(CaseStep::query()->where('case_id', $again->getKey())->where('sequence', 1)->sole()->outcome)->toBe('held')
            ->and($again->fresh()->state)->toBe(ProcessCase::Open);
    });
});

it('says on the exit\'s page that it is waiting on a handover, and stops saying it once the work has moved', function () {
    TenantContext::run($this->meridian, function () {
        $rakeshs = theOpenExitOf('rakesh');
        $chandni = hereAtMeridian('chandni');

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $rakeshs->getKey()])
            ->assertSee('Waiting on a handover')
            ->assertSee('Rakesh Menon still has 2 roles and 2 people reporting to them');

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => theOpenExitOf('deepak')->getKey()])
            ->assertDontSee('Waiting on a handover');

        chandniHandsTheirWorkTo($rakeshs, hereAtMeridian('priya'));

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $rakeshs->getKey()])
            ->assertDontSee('Waiting on a handover');
    });
});
