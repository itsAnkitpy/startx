<?php

use App\Exceptions\ProcessRefused;
use App\Filament\Pages\MyQueue;
use App\Models\CaseStep;
use App\Models\Designation;
use App\Models\FormDefinition;
use App\Models\FormField;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Process\StepForm;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/*
| What a step asks for, defined by the client rather than by us.
|
| The claim being checked here is one sentence: IT's clearance asking whether the mailbox
| is off and finance's asking for the imprest card back are the same code and different
| rows, and a form edited next year cannot change what a closed step meant.
|
| The last few run against the demo company as it is actually seeded, so they check the
| screen Ankit opens rather than a fixture built to suit them.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian-forms']);
});

/** A draft form with the questions given, in the order given. */
function draftFormAsking(array $questions, string $key = 'clearance'): FormDefinition
{
    $form = FormDefinition::factory()->named($key, 'Finance clearance')->create();

    foreach (array_values($questions) as $position => $question) {
        FormField::factory()->on($form)->at($position + 1)->state($question)->create();
    }

    return $form;
}

/** A live one-step process whose only step asks the given questions. */
function aStepAsking(array $questions): ProcessStep
{
    $form = draftFormAsking($questions);
    $form->publish();

    $template = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

    $step = ProcessStep::factory()->of($template)->at(1, 1)->named('Finance clearance')
        ->asking($form)->create();

    $template->publish();

    return $step->refresh();
}

it('lets one client ask what nobody else asks, without a migration', function () {
    TenantContext::run($this->meridian, function () {
        $itsQuestion = aStepAsking([
            ['key' => 'mailbox_off', 'label' => 'Mailbox switched off', 'type' => FormField::Boolean],
        ]);

        expect((new StepForm)->labels($itsQuestion))->toBe(['mailbox_off' => 'Mailbox switched off']);
    });
});

it('freezes a live form, so a closed step still asks what it asked', function () {
    TenantContext::run($this->meridian, function () {
        $form = draftFormAsking([
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean],
        ]);

        // A draft is edited freely — this is what the editing screen in module 12 does.
        $form->fields()->first()->update(['label' => 'Imprest card handed back']);

        $form->publish();

        // And after it is live, nothing may. This is the database refusing, not the
        // application: the whole protection a closed step has is that the questions
        // cannot change, and a rule only the application applies is one an import walks
        // straight round.
        //
        // Renaming, adding and removing are three tests rather than three lines of one,
        // because the first refusal takes the whole transaction down with it.
        $form->fields()->first()->update(['label' => 'Card returned']);
    });
})->throws(QueryException::class, 'cannot be changed');

it('refuses a new question on a live form', function () {
    TenantContext::run($this->meridian, function () {
        $form = draftFormAsking([['key' => 'imprest_returned', 'label' => 'Imprest card returned']]);
        $form->publish();

        FormField::factory()->on($form)->at(2)->asking('extra', 'Something else asked later')->create();
    });
})->throws(QueryException::class, 'cannot be changed');

it('refuses a question being taken off a live form', function () {
    TenantContext::run($this->meridian, function () {
        $form = draftFormAsking([['key' => 'imprest_returned', 'label' => 'Imprest card returned']]);
        $form->publish();

        // Removing a question changes what a closed step asked exactly as much as
        // renaming one does, which is why the trigger covers deletes too.
        $form->fields()->first()->delete();
    });
})->throws(QueryException::class, 'cannot be changed');

it('keeps one live version of a form, so a step cannot ask two things at once', function () {
    TenantContext::run($this->meridian, function () {
        $first = draftFormAsking([['key' => 'a', 'label' => 'First question']], 'clearance');
        $first->publish();

        $second = FormDefinition::factory()->named('clearance', 'Finance clearance')->version(2)->create();
        FormField::factory()->on($second)->asking('b', 'Second question')->create();
        $second->publish();

        // Publishing the second retired the first rather than leaving two live rows the
        // database would hand back in whichever order it liked.
        expect($first->refresh()->status)->toBe(FormDefinition::Archived)
            ->and($second->refresh()->status)->toBe(FormDefinition::Published);
    });
});

