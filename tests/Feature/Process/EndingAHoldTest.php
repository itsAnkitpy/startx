<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Exceptions\ProcessRefused;
use App\Filament\Pages\MyQueue;
use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TemplateCatalogue;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Livewire\Livewire;

/*
| Module 07 step 3: the two ways out of a held clearance.
|
| Chandni holds finance on every Meridian exit. Holding one leaves it open and stops HR's
| final check, so it needs a way out that never says finance approved. She closes her own
| hold as disputed from her queue, and HR for that part of the company overrides it from
| the exit's page — Rakesh over Shimla, which is where Deepak works and Rohit does not.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

function someoneAtMeridian(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** Chandni picks up finance on somebody's seeded exit and holds it over 40,000 to recover. */
function chandniHoldsFinanceOn(string $leaver): ProcessCase
{
    $exit = ProcessCase::query()->where('subject_user_id', someoneAtMeridian($leaver)->getKey())->sole();
    $chandni = someoneAtMeridian('chandni');

    (new CaseEngine)->claim($exit, 3, $chandni);
    (new CaseEngine)->decide($exit->fresh(), 3, 'held', $chandni, [
        'imprest_card_returned' => false,
        'recover_from_them' => 40000,
    ], 'Imprest card not returned');

    return $exit->fresh();
}

/**
 * Every answer ever recorded at finance on this exit, oldest first.
 *
 * @return list<string>
 */
function everyAnswerAtFinance(ProcessCase $exit): array
{
    return CaseEvent::query()->where('case_id', $exit->getKey())->where('type', 'step_acted')->orderBy('id')->get()
        ->filter(fn (CaseEvent $event): bool => (int) $event->payload['sequence'] === 3)
        ->map(fn (CaseEvent $event): string => $event->payload['outcome'])
        ->values()->all();
}

it('lets Chandni close her own hold as disputed from her queue, keeping the figure she settled on', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = someoneAtMeridian('chandni');

        // Nothing is on hold yet, so nothing offers to close one.
        Livewire::actingAs($chandni)->test(MyQueue::class)->assertDontSee('Close as disputed');

        $deepaks = chandniHoldsFinanceOn('deepak');
        $at = $deepaks->getKey();

        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->assertSee('Close as disputed')
            ->call('askFor', $at, 3, 'closed_disputed')
            ->assertSee('What is still disputed?')
            ->assertSee('stays on it as disputed')
            ->call('decide', $at, 3, 'closed_disputed')
            ->assertHasErrors(["reasons.{$at}.3"])
            ->set("answers.{$at}.3.recover_from_them", 35000)
            ->set("reasons.{$at}.3", 'Deepak disputes the figure for the imprest card')
            ->call('decide', $at, 3, 'closed_disputed')
            ->assertNotified('Recorded.');

        $finance = CaseStep::query()->where('case_id', $at)->where('sequence', 3)->sole();

        // Still hers, closed as disputed with the figure she typed as she closed it, and
        // never approved.
        expect($finance->outcome)->toBe('closed_disputed')
            ->and((int) $finance->assignee_id)->toBe((int) $chandni->getKey())
            ->and($finance->payload)->toEqual(['imprest_card_returned' => false, 'recover_from_them' => 35000])
            ->and(everyAnswerAtFinance($deepaks))->toBe(['held', 'closed_disputed']);

        Livewire::actingAs(someoneAtMeridian('rakesh'))->test(ViewCase::class, ['record' => $at])
            ->assertSee('Closed with the disagreement on the record by Chandni Verma')
            ->assertSee('Why: Deepak disputes the figure for the imprest card')
            ->assertSee('Put on hold by Chandni Verma')
            ->assertDontSee('Approved by Chandni Verma')
            // Nothing is on hold any more, so there is nothing to override.
            ->assertActionHidden('overrideHold');
    });
});

