<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Exceptions\ProcessRefused;
use App\Filament\Pages\RaiseARequest;
use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\ProcessCase;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\Succession;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TemplateCatalogue;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/*
| Module 07 step 2: withdrawing an exit, and changing its last working day.
|
| HR does both from the exit's own page, over the part of the company they look after.
| The leaver may withdraw their own resignation from the raise screen until anybody has
| acted on it, because the exit's page is not theirs to read. Everybody holding an open
| step, the leaver and whoever raised it are told, and the person who pressed is not.
|
| Run against the demo as it is seeded: Deepak's exit was raised by Chandni and approved
| by Rakesh, his manager, so HR (Rakesh and Priya over Shimla), finance (Chandni) and his
| own handover confirmation are open on it.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

function whoChangesExits(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

function theRunningExitOf(User $person): ProcessCase
{
    return ProcessCase::query()->where('subject_user_id', $person->getKey())
        ->whereNotNull('statutory_from')->whereNull('closed_at')->whereNull('cancelled_at')->sole();
}

/**
 * Who was sent one message about a case, by first name, with the address it went to.
 *
 * @return Collection<int, string>
 */
function whoWasSent(ProcessCase $case, string $key): Collection
{
    return NotificationDelivery::query()->where('case_id', $case->getKey())->where('template_key', $key)
        ->with('recipient')->get()
        ->map(fn (NotificationDelivery $sent): string => $sent->recipient->first_name.' '.$sent->recipient_address)
        ->sort()->values();
}

/** Deepak's seeded exit withdrawn, and his own resignation raised in its place. */
function deepaksOwnResignation(): ProcessCase
{
    $deepak = whoChangesExits('deepak');

    (new CaseEngine)->cancel(theRunningExitOf($deepak), whoChangesExits('chandni'), 'Raised again by Deepak');

    return (new CaseEngine)->open(
        ProcessTemplate::query()->where('key', 'exit')->where('status', ProcessTemplate::Published)->sole(),
        $deepak, $deepak, now()->addDays(30)->toDateString(), 'resignation',
    );
}

/*
| Withdrawing
*/

it('lets HR for the branch withdraw an exit, keeps what was done, and tells everybody working on it', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = theRunningExitOf(whoChangesExits('deepak'));

        Livewire::actingAs(whoChangesExits('rakesh'))->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->assertActionVisible('withdraw')
            ->callAction('withdraw', ['reason' => 'Staying after a counter-offer'])
            ->assertHasNoActionErrors()
            ->assertNotified('Exit withdrawn')
            ->assertSee('Withdrawn by Rakesh Menon')
            ->assertSee('Why: Staying after a counter-offer')
            ->assertActionHidden('withdraw')
            ->assertActionHidden('changeLastWorkingDay');

        $deepaks->refresh();

        // Withdrawn, with Rakesh's approval still on the record, and never finished after.
        expect($deepaks->state)->toBe(ProcessCase::Cancelled)
            ->and(CaseStep::query()->where('case_id', $deepaks->getKey())->where('sequence', 1)->sole()->outcome)->toBe('approved');

        expect(fn () => (new CaseEngine)->decide($deepaks, 3, 'approved', whoChangesExits('chandni')))
            ->toThrow(ProcessRefused::class, 'This case is cancelled');

        // Deepak at his own address, with the reason. HR clearance's other holder, and
        // Chandni once although she both raised it and holds finance. Not Rakesh, who
        // pressed it.
        expect(whoWasSent($deepaks, TemplateCatalogue::CaseWithdrawnSubject)->all())->toBe(['Deepak deepak@personal.example'])
            ->and(whoWasSent($deepaks, TemplateCatalogue::CaseWithdrawnWorkingOnIt)->all())->toBe([
                'Chandni chandni@meridian.test',
                'Priya priya@meridian.test',
            ])
            ->and(NotificationDelivery::query()->where('template_key', TemplateCatalogue::CaseWithdrawnSubject)->sole()->body)
            ->toContain('Rakesh Menon has withdrawn your Exit')
            ->toContain('Staying after a counter-offer');
    });
});