it('refuses to make a form live with nothing on it', function () {
    TenantContext::run($this->meridian, function () {
        $empty = FormDefinition::factory()->named('empty', 'Empty clearance')->create();

        expect(fn () => $empty->publish())
            ->toThrow(ProcessRefused::class, 'has no questions on it yet');
    });
});

it('builds the rules for each kind of question out of the client rows', function () {
    TenantContext::run($this->meridian, function () {
        $step = aStepAsking([
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean, 'required' => true],
            ['key' => 'recover', 'label' => 'Amount to recover', 'type' => FormField::Money],
            ['key' => 'reason', 'label' => 'What for', 'type' => FormField::Select, 'options' => [
                ['value' => 'advance', 'label' => 'Salary advance'],
            ]],
            ['key' => 'notes', 'label' => 'Notes', 'type' => FormField::Textarea, 'validation' => ['max_length' => 40]],
            ['key' => 'cleared_on', 'label' => 'Cleared on', 'type' => FormField::Date],
        ]);

        $rules = (new StepForm)->rules($step, []);

        // A required yes/no uses `present` rather than `required`, because "no" is a real
        // answer and Laravel counts a false as nothing at all. Asking whether the mailbox
        // was switched off and getting an explicit no is the whole point of asking.
        expect($rules['imprest_returned'])->toBe(['present', 'boolean'])
            // Never negative: a recovery of minus two thousand rupees is a payable, and
            // the settlement statement has its own line for that.
            ->and($rules['recover'])->toBe(['nullable', 'numeric', 'min:0'])
            ->and($rules['notes'])->toBe(['nullable', 'string', 'max:40'])
            // The date the browser's own date box sends, and nothing looser. Plain `date`
            // accepts "tomorrow", which is one date today and another next week, and
            // these are read off a closed case years later.
            ->and($rules['cleared_on'])->toBe(['nullable', 'date_format:Y-m-d']);

        expect((string) $rules['reason'][2])->toBe('in:"advance"');
    });
});

it('never lets a client row become a rule of its own', function () {
    TenantContext::run($this->meridian, function () {
        // Somebody editing the finance clearance form types a rule string where a limit
        // was meant. It is read as a named limit, found to be nothing of the kind, and
        // ignored — it never reaches Laravel's rule parser, where it would have let
        // whoever edits a form decide which rules run on it.
        $step = aStepAsking([
            ['key' => 'notes', 'label' => 'Notes', 'type' => FormField::Text, 'validation' => [
                'max_length' => 'exists:users,id|required',
                'min' => 'unique:users',
            ]],
        ]);

        expect((new StepForm)->rules($step, [])['notes'])
            ->toBe(['nullable', 'string', 'max:'.StepForm::DefaultTextLength]);
    });
});

it('refuses an answer to a question the step does not ask', function () {
    $this->seed(MeridianSeeder::class);

    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, function () {
        $priya = User::query()->where('work_email', 'priya@meridian.test')->sole();

        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // Priya really does hold Anjali's HR clearance, and the answer she is sending is
        // one finance's step asks, not hers.
        //
        // The reason it is refused rather than quietly dropped: a case reads all its live
        // steps' answers together when it works out which steps it still wants. An answer
        // written at the wrong step is an answer at every step, so Priya could switch off
        // the finance clearance behind her and the exit would close as though finance had
        // cleared it.
        expect(fn () => (new CaseEngine)->decide(
            $anjalis, 1, 'approved', $priya, ['imprest_card_returned' => true],
        ))->toThrow(ProcessRefused::class, 'does not ask imprest_card_returned');

        // And nothing was written on the way to being refused.
        expect(CaseStep::query()->where('case_id', $anjalis->getKey())
            ->whereNotNull('acted_at')->count())->toBe(0);
    });
});