it('lets Rakesh override Chandni\'s hold from the exit page, in his name with hers beside it, and emails her', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = chandniHoldsFinanceOn('deepak');
        $chandni = someoneAtMeridian('chandni');
        $rakesh = someoneAtMeridian('rakesh');
        $toWhoeverRaisedIt = fn (): int => NotificationDelivery::query()->where('case_id', $deepaks->getKey())
            ->where('template_key', TemplateCatalogue::CaseMovedInitiator)->count();
        $before = $toWhoeverRaisedIt();

        // One hold, so the dialog has already chosen it.
        Livewire::actingAs($rakesh)->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->assertActionVisible('overrideHold')
            ->callAction('overrideHold', ['reason' => 'Chandni is on leave and settlement is due Friday'])
            ->assertHasNoActionErrors()
            ->assertNotified('Hold overridden')
            ->assertSee("Closed over Chandni Verma's hold by Rakesh Menon")
            ->assertSee('Why: Chandni is on leave and settlement is due Friday')
            ->assertSee('Put on hold by Chandni Verma')
            ->assertActionHidden('overrideHold');

        $finance = CaseStep::query()->where('case_id', $deepaks->getKey())->where('sequence', 3)->sole();
        $override = CaseEvent::query()->where('case_id', $deepaks->getKey())->where('type', 'step_acted')->latest('id')->first();

        // Chandni stays on the row with her figure, and the override is Rakesh's.
        expect($finance->outcome)->toBe('force_closed')
            ->and((int) $finance->assignee_id)->toBe((int) $chandni->getKey())
            ->and($finance->payload)->toEqual(['imprest_card_returned' => false, 'recover_from_them' => 40000])
            ->and((int) $override->actor_id)->toBe((int) $rakesh->getKey())
            ->and($override->payload['held_by'])->toBe((int) $chandni->getKey())
            ->and(everyAnswerAtFinance($deepaks))->toBe(['held', 'force_closed']);

        // Chandni is told, once: she raised the exit too, and the note to whoever raised it
        // would only have said the same thing with less in it.
        $told = NotificationDelivery::query()->where('case_id', $deepaks->getKey())
            ->where('template_key', TemplateCatalogue::HoldOverriddenHolder)->sole();

        expect((int) $told->recipient_user_id)->toBe((int) $chandni->getKey())
            ->and($told->body)->toContain('Rakesh Menon has overridden your hold on Finance clearance')
            ->toContain('Chandni is on leave and settlement is due Friday')
            ->and($toWhoeverRaisedIt())->toBe($before);
    });
});

it('keeps the override to HR for that part of the company, never the person holding it, by any door', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = chandniHoldsFinanceOn('deepak');
        $rohits = chandniHoldsFinanceOn('rohit');
        $chandni = someoneAtMeridian('chandni');
        $rakesh = someoneAtMeridian('rakesh');

        // Chandni is an administrator, but the hold is her own. She ends it from her queue.
        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->assertActionHidden('overrideHold');

        expect(fn () => (new CaseEngine)->resolveHold($deepaks, 3, 'force_closed', $chandni, 'Overriding myself'))
            ->toThrow(ProcessRefused::class, 'You are holding [Finance clearance] yourself');

        // Rakesh is HR head over Shimla, and Rohit works in Pune. Anjali holds nothing.
        expect(fn () => (new CaseEngine)->resolveHold($rohits, 3, 'force_closed', $rakesh, 'Not his branch'))
            ->toThrow(ProcessRefused::class, 'Only somebody who looks after exits for that part of the company can override a hold')
            ->and(fn () => (new CaseEngine)->resolveHold($deepaks, 3, 'force_closed', someoneAtMeridian('anjali'), 'Not hers'))
            ->toThrow(ProcessRefused::class, 'Only somebody who looks after exits for that part of the company can override a hold');

        // A client who takes the action off their HR head role: Rakesh still reads Deepak's
        // exit and is not offered the override.
        Role::query()->where('key', 'hr_head')->sole()->permissions()->where('permission', Permission::OverrideHold)->delete();
        app(PermissionResolver::class)->forget();

        Livewire::actingAs($rakesh)->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->assertSuccessful()
            ->assertActionHidden('overrideHold');

        expect(CaseStep::query()->where('sequence', 3)->whereIn('case_id', [$deepaks->getKey(), $rohits->getKey()])->pluck('outcome')->all())
            ->toBe(['held', 'held']);
    });
});