it('keeps both buttons off another branch\'s exits and off your own, and the engine refuses them too', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = whoChangesExits('rakesh');
        $rohits = theRunningExitOf(whoChangesExits('rohit'));

        // Rakesh is HR head over Shimla, and Rohit works in Pune. The page itself is shut
        // to him, and the engine refuses him by any other door.
        expect(fn () => (new CaseEngine)->cancel($rohits, $rakesh, 'Not his to withdraw'))
            ->toThrow(ProcessRefused::class, 'Only somebody who looks after exits for that part of the company');

        // A client who takes the action off their HR head role: Rakesh still reads
        // Deepak's exit, and is offered neither button.
        Role::query()->where('key', 'hr_head')->sole()->permissions()->where('permission', Permission::ChangeExit)->delete();
        app(PermissionResolver::class)->forget();

        Livewire::actingAs($rakesh)->test(ViewCase::class, ['record' => theRunningExitOf(whoChangesExits('deepak'))->getKey()])
            ->assertOk()
            ->assertActionHidden('withdraw')
            ->assertActionHidden('changeLastWorkingDay');

        // His own exit, which his HR head role over Shimla lets him read.
        Livewire::actingAs($rakesh)->test(ViewCase::class, ['record' => theRunningExitOf($rakesh)->getKey()])
            ->assertActionHidden('withdraw')
            ->assertActionHidden('changeLastWorkingDay');

        expect(fn () => (new CaseEngine)->amendTheDateTheClocksCountFrom(theRunningExitOf($rakesh), now()->addDays(60)->toDateString(), $rakesh, 'Staying longer'))
            ->toThrow(ProcessRefused::class, 'not yours to do when the case is about you');

        // Chandni looks after the whole company.
        Livewire::actingAs(whoChangesExits('chandni'))->test(ViewCase::class, ['record' => $rohits->getKey()])
            ->assertActionVisible('withdraw')
            ->assertActionVisible('changeLastWorkingDay');

        expect($rohits->fresh()->state)->toBe(ProcessCase::Open);
    });
});

it('lets Deepak withdraw his own resignation from the raise screen until anybody acts on it', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoChangesExits('deepak');
        $own = deepaksOwnResignation();

        Livewire::actingAs($deepak)->test(RaiseARequest::class)
            ->set('processId', $own->template_id)
            // His open exit, in place of a second one.
            ->assertSee('Your exit is open.')
            ->assertSee($own->statutory_from->format('j F Y'))
            ->assertSee('Withdraw my exit')
            ->assertDontSee('Raise the exit')
            ->call('withdrawTheirOwn')
            ->assertHasErrors(['withdrawalReason' => 'required'])
            ->set('withdrawalReason', 'Changed my mind')
            ->call('withdrawTheirOwn')
            ->assertHasNoErrors()
            ->assertNotified('Your exit is withdrawn.')
            ->assertSet('processId', null);

        expect($own->fresh()->state)->toBe(ProcessCase::Cancelled)
            ->and((int) $own->fresh()->cancelled_by)->toBe((int) $deepak->getKey());

        // Rakesh, whose approval it was waiting on, is told. Deepak pressed it himself.
        expect(whoWasSent($own, TemplateCatalogue::CaseWithdrawnWorkingOnIt)->all())->toBe(['Rakesh rakesh@meridian.test'])
            ->and(whoWasSent($own, TemplateCatalogue::CaseWithdrawnSubject)->all())->toBe([]);
    });
});

it('tells Deepak only HR can withdraw his resignation once his manager has answered', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoChangesExits('deepak');
        $own = deepaksOwnResignation();

        (new CaseEngine)->decide($own, 1, 'approved', whoChangesExits('rakesh'));

        $page = Livewire::actingAs($deepak)->test(RaiseARequest::class)
            ->set('processId', $own->template_id)
            ->assertSee('Only HR can withdraw it or change your last working day now.')
            ->assertDontSee('Withdraw my exit');

        // Pressed from past the screen, the engine refuses it in the same words.
        $page->set('withdrawalReason', 'Changed my mind')
            ->call('withdrawTheirOwn')
            ->assertNotified('Somebody has already acted on your exit, or it is not your resignation, so only HR can withdraw it now.');

        expect($own->fresh()->state)->toBe(ProcessCase::Open);
    });
});

it('never lets the leaver withdraw an exit that is not their resignation', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = whoChangesExits('deepak');
        $chandni = whoChangesExits('chandni');

        (new CaseEngine)->cancel(theRunningExitOf($deepak), $chandni, 'Raised again');

        // Nobody has acted on it, and it is still not his to take back.
        $dismissal = (new CaseEngine)->open(
            ProcessTemplate::query()->where('key', 'exit')->where('status', ProcessTemplate::Published)->sole(),
            $deepak, $chandni, now()->addDays(30)->toDateString(), 'dismissal',
        );

        expect(fn () => (new CaseEngine)->cancel($dismissal, $deepak, 'I would rather stay'))
            ->toThrow(ProcessRefused::class, 'only HR can withdraw it now');

        expect($dismissal->fresh()->state)->toBe(ProcessCase::Open);
    });
});