it('stores what was answered against the step that asked it', function () {
    $this->seed(MeridianSeeder::class);

    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, function () {
        $chandni = User::query()->where('work_email', 'chandni@meridian.test')->sole();

        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // HR clears it first, so the finance clearance is actually Chandni's turn.
        $priya = User::query()->where('work_email', 'priya@meridian.test')->sole();
        (new CaseEngine)->decide($anjalis, 1, 'approved', $priya, ['id_card_returned' => true]);

        // The imprest card did not come back, which is what makes finance's other two
        // questions asked at all — the demo form does not ask what to recover from
        // somebody who returned the card.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->set("answers.{$anjalis->getKey()}.2.imprest_card_returned", '0')
            ->set("answers.{$anjalis->getKey()}.2.recover_from_them", '2500.50')
            ->set("answers.{$anjalis->getKey()}.2.recovery_reason", 'advance')
            ->call('decide', $anjalis->getKey(), 2, 'approved')
            ->assertHasNoErrors();

        $answered = CaseStep::query()->where('case_id', $anjalis->getKey())->where('sequence', 2)->sole();

        // Postgres sorts a jsonb object's keys, so this is compared as a map and not as
        // the text it was written as.
        expect((array) $answered->payload)->toEqual([
            'imprest_card_returned' => '0',
            'recover_from_them' => '2500.50',
            'recovery_reason' => 'advance',
        ]);
    });
});

it('shows the client own questions on the card, in their own words', function () {
    $this->seed(MeridianSeeder::class);

    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, function () {
        $rakesh = User::query()->where('work_email', 'rakesh@meridian.test')->sole();
        $chandni = User::query()->where('work_email', 'chandni@meridian.test')->sole();

        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // Rakesh holds HR's clearance, and HR asks about the ID card. Nothing about
        // finance's questions is on his page.
        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->assertSee('ID card returned')
            ->assertSee('Notice period short by (days)')
            ->assertDontSee('Imprest card returned')
            // Drawn with Filament's own components. This project has no front-end build,
            // so the only styles that reach the browser are the ones Filament ships
            // compiled — a class we invent does nothing, and the first version of this
            // screen shipped unstyled boxes because of exactly that.
            ->assertSee('fi-fo-field-label', escape: false)
            ->assertSee('fi-input-wrp', escape: false)
            // The paragraph box specifically. Filament styles a one-line box with an
            // element selector, so the same class on a textarea styles nothing and the
            // browser draws its own small box inside ours — which is what Ankit's
            // screenshot caught.
            ->assertSee('fi-fo-textarea', escape: false);

        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->set("answers.{$anjalis->getKey()}.1.id_card_returned", '1')
            ->call('decide', $anjalis->getKey(), 1, 'approved')
            ->assertHasNoErrors();

        // The same screen, the same code, a different set of questions — which is the
        // whole claim of this module. Chandni sees HR's questions as well, because Pune
        // has no HR head and that clearance falls to her as the company's stand-in; the
        // point is that each card carries the questions of its own step.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->assertSee('Imprest card returned')
            // Not asked until she says the card did not come back — the next test is the
            // one that watches it appear.
            ->assertDontSee('Amount to recover from them');
    });
});

it('will not let a required question be left out of the request', function () {
    $this->seed(MeridianSeeder::class);

    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, function () {
        $chandni = User::query()->where('work_email', 'chandni@meridian.test')->sole();
        $priya = User::query()->where('work_email', 'priya@meridian.test')->sole();

        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        (new CaseEngine)->decide($anjalis, 1, 'approved', $priya, ['id_card_returned' => true]);

        // Nothing sent at all for the one question finance has to answer. The browser is
        // the half of this an employee controls, so the refusal is on the server.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->call('decide', $anjalis->getKey(), 2, 'approved')
            ->assertHasErrors("answers.{$anjalis->getKey()}.2.imprest_card_returned");

        // And the step is still waiting, rather than approved with a hole in it.
        expect(CaseStep::query()->where('case_id', $anjalis->getKey())->where('sequence', 2)
            ->whereNotNull('acted_at')->count())->toBe(0);
    });
});

it('keeps a picker inside the client own rows', function () {
    $ours = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex-forms']);

    $theirs = TenantContext::run($this->meridian, fn () => Designation::factory()->create(['name' => 'Area Manager']));

    TenantContext::run($ours, function () use ($theirs) {
        $step = aStepAsking([
            ['key' => 'replacing', 'label' => 'Designation being vacated', 'type' => FormField::DesignationPicker],
        ]);

        $rules = (new StepForm)->rules($step, []);

        // The number is another company's designation. Nothing in this rule says so —
        // the tenant wall on the table does, which is exactly why these are pickers and
        // not free text.
        $refused = Validator::make(['replacing' => $theirs->getKey()], $rules);

        expect($refused->fails())->toBeTrue();

        $ourOwn = Designation::factory()->create(['name' => 'Area Manager']);

        expect(Validator::make(['replacing' => $ourOwn->getKey()], $rules)->fails())->toBeFalse();
    });
});

