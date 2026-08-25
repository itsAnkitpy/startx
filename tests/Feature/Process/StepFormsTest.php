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

        $rules = (new StepForm)->rules($step);

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

        expect((new StepForm)->rules($step)['notes'])
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

        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->set("answers.{$anjalis->getKey()}.2.imprest_card_returned", '1')
            ->set("answers.{$anjalis->getKey()}.2.recover_from_them", '2500.50')
            ->set("answers.{$anjalis->getKey()}.2.recovery_reason", 'advance')
            ->call('decide', $anjalis->getKey(), 2, 'approved')
            ->assertHasNoErrors();

        $answered = CaseStep::query()->where('case_id', $anjalis->getKey())->where('sequence', 2)->sole();

        // Postgres sorts a jsonb object's keys, so this is compared as a map and not as
        // the text it was written as.
        expect((array) $answered->payload)->toEqual([
            'imprest_card_returned' => '1',
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
            ->assertSee('Amount to recover from them')
            ->assertSee('Imprest not settled');
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

        $rules = (new StepForm)->rules($step);

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