it('refuses to withdraw an exit once the last working day has passed', function () {
    $deepaks = TenantContext::run($this->meridian, fn () => theRunningExitOf(whoChangesExits('deepak')));

    $this->travelTo($deepaks->statutory_from->addDay()->setTime(9, 0));

    TenantContext::run($this->meridian, function () use ($deepaks) {
        Livewire::actingAs(whoChangesExits('rakesh'))->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->callAction('withdraw', ['reason' => 'Too late'])
            ->assertActionHalted('withdraw')
            ->assertNotified('This exit was not withdrawn');

        expect(fn () => (new CaseEngine)->cancel($deepaks, whoChangesExits('chandni'), 'Too late'))
            ->toThrow(ProcessRefused::class, "Deepak Iyer's last working day, ".$deepaks->statutory_from->format('j F Y').', has passed');

        expect($deepaks->fresh()->state)->toBe(ProcessCase::Open);
    });
});

it('warns before withdrawing an exit whose work has been handed over, and withdraws it anyway', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoChangesExits('chandni');
        $deepaks = theRunningExitOf(whoChangesExits('deepak'));

        Succession::handOver($deepaks, whoChangesExits('priya'), $chandni, now()->toDateString());

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->mountAction('withdraw')
            // Read off the open dialog: its contents are drawn in the browser, not in the
            // page's own response.
            ->assertSchemaComponentExists('handover', checkComponentUsing: fn (Callout $warning): bool => $warning->isVisible()
                && str_contains((string) $warning->getDescription(), 'Priya Nair took it on from')
                && str_contains((string) $warning->getDescription(), 'That stays as it is.'))
            ->fillForm(['reason' => 'Staying after all'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        expect($deepaks->fresh()->state)->toBe(ProcessCase::Cancelled);
    });
});

/*
| Changing the last working day
*/

it('moves the last working day from the exit page, shows the new deadline first, and tells everybody working on it', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = theRunningExitOf(whoChangesExits('deepak'));
        $later = CarbonImmutable::parse($deepaks->statutory_from)->addWeeks(2)->next(CarbonImmutable::FRIDAY);

        Livewire::actingAs(whoChangesExits('chandni'))->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->mountAction('changeLastWorkingDay')
            ->assertSchemaStateSet([
                'last_working_day' => $deepaks->statutory_from->toDateString(),
                'employment_ended_by' => 'resignation',
            ])
            ->fillForm(['last_working_day' => $later->toDateString()])
            // A Friday, so two working days later is the Tuesday.
            ->assertSchemaComponentExists('deadlines', checkComponentUsing: fn (Text $said): bool => str_starts_with(
                (string) $said->getContent(), 'Settlement would be due by '.$later->addDays(4)->format('j F Y').'. Gratuity: not owed.'
            ))
            ->fillForm(['reason' => 'Notice extended by two weeks'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Last working day changed')
            ->assertSee('Last working day moved from '.$deepaks->statutory_from->format('j F Y').' to '.$later->format('j F Y').' by Chandni Verma');

        expect($deepaks->fresh()->statutory_from->toDateString())->toBe($later->toDateString())
            ->and($deepaks->fresh()->statutory_due_at->toDateString())->toBe($later->addDays(4)->toDateString());

        // Deepak, and both HR clearance holders. Not Chandni, who pressed it and also
        // raised it and holds finance.
        expect(whoWasSent($deepaks, TemplateCatalogue::LastDayMovedSubject)->all())->toBe(['Deepak deepak@personal.example'])
            ->and(whoWasSent($deepaks, TemplateCatalogue::LastDayMovedWorkingOnIt)->all())->toBe([
                'Priya priya@meridian.test',
                'Rakesh rakesh@meridian.test',
            ])
            ->and(NotificationDelivery::query()->where('template_key', TemplateCatalogue::LastDayMovedSubject)->sole()->body)
            ->toContain('to '.$later->format('j F Y'))
            ->toContain('now due by '.$later->addDays(4)->format('j F Y'))
            ->toContain('Notice extended by two weeks');
    });
});

it('says plainly when the new date gives a deadline that has already passed, and saves it', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = theRunningExitOf(whoChangesExits('deepak'));
        $absconded = now()->subWeeks(2)->toDateString();

        Livewire::actingAs(whoChangesExits('chandni'))->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->mountAction('changeLastWorkingDay')
            ->fillForm(['last_working_day' => $absconded])
            ->assertSchemaComponentExists('deadlines', checkComponentUsing: fn (Text $said): bool => str_contains((string) $said->getContent(), 'That date has already passed.'))
            ->fillForm(['reason' => 'Stopped coming in two weeks ago'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        expect($deepaks->fresh()->statutory_from->toDateString())->toBe($absconded);
    });
});