it('will not make a form live that asks for a document, until documents are built', function () {
    TenantContext::run($this->meridian, function () {
        $form = draftFormAsking([
            ['key' => 'it_note', 'label' => 'Signed IT handover note', 'type' => FormField::File],
        ]);

        expect(fn () => $form->publish())
            ->toThrow(ProcessRefused::class, 'attaching a document is not built yet');
    });
});

it('refuses to run a process whose step asks questions that can still be edited', function () {
    TenantContext::run($this->meridian, function () {
        // The form is left as a draft, which is the state a client's form is in while they
        // are still writing it — and a draft can be edited freely, which is the point.
        $draft = draftFormAsking([
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean],
        ]);

        $template = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        ProcessStep::factory()->of($template)->at(1, 1)->named('Finance clearance')
            ->asking($draft)->create();

        // Without this, the whole promise of the module is off: Chandni answers the
        // finance clearance in March, Anjali renames and deletes questions on the draft in
        // April, and the answers on the closed exit are filed against questions nobody was
        // ever asked. The freeze only protects a form that is live.
        expect(fn () => $template->publish())
            ->toThrow(ProcessRefused::class, 'which is still a draft');
    });
});

it('refuses to run a process whose step asks last month questions', function () {
    TenantContext::run($this->meridian, function () {
        $first = draftFormAsking([['key' => 'a', 'label' => 'First question']], 'clearance');
        $first->publish();

        $template = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        ProcessStep::factory()->of($template)->at(1, 1)->named('Finance clearance')
            ->asking($first)->create();

        // The client writes the next version of the form and makes it live, which retires
        // the one the step still points at.
        $second = FormDefinition::factory()->named('clearance', 'Finance clearance')->version(2)->create();
        FormField::factory()->on($second)->asking('b', 'Second question')->create();
        $second->publish();

        expect(fn () => $template->publish())
            ->toThrow(ProcessRefused::class, 'replaced by a newer version');
    });
});

it('refuses to make a form live asking a list with nothing on it to choose', function () {
    TenantContext::run($this->meridian, function () {
        $form = draftFormAsking([
            ['key' => 'recovery_reason', 'label' => 'What the recovery is for', 'type' => FormField::Select],
        ]);

        // A question nobody can answer. Required, it leaves the exit open for ever with
        // nothing on any screen saying why.
        expect(fn () => $form->publish())
            ->toThrow(ProcessRefused::class, 'nothing for anybody to pick');
    });
});

it('records a question left blank as unanswered rather than as an empty answer', function () {
    $this->seed(MeridianSeeder::class);

    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, function () {
        $rakesh = User::query()->where('work_email', 'rakesh@meridian.test')->sole();

        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // Rakesh answers the one question HR has to answer and leaves the other two boxes
        // as he found them — the shortfall in days, and the remarks.
        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->set("answers.{$anjalis->getKey()}.1.id_card_returned", '1')
            ->set("answers.{$anjalis->getKey()}.1.notice_shortfall_days", '')
            ->set("answers.{$anjalis->getKey()}.1.remarks", '')
            ->call('decide', $anjalis->getKey(), 1, 'approved')
            ->assertHasNoErrors();

        $answered = CaseStep::query()->where('case_id', $anjalis->getKey())->where('sequence', 1)->sole();

        // Only the answer he gave. An empty box stored as an answer is read as an answer
        // by every later step: one that opens when the shortfall was answered at all would
        // open on this exit, and one comparing it against a threshold is quietly false on
        // every exit. Not answered is recorded by the answer not being there.
        expect((array) $answered->payload)->toEqual(['id_card_returned' => '1']);
    });
});

