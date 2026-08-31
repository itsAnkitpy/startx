<?php

use App\Authorization\PermissionResolver;
use App\Filament\Pages\CaseHistory;
use App\Filament\Pages\MyQueue;
use App\Models\FormDefinition;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Livewire\Livewire;

/*
| The screen that makes an approval which never happened visible.
|
| Every other list in the product only holds steps that did happen, so an exit that
| finished without a sign-off looks exactly like one that got it. These run against the
| demo company as it is seeded, including the one process in it that was built with the
| mistake publishing now refuses.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function whoIsAtMeridian(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

it('shows an exit finishing with an approval nobody was ever asked for', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoIsAtMeridian('chandni');
        $priyas = ProcessCase::query()->whereRelation('template', 'key', 'exit_with_the_mistake')->sole();

        // Chandni holds finance for the whole company, so the only step in front of
        // Priya's exit is hers. She clears it the way she would on the queue screen.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->set("answers.{$priyas->getKey()}.1.imprest_card_returned", '1')
            ->call('decide', $priyas->getKey(), 1, 'approved')
            ->assertHasNoErrors();

        // The exit is over, and the manager's sign-off — which was Chandni's own — never
        // opened for anybody.
        expect($priyas->fresh()->state)->toBe(ProcessCase::Closed)
            ->and($priyas->fresh()->steps()->where('sequence', 2)->exists())->toBeFalse();

        Livewire::actingAs($chandni)->test(CaseHistory::class)
            ->assertOk()
            ->assertSee('A step never happened')
            ->assertSee('Manager sign-off')
            ->assertSee('Never happened. Nobody was ever asked, and the case carried on without it.');
    });
});

it('does not mark a step that has simply not come round yet', function () {
    TenantContext::run($this->meridian, function () {
        // Anjali's exit is on the real process and nobody has touched it, so all four of
        // its steps have no row anywhere and not one of them is a failure.
        Livewire::actingAs(whoIsAtMeridian('chandni'))->test(CaseHistory::class)
            ->assertOk()
            ->assertSee('Anjali Rao')
            ->assertSee('It opens when the steps in front of it are done');

        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        expect(collect((new CaseHistory)->whatHappenedOn($anjalis))->pluck('tone')->all())
            ->toBe(['later', 'later', 'later', 'later']);
    });
});

it('says a step is waiting on somebody as soon as it becomes their turn', function () {
    TenantContext::run($this->meridian, function () {
        // A hiring request Anjali has already raised. Its first step is answered, so the
        // branch approval in front of Rakesh is open this minute — nobody has opened the
        // card yet, which is exactly the state that used to read as a request that had
        // stalled.
        $request = ProcessCase::query()
            ->whereRelation('template', 'key', 'hiring_request')
            ->whereNull('closed_at')
            ->firstOrFail();

        $said = collect((new CaseHistory)->whatHappenedOn($request))->pluck('said', 'sequence');

        expect($said[2])->toBe('Waiting on somebody to answer it.')
            // And the step behind that one really has not come round.
            ->and($said[3])->toBe('Not yet. It opens when the steps in front of it are done.');
    });
});

it('keeps a case out of the list of somebody with no business reading it', function () {
    TenantContext::run($this->meridian, function () {
        // Deepak holds no role at all, so the page does not open for him and he sees
        // nobody's case — the same rule the rest of the product applies to seeing a
        // person's record.
        auth()->login(whoIsAtMeridian('deepak'));

        expect(CaseHistory::canAccess())->toBeFalse()
            ->and((new CaseHistory)->cases()->count())->toBe(0);

        // Rakesh clears HR for Shimla and may see people there, so the Shimla exits are
        // his to read and Rohit's, in Pune, is not.
        auth()->login(whoIsAtMeridian('rakesh'));
        app(PermissionResolver::class)->forget();

        // A hiring request is about a vacancy and has no first name to read, so it is
        // counted by the process it runs on.
        $names = (new CaseHistory)->cases()
            ->map(fn (ProcessCase $case) => $case->subject?->first_name ?? $case->template->name)
            ->sort()->values()->all();

        expect(CaseHistory::canAccess())->toBeTrue()
            ->and($names)->not->toContain('Rohit')
            ->and($names)->toContain('Anjali');
    });
});

it('refuses to make the same mistake live a second time', function () {
    // The other half of it: the draft carrying the same mistake is refused, and the
    // refusal names the step and lists the questions the process really does ask.
    $this->artisan('process:publish', ['key' => 'exit_with_the_mistake', '--tenant' => MeridianSeeder::Slug])
        ->expectsOutputToContain('which no form on this process asks')
        ->assertFailed();

    TenantContext::run($this->meridian, function () {
        expect(ProcessTemplate::query()->where('key', 'exit_with_the_mistake')->where('version', 2)->sole()->status)
            ->toBe(ProcessTemplate::Draft);
    });
});

it('does not cry failure on an exit somebody turned down', function () {
    TenantContext::run($this->meridian, function () {
        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // Priya clears HR for Shimla, which is Anjali's branch, and she turns the exit
        // down at the first step. The three steps behind it were never due, so not one of
        // them is an approval that never happened.
        (new CaseEngine)->decide($anjalis, 1, 'rejected', whoIsAtMeridian('priya'), reason: 'She is staying.');

        expect(collect((new CaseHistory)->whatHappenedOn($anjalis->fresh()))->pluck('tone')->all())
            ->toBe(['done', 'stopped', 'stopped', 'stopped']);

        Livewire::actingAs(whoIsAtMeridian('chandni'))->test(CaseHistory::class)
            ->assertOk()
            ->assertSee('The exit ended before this came round.')
            ->assertDontSee('steps never happened');
    });
});

it('does not cry failure on an exit that was withdrawn', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = ProcessCase::query()->whereRelation('subject', 'first_name', 'Deepak')->sole();

        (new CaseEngine)->cancel($deepaks, whoIsAtMeridian('priya'), 'He withdrew his resignation.');

        expect(collect((new CaseHistory)->whatHappenedOn($deepaks->fresh()))->pluck('tone')->all())
            ->toBe(['stopped', 'stopped', 'stopped', 'stopped']);
    });
});

it('does not cry failure on a step that only applies to some exits', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoIsAtMeridian('chandni');

        // A sound process, with a step that opens only when finance says the imprest card
        // did not come back. It goes live through the ordinary check, so nothing about it
        // is the mistake this page exists to show.
        $sound = ProcessTemplate::factory()->named('exit_with_a_branch', 'Exit (with a branch)')
            ->about('employee')->create();

        ProcessStep::factory()->of($sound)->at(1, 1)->named('Finance clearance')
            ->asking(FormDefinition::query()->where('key', 'finance_clearance')
                ->where('status', FormDefinition::Published)->sole())
            ->heldByTheRoleAnywhere('finance_head')->offering('approved')->dueIn(48)->create();

        ProcessStep::factory()->of($sound)->at(2, 2)->named('Recovery sign-off')
            ->offering('approved')->dueIn(24)
            ->happensWhen([['source' => 'payload', 'field' => 'imprest_card_returned',
                'operator' => '=', 'value' => false]])
            ->state(['assignee_rule' => ['kind' => 'reporting_manager']])->create();

        $sound->publish();

        // The card came back, so there is nothing to recover and the sign-off was never
        // wanted. The exit is over and that step has no row — which is exactly the shape
        // of the failure, and is not one.
        $deepaks = (new CaseEngine)->open($sound, whoIsAtMeridian('deepak'), $chandni);

        (new CaseEngine)->decide($deepaks, 1, 'approved', $chandni, ['imprest_card_returned' => true]);

        expect($deepaks->fresh()->state)->toBe(ProcessCase::Closed)
            ->and(collect((new CaseHistory)->whatHappenedOn($deepaks->fresh()))->pluck('tone')->all())
            ->toBe(['done', 'skipped']);

        Livewire::actingAs($chandni)->test(CaseHistory::class)
            ->assertOk()
            ->assertSee('Not needed on this exit. It only opens in some cases, and this was not one.')
            ->assertDontSee('Never happened');
    });
});

it('says why a request was sent back, and where it went', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = whoIsAtMeridian('rakesh');
        $request = ProcessCase::query()->whereRelation('template', 'key', 'hiring_request')->first();

        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->call('askFor', $request->getKey(), 2, 'sent_back')
            ->set("reasons.{$request->getKey()}.2", 'The start date is before the vacancy exists.')
            ->call('decide', $request->getKey(), 2, 'sent_back')
            ->assertHasNoErrors();

        // Without the reason the page says "Sent back" and stops, which reads as a case
        // that stalled for nothing. It has to say why, and which step it went to.
        Livewire::actingAs($rakesh)->test(CaseHistory::class)
            ->assertOk()
            ->assertSee('Sent back by Rakesh Menon')
            ->assertSee('Back to Raise request.')
            ->assertSee('Why: The start date is before the vacancy exists.')
            ->assertDontSee('Waiting on somebody to answer it again.');

        // Anjali corrects it and sends it on, which puts the approval back in front of
        // Rakesh. The line about sending it back stops describing where the request is
        // the moment that happens: read on its own it says the request is still with her.
        $anjali = whoIsAtMeridian('anjali');

        Livewire::actingAs($anjali)->test(MyQueue::class)
            ->set("answers.{$request->getKey()}.1.justification", 'Corrected: the start date is now after the vacancy opens.')
            ->call('decide', $request->getKey(), 1, 'approved')
            ->assertHasNoErrors();

        Livewire::actingAs($rakesh)->test(CaseHistory::class)
            ->assertOk()
            ->assertSee('Sent back by Rakesh Menon')
            ->assertSee('Waiting on somebody to answer it again.');
    });
});

it('stops showing why a step was held once it has been answered', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = whoIsAtMeridian('rakesh');
        $chandni = whoIsAtMeridian('chandni');
        $exit = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->set("answers.{$exit->getKey()}.1.id_card_returned", '1')
            ->call('decide', $exit->getKey(), 1, 'approved')
            ->assertHasNoErrors();

        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->set("answers.{$exit->getKey()}.2.imprest_card_returned", '0')
            ->set("answers.{$exit->getKey()}.2.recover_from_them", 12000)
            ->set("answers.{$exit->getKey()}.2.recovery_reason", 'imprest')
            ->call('askFor', $exit->getKey(), 2, 'held')
            ->set("reasons.{$exit->getKey()}.2", 'Waiting on the imprest card.')
            ->call('decide', $exit->getKey(), 2, 'held')
            ->assertHasNoErrors();

        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->set("answers.{$exit->getKey()}.2.imprest_card_returned", '1')
            ->call('decide', $exit->getKey(), 2, 'approved')
            ->assertHasNoErrors();

        // A step somebody held and then cleared has two lines about it in the case's
        // history, and the later one is the only one that describes it. Showing the
        // earlier one puts the words she typed to hold the clearance against the day she
        // cleared it, which reads as money still being argued about.
        Livewire::actingAs($chandni)->test(CaseHistory::class)
            ->assertOk()
            ->assertSee('Finance clearance')
            ->assertDontSee('Why: Waiting on the imprest card.');
    });
});