it('corrects how the employment is ending without telling anybody when the date stays', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = theRunningExitOf(whoChangesExits('deepak'));

        expect($deepaks->gratuity_due_at)->toBeNull();

        Livewire::actingAs(whoChangesExits('chandni'))->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->callAction('changeLastWorkingDay', [
                'employment_ended_by' => 'fixed_term_ended',
                'reason' => 'He was on a contract, entered as a resignation',
            ])
            ->assertHasNoActionErrors()
            ->assertSee('How their employment ends changed from Resignation to End of a fixed-term contract by Chandni Verma');

        // A contract that simply ends is owed gratuity whatever the service.
        expect($deepaks->fresh()->employment_ended_by)->toBe('fixed_term_ended')
            ->and($deepaks->fresh()->gratuity_due_at)->not->toBeNull()
            ->and(whoWasSent($deepaks, TemplateCatalogue::LastDayMovedSubject)->all())->toBe([])
            ->and(whoWasSent($deepaks, TemplateCatalogue::LastDayMovedWorkingOnIt)->all())->toBe([]);
    });
});

it('says nothing changed when the same answers are saved again', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = theRunningExitOf(whoChangesExits('deepak'));

        Livewire::actingAs(whoChangesExits('chandni'))->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->callAction('changeLastWorkingDay', ['reason' => 'Checking'])
            ->assertNotified('Nothing changed');

        expect(CaseEvent::query()->where('case_id', $deepaks->getKey())->where('type', 'case_amended')->exists())->toBeFalse();
    });
});

it('gives an exit opened with no date one, and refuses in a sentence when that makes a second open exit', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoChangesExits('chandni');
        $priya = whoChangesExits('priya');

        // The demo's exit built with the mistake was opened for Priya with no date, so it
        // never counted as her open exit, and her real one can be raised beside it.
        $undated = ProcessCase::query()->where('subject_user_id', $priya->getKey())->whereNull('statutory_from')->sole();

        (new CaseEngine)->open(
            ProcessTemplate::query()->where('key', 'exit')->where('status', ProcessTemplate::Published)->sole(),
            $priya, $chandni, now()->addDays(30)->toDateString(), 'resignation',
        );

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $undated->getKey()])
            ->callAction('changeLastWorkingDay', [
                'last_working_day' => now()->addDays(20)->toDateString(),
                'employment_ended_by' => 'resignation',
                'reason' => 'Giving it a date',
            ])
            ->assertActionHalted('changeLastWorkingDay')
            ->assertNotified('The last working day was not changed');

        expect(fn () => (new CaseEngine)->amendTheDateTheClocksCountFrom($undated, now()->addDays(20)->toDateString(), $chandni, 'Giving it a date', 'resignation'))
            ->toThrow(ProcessRefused::class, 'Priya Nair already has an exit open.');

        expect($undated->fresh()->statutory_from)->toBeNull();
    });
});

/*
| Existing companies
*/

it('gives existing administrators and HR heads the new action and existing companies the four messages', function () {
    $keys = [
        TemplateCatalogue::LastDayMovedSubject, TemplateCatalogue::LastDayMovedWorkingOnIt,
        TemplateCatalogue::CaseWithdrawnSubject, TemplateCatalogue::CaseWithdrawnWorkingOnIt,
    ];

    // Meridian wound back to how it was before this step.
    TenantContext::run($this->meridian, function () use ($keys) {
        Role::query()->get()->each(fn (Role $role) => $role->permissions()->where('permission', Permission::ChangeExit)->delete());
        NotificationTemplate::query()->whereIn('key', $keys)->delete();
    });

    (require database_path('migrations/2026_11_20_120000_grant_changing_an_exit_and_word_its_four_messages.php'))->up();

    app(PermissionResolver::class)->forget();

    TenantContext::run($this->meridian, function () use ($keys) {
        $resolver = app(PermissionResolver::class);

        expect($resolver->allows(whoChangesExits('chandni'), Permission::ChangeExit))->toBeTrue()
            ->and($resolver->allows(whoChangesExits('rakesh'), Permission::ChangeExit))->toBeTrue()
            // Finance clears an exit; it does not withdraw one.
            ->and(Role::query()->where('key', 'finance_head')->sole()->permissions()->where('permission', Permission::ChangeExit)->exists())->toBeFalse()
            ->and(NotificationTemplate::query()->whereIn('key', $keys)->count())->toBe(4);
    });
});