it('keeps what somebody typed when the engine refuses what they pressed', function () {
    $this->seed(MeridianSeeder::class);

    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, function () {
        $rakesh = User::query()->where('work_email', 'rakesh@meridian.test')->sole();

        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // HR's clearance offers approve and reject, so sending it back is refused. A
        // refusal is a sentence asking somebody to do something differently, and clearing
        // the boxes underneath it would make Rakesh type the whole clearance again to read
        // the same sentence.
        $screen = Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->set("answers.{$anjalis->getKey()}.1.id_card_returned", '1')
            ->set("answers.{$anjalis->getKey()}.1.remarks", 'Card handed to the Pune office.')
            ->call('decide', $anjalis->getKey(), 1, 'sent_back');

        $screen->assertSet("answers.{$anjalis->getKey()}.1.remarks", 'Card handed to the Pune office.')
            ->assertSet("answers.{$anjalis->getKey()}.1.id_card_returned", '1');

        // And nothing was recorded on the way to being refused.
        expect(CaseStep::query()->where('case_id', $anjalis->getKey())->where('sequence', 1)
            ->whereNotNull('acted_at')->count())->toBe(0);
    });
});

/*
| Step 2: a question hidden by an earlier answer on the same form.
|
| One rule, three places, and the whole point is that they cannot disagree: a question the
| screen is not showing is not in the rules and is not stored. ServiceNow spent years
| hiding a field in the browser while the server went on demanding it, which is a step
| nobody can complete — the box is not there and the refusal says it is required.
*/

it('stops asking a question once an earlier answer makes it pointless', function () {
    TenantContext::run($this->meridian, function () {
        $step = aStepAsking([
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean, 'required' => true],
            ['key' => 'recover', 'label' => 'Amount to recover', 'type' => FormField::Money, 'required' => true,
                'visible_if' => [[['field' => 'imprest_returned', 'operator' => '=', 'value' => false]]]],
        ]);

        $forms = new StepForm;

        // The card came back, so there is nothing to recover and nobody is asked for a
        // figure. Required or not is beside the point: an unasked question is not required,
        // and demanding it would leave the clearance impossible to finish with nothing on
        // the screen saying why.
        expect($forms->asking($step, ['imprest_returned' => '1'])->pluck('key')->all())
            ->toBe(['imprest_returned']);

        expect(array_keys($forms->rules($step, ['imprest_returned' => '1'])))
            ->toBe(['imprest_returned']);

        // It did not come back, so the figure is asked for and is required.
        expect($forms->asking($step, ['imprest_returned' => '0'])->pluck('key')->all())
            ->toBe(['imprest_returned', 'recover']);

        expect($forms->rules($step, ['imprest_returned' => '0'])['recover'])
            ->toContain('required');

        // Nothing answered yet, so the question that depends on an answer is not asked
        // either. A comparison against an answer nobody has given is false, which is the
        // same reading a step's own opening conditions get.
        expect($forms->asking($step, [])->pluck('key')->all())->toBe(['imprest_returned']);
    });
});

it('does not store an answer to a question it has stopped asking', function () {
    TenantContext::run($this->meridian, function () {
        $step = aStepAsking([
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean],
            ['key' => 'recover', 'label' => 'Amount to recover', 'type' => FormField::Money,
                'visible_if' => [[['field' => 'imprest_returned', 'operator' => '=', 'value' => false]]]],
        ]);

        // Chandni types a figure, then says the card came back after all. The box she typed
        // in is no longer on the screen, so the figure goes with it — and it goes at the
        // engine, so it goes whichever of the three ways in the answer arrived by.
        expect((new StepForm)->onlyWhatThisStepAsks($step, [
            'imprest_returned' => '1',
            'recover' => '2500',
        ]))->toBe(['imprest_returned' => '1']);

        // Storing it would not be untidiness. Every live step's answers are read together
        // when the case works out which steps it still wants, so a figure nobody was asked
        // for can decide whether the director's approval happens.
        expect((new StepForm)->onlyWhatThisStepAsks($step, [
            'imprest_returned' => '0',
            'recover' => '2500',
        ]))->toEqual(['imprest_returned' => '0', 'recover' => '2500']);
    });
});