it('never lets Chandni close a hold as disputed once finance is no longer hers', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = chandniHoldsFinanceOn('deepak');
        $chandni = someoneAtMeridian('chandni');
        $at = $deepaks->getKey();

        // Finance moves to Priya, so nobody is standing in and the step is Priya's alone.
        $finance = Role::query()->where('key', 'finance_head')->sole();
        $chandni->roleAssignments()->where('role_id', $finance->getKey())->delete();
        someoneAtMeridian('priya')->roleAssignments()->create(['role_id' => $finance->getKey(), 'org_unit_id' => null]);
        app(PermissionResolver::class)->forget();

        // The card has left her queue. The same request sent by hand is refused, figure
        // and all.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->assertDontSee('Close as disputed')
            ->set("answers.{$at}.3.recover_from_them", 4000)
            ->set("reasons.{$at}.3", 'Settling it on my way out')
            ->call('decide', $at, 3, 'closed_disputed')
            ->assertNotified('[Finance clearance] is not yours to act on.');

        $row = CaseStep::query()->where('case_id', $at)->where('sequence', 3)->sole();

        expect($row->outcome)->toBe('held')
            ->and($row->payload)->toEqual(['imprest_card_returned' => false, 'recover_from_them' => 40000]);
    });
});

it('never ends a hold on a case about nobody either way, so a hire cannot go ahead unapproved', function () {
    TenantContext::run($this->meridian, function () {
        $hiringRequest = ProcessCase::query()->whereNull('subject_user_id')->first();

        // Priya is an administrator over the whole company.
        expect(someoneAtMeridian('priya')->can('overrideHold', $hiringRequest))->toBeFalse()
            ->and(someoneAtMeridian('priya')->can('overrideHold', ProcessCase::query()->where('subject_user_id', someoneAtMeridian('deepak')->getKey())->sole()))->toBeTrue();

        // Meridian's hiring approvals cannot be held, so a request about nobody whose
        // approval can be. Rakesh holds it, and neither way out passes it.
        $rakesh = someoneAtMeridian('rakesh');
        $budget = ProcessTemplate::factory()->named('budget_request', 'Budget request')->about('none')->create();
        ProcessStep::factory()->of($budget)->at(1, 1)->named('Budget approval')->heldBy('rakesh@meridian.test')->offering('approved', 'rejected', 'held')->create();
        ProcessStep::factory()->of($budget)->at(2, 2)->named('Raise the order')->heldBy('anjali@meridian.test')->offering('approved')->create();
        $budget->publish();

        $request = (new CaseEngine)->open($budget->fresh(), null, someoneAtMeridian('anjali'));
        (new CaseEngine)->claim($request->fresh(), 1, $rakesh);
        (new CaseEngine)->decide($request->fresh(), 1, 'held', $rakesh, [], 'Budget not confirmed');

        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->assertSee('On hold')
            ->assertDontSee('Close as disputed');

        expect(fn () => (new CaseEngine)->resolveHold($request->fresh(), 1, 'closed_disputed', $rakesh, 'Still disagree'))
            ->toThrow(ProcessRefused::class, 'only on somebody\'s exit')
            ->and(fn () => (new CaseEngine)->resolveHold($request->fresh(), 1, 'force_closed', someoneAtMeridian('priya'), 'Rakesh is away'))
            ->toThrow(ProcessRefused::class, 'only on somebody\'s exit')
            ->and(CaseStep::query()->where('case_id', $request->getKey())->where('sequence', 1)->sole()->outcome)->toBe('held');
    });
});

/*
| Existing companies
*/

it('gives existing administrators and HR heads the override and existing companies its message', function () {
    TenantContext::run($this->meridian, function () {
        Role::query()->get()->each(fn (Role $role) => $role->permissions()->where('permission', Permission::OverrideHold)->delete());
        NotificationTemplate::query()->where('key', TemplateCatalogue::HoldOverriddenHolder)->delete();
    });

    (require database_path('migrations/2026_11_20_140000_grant_overriding_a_hold_and_word_its_message.php'))->up();

    app(PermissionResolver::class)->forget();

    TenantContext::run($this->meridian, function () {
        $resolver = app(PermissionResolver::class);

        expect($resolver->allows(someoneAtMeridian('chandni'), Permission::OverrideHold))->toBeTrue()
            ->and($resolver->allows(someoneAtMeridian('rakesh'), Permission::OverrideHold))->toBeTrue()
            // Finance holds a clearance; it does not overrule one.
            ->and(Role::query()->where('key', 'finance_head')->sole()->permissions()->where('permission', Permission::OverrideHold)->exists())->toBeFalse()
            ->and(NotificationTemplate::query()->where('key', TemplateCatalogue::HoldOverriddenHolder)->exists())->toBeTrue();
    });
});