it('hides what a hidden question would have decided, all the way down', function () {
    TenantContext::run($this->meridian, function () {
        $step = aStepAsking([
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean],
            ['key' => 'recover', 'label' => 'Amount to recover', 'type' => FormField::Money,
                'visible_if' => [[['field' => 'imprest_returned', 'operator' => '=', 'value' => false]]]],
            ['key' => 'recovery_reason', 'label' => 'What the recovery is for', 'type' => FormField::Text,
                'visible_if' => [[['field' => 'recover', 'operator' => 'is_set']]]],
        ]);

        $forms = new StepForm;

        // The middle question is not asked, so the figure that was typed into it decides
        // nothing below it. Without that, Chandni says the card came back and is still
        // asked to explain a recovery that is not happening.
        expect($forms->asking($step, ['imprest_returned' => '1', 'recover' => '2500'])->pluck('key')->all())
            ->toBe(['imprest_returned']);

        expect($forms->asking($step, ['imprest_returned' => '0', 'recover' => '2500'])->pluck('key')->all())
            ->toBe(['imprest_returned', 'recover', 'recovery_reason']);

        // And the whole chain is dropped from what is stored, not only the middle of it.
        expect($forms->onlyWhatThisStepAsks($step, [
            'imprest_returned' => '1', 'recover' => '2500', 'recovery_reason' => 'Salary advance',
        ]))->toBe(['imprest_returned' => '1']);
    });
});

it('still refuses an answer to a question no version of the form asks', function () {
    TenantContext::run($this->meridian, function () {
        $step = aStepAsking([
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean],
        ]);

        // The difference that matters. A question the form has and is not asking is dropped,
        // because somebody genuinely typed into a box that has since gone. A question no
        // version of the form ever had is somebody reaching past the screen.
        expect(fn () => (new StepForm)->onlyWhatThisStepAsks($step, ['settlement_cleared' => true]))
            ->toThrow(ProcessRefused::class, 'does not ask settlement_cleared');
    });
});

it('asks finance what to recover only once it says the card did not come back', function () {
    $this->seed(MeridianSeeder::class);

    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, function () {
        $chandni = User::query()->where('work_email', 'chandni@meridian.test')->sole();

        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // HR clears it first, so the finance clearance is Chandni's turn.
        $priya = User::query()->where('work_email', 'priya@meridian.test')->sole();
        (new CaseEngine)->decide($anjalis, 1, 'approved', $priya, ['id_card_returned' => true]);

        $screen = Livewire::actingAs($chandni)->test(MyQueue::class)
            ->assertSee('Imprest card returned')
            ->assertDontSee('Amount to recover from them')
            ->assertDontSee('What the recovery is for')
            // The half a `set` in a test cannot prove: the boxes really do carry the
            // binding that sends the answer as it is given. Without it every assertion
            // below still passes and nothing appears or disappears in a browser, because
            // Livewire's own default is to send nothing until a button is pressed. A
            // Filament component that quietly dropped the attribute would look identical
            // from here.
            ->assertSee('wire:model.live="answers.'.$anjalis->getKey().'.2.imprest_card_returned"', escape: false)
            ->assertSee('wire:model.live.blur="answers.'.$anjalis->getKey().'.2.pay_to_them"', escape: false);

        // She says it did not come back. This is the whole thing Ankit can watch happen on
        // the card: one answer, two more questions.
        $screen->set("answers.{$anjalis->getKey()}.2.imprest_card_returned", '0')
            ->assertSee('Amount to recover from them')
            ->assertDontSee('What the recovery is for');

        // A figure, and the reason for it is asked as well.
        $screen->set("answers.{$anjalis->getKey()}.2.recover_from_them", '2500')
            ->assertSee('What the recovery is for')
            ->assertSee('Imprest not settled');

        // And back again — she was wrong, the card is on her desk. Both questions go.
        $screen->set("answers.{$anjalis->getKey()}.2.imprest_card_returned", '1')
            ->assertDontSee('Amount to recover from them')
            ->assertDontSee('What the recovery is for');

        // The figure she typed is not stored against the clearance, even though it is still
        // held on the page.
        $screen->call('decide', $anjalis->getKey(), 2, 'approved')->assertHasNoErrors();

        $answered = CaseStep::query()->where('case_id', $anjalis->getKey())->where('sequence', 2)->sole();

        expect((array) $answered->payload)->toEqual(['imprest_card_returned' => '1']);
    });
});

/*
| And what going live refuses, because every failure above is the silent kind.
|
| A question hidden by a condition that can never be true is simply not on the screen:
| nothing errors, nobody is refused, and the finance clearance quietly stops collecting the
| recovery figure. If a later step opens on that figure, the director's approval never
| appears either, and the exit closes as though it had been given.
*/

/** A draft exit whose one step asks the given questions on a form that is already live. */
function aDraftExitAsking(array $questions): ProcessTemplate
{
    $form = draftFormAsking($questions);
    $form->publish();

    $template = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

    ProcessStep::factory()->of($template)->at(1, 1)->named('Finance clearance')
        ->asking($form)->create();

    return $template;
}

it('refuses a question hidden by an answer nobody on the form is asked for', function () {
    TenantContext::run($this->meridian, function () {
        // The usual way a client gets here: the question was renamed on the new version of
        // the form and what pointed at it was not.
        $exit = aDraftExitAsking([
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean],
            ['key' => 'recover', 'label' => 'Amount to recover', 'type' => FormField::Money,
                'visible_if' => [[['field' => 'imprest_card_back', 'operator' => '=', 'value' => false]]]],
        ]);

        expect(fn () => $exit->publish())->toThrow(
            ProcessRefused::class,
            'question [Amount to recover] depends on the answer to [imprest_card_back], which is not one '
                .'of the questions asked before it on this form',
        );
    });
});

it('refuses a question hidden by an answer given after it, or by its own', function () {
    TenantContext::run($this->meridian, function () {
        // Both are the same dead question. A condition can only read an answer already
        // given, so a question waiting on one below it — or on itself — is never asked at
        // all, and that is also what stops two questions hiding each other for ever.
        $exit = aDraftExitAsking([
            ['key' => 'recover', 'label' => 'Amount to recover', 'type' => FormField::Money,
                'visible_if' => [[['field' => 'imprest_returned', 'operator' => '=', 'value' => false]]]],
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean,
                'visible_if' => [[['field' => 'imprest_returned', 'operator' => 'is_set']]]],
        ]);

        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'question [Amount to recover] depends on the answer to [imprest_returned]')
            ->toThrow(ProcessRefused::class, 'It is the first question on the form, so there is no earlier answer')
            ->toThrow(ProcessRefused::class, 'question [Imprest card returned] depends on the answer to [imprest_returned]');
    });
});

it('refuses a question hidden by a comparison that cannot be made', function () {
    TenantContext::run($this->meridian, function () {
        // The same three things a step's own condition is refused for, arriving through a
        // question: a comparison this system does not have, a threshold typed as words, and
        // asking whether something was answered at all while also comparing it.
        $exit = aDraftExitAsking([
            ['key' => 'shortfall_days', 'label' => 'Notice short by', 'type' => FormField::Number],
            ['key' => 'waiver', 'label' => 'Notice waived', 'type' => FormField::Boolean,
                'visible_if' => [[['field' => 'shortfall_days', 'operator' => 'contains', 'value' => 7]]]],
            ['key' => 'waiver_reason', 'label' => 'Why it was waived', 'type' => FormField::Text,
                'visible_if' => [[['field' => 'shortfall_days', 'operator' => '>', 'value' => 'thirty']]]],
            ['key' => 'approved_by', 'label' => 'Who approved the waiver', 'type' => FormField::Text,
                'visible_if' => [[['field' => 'shortfall_days', 'operator' => 'is_set', 'value' => 7]]]],
        ]);

        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'question [Notice waived] depends on an earlier answer using [contains]')
            ->toThrow(ProcessRefused::class, 'question [Why it was waived] compares with [>] against [thirty], which is not a number')
            ->toThrow(ProcessRefused::class, 'question [Who approved the waiver] depends on whether an earlier question was answered at all, and gives something to compare it against as well');
    });
});

it('refuses a question said to be hidden by nothing at all', function () {
    TenantContext::run($this->meridian, function () {
        // An empty group is true by definition, so the question is asked on every case and
        // the client believes it is conditional. Same sentence a step gets for the same
        // shape, and it reads as nonsense unless it says so plainly.
        $exit = aDraftExitAsking([
            ['key' => 'imprest_returned', 'label' => 'Imprest card returned', 'type' => FormField::Boolean],
            ['key' => 'recover', 'label' => 'Amount to recover', 'type' => FormField::Money,
                'visible_if' => [[]]],
        ]);

        expect(fn () => $exit->publish())->toThrow(
            ProcessRefused::class,
            'question [Amount to recover] is asked only in certain cases, and one of those cases says nothing',
        );
    });
});
