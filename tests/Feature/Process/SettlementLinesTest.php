<?php

use App\Exceptions\ProcessRefused;
use App\Filament\Pages\MyQueue;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\EmploymentRecord;
use App\Models\FormDefinition;
use App\Models\FormField;
use App\Models\NotificationDelivery;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\SettlementLine;
use App\Models\Succession;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TemplateCatalogue;
use App\Process\CaseEngine;
use App\Process\StepLink;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Database\Seeders\VertexSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Module 08 step 1: every recovery and payable a clearance files becomes a line nobody can
| change.
|
| Chandni holds finance on every Meridian exit, and Deepak's is waiting on her. Her form asks
| what to recover from him and why, and what is payable to him. A figure becomes a line when
| her clearance passes, by any of the three ways a clearance passes, and an exit cannot
| finish while a figure filed that way has no line. Step 2: Deepak is told of each recovery
| as its line is written, because his seven days to answer it start then.
|
| A refused statement abandons the surrounding transaction in Postgres, which under
| RefreshDatabase is the test's own, so each expected database refusal has a test to itself.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

function owedAtMeridian(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

function deepaksExitAwaitingFinance(): ProcessCase
{
    return ProcessCase::query()->where('subject_user_id', owedAtMeridian('deepak')->getKey())->sole();
}

/**
 * Every claim the person leaving was told of on this exit, oldest first.
 *
 * @return Collection<int, NotificationDelivery>
 */
function claimsToldOn(ProcessCase $exit): Collection
{
    return NotificationDelivery::query()->where('case_id', $exit->getKey())
        ->whereIn('template_key', [TemplateCatalogue::MoneyClaimedSubject, TemplateCatalogue::MoneyClaimChangedSubject])
        ->orderBy('id')->get();
}

/** Finance claims 40,000 from Farah, and HR's final check sends finance back to answer again. */
function financeSentBackAfterClaiming40000(): ProcessCase
{
    $process = ProcessTemplate::factory()->named('exit_claim_sent_back', 'Exit')->about('employee')->create();
    ProcessStep::factory()->of($process)->at(1, 1)->named('Finance clearance')
        ->collecting('claim_amount', FormField::Money, SettlementLine::Recovery)
        ->heldByTheRoleAnywhere('finance_head')->offering('approved', 'held')->create();
    ProcessStep::factory()->of($process)->at(2, 2)->named('HR final check')
        ->heldByTheRoleAnywhere('finance_head')->offering('approved', 'sent_back')->create();
    $process->publish();

    $exit = aLeaverOn($process);
    $chandni = owedAtMeridian('chandni');

    (new CaseEngine)->decide($exit, 1, 'approved', $chandni, ['claim_amount' => 40000]);
    (new CaseEngine)->decide($exit->fresh(), 2, 'sent_back', $chandni, [], 'The payable was wrong', 1);

    return $exit->fresh();
}

/**
 * Every line on this exit, oldest first.
 *
 * @return Collection<int, SettlementLine>
 */
function linesOn(ProcessCase $exit): Collection
{
    return SettlementLine::query()->where('case_id', $exit->getKey())->orderBy('id')->get();
}

/** Chandni picks up finance on Deepak's exit and holds it over 40,000 for a salary advance. */
function chandniHoldsDeepaksFinanceOver40000(): ProcessCase
{
    $exit = deepaksExitAwaitingFinance();
    $chandni = owedAtMeridian('chandni');

    (new CaseEngine)->claim($exit, 3, $chandni);
    (new CaseEngine)->decide($exit->fresh(), 3, 'held', $chandni, [
        'imprest_card_returned' => false,
        'recover_from_them' => 40000,
        'recovery_reason' => 'advance',
    ], 'Deepak disputes the advance');

    return $exit->fresh();
}

/** HR's clearance and Deepak's own handover answered, so finance is all that stands before HR's final check. */
function everythingButFinanceOnDeepaks(ProcessCase $exit): void
{
    (new CaseEngine)->decide($exit->fresh(), 2, 'approved', owedAtMeridian('rakesh'), ['id_card_returned' => false]);
    (new CaseEngine)->decideThroughALink(basename((new StepLink)->issue($exit->fresh(), 4)), 'approved');
}

/** Somebody at Meridian with no exit yet, leaving on a process the test builds. */
function aLeaverOn(ProcessTemplate $process): ProcessCase
{
    $leaver = User::factory()->named('Farah Khan')->create(['personal_email' => 'farah@home.test']);

    EmploymentRecord::factory()->forPerson($leaver)->create();

    return (new CaseEngine)->open($process, $leaver, owedAtMeridian('chandni'));
}

/*
| The three ways a clearance passes
*/

it('writes a line for each figure Chandni files as she approves finance, in the words she was asked', function () {
    TenantContext::run($this->meridian, function () {
        $exit = deepaksExitAwaitingFinance();
        $chandni = owedAtMeridian('chandni');

        (new CaseEngine)->decide($exit, 3, 'approved', $chandni, [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
            'recovery_reason' => 'advance',
            'pay_to_them' => 1250.5,
        ]);

        $finance = CaseStep::query()->where('case_id', $exit->getKey())->where('sequence', 3)->sole();

        expect(linesOn($exit)->map->only(['case_step_id', 'field_key', 'entered_by', 'direction', 'label', 'reason', 'amount_paise'])->all())
            ->toEqual([
                [
                    'case_step_id' => $finance->getKey(), 'field_key' => 'recover_from_them',
                    'entered_by' => $chandni->getKey(), 'direction' => 'recovery',
                    'label' => 'Amount to recover from them', 'reason' => 'Salary advance',
                    'amount_paise' => 4000000,
                ],
                [
                    'case_step_id' => $finance->getKey(), 'field_key' => 'pay_to_them',
                    'entered_by' => $chandni->getKey(), 'direction' => 'payable',
                    'label' => 'Amount payable to them', 'reason' => null,
                    'amount_paise' => 125050,
                ],
            ]);
    });
});

it('writes nothing while finance is on hold, and the figure Chandni settled on when she closes it as disputed', function () {
    TenantContext::run($this->meridian, function () {
        $exit = chandniHoldsDeepaksFinanceOver40000();

        expect(linesOn($exit))->toBeEmpty();

        (new CaseEngine)->resolveHold($exit, 3, 'closed_disputed', owedAtMeridian('chandni'), 'Deepak disputes the figure', [
            'imprest_card_returned' => false,
            'recover_from_them' => 35000,
            'recovery_reason' => 'advance',
        ]);

        expect(linesOn($exit)->map->only(['direction', 'reason', 'amount_paise'])->all())
            ->toEqual([['direction' => 'recovery', 'reason' => 'Salary advance', 'amount_paise' => 3500000]]);
    });
});

it('writes the held figure in Chandni\'s name when Rakesh overrides her hold', function () {
    TenantContext::run($this->meridian, function () {
        $exit = chandniHoldsDeepaksFinanceOver40000();

        (new CaseEngine)->resolveHold($exit, 3, 'force_closed', owedAtMeridian('rakesh'), 'Chandni is on leave');

        expect(linesOn($exit)->map->only(['entered_by', 'amount_paise'])->all())
            ->toEqual([['entered_by' => owedAtMeridian('chandni')->getKey(), 'amount_paise' => 4000000]]);
    });
});

it('writes an overridden figure in the name of whoever put it on hold, after her work was handed over', function () {
    TenantContext::run($this->meridian, function () {
        $exit = chandniHoldsDeepaksFinanceOver40000();
        $chandni = owedAtMeridian('chandni');
        $priya = owedAtMeridian('priya');

        // Chandni leaves, and her held clearance goes to Rakesh with the rest of her work.
        $chandnis = (new CaseEngine)->open($exit->template, $chandni, $priya, now()->addDays(20)->toDateString(), 'resignation');
        Succession::handOver($chandnis, owedAtMeridian('rakesh'), $priya);

        (new CaseEngine)->resolveHold($exit->fresh(), 3, 'force_closed', $priya, 'Rakesh never saw the figure');

        // Rakesh holds the step now, but the 40,000 is Chandni's.
        expect(linesOn($exit)->map->only(['entered_by', 'amount_paise'])->all())
            ->toEqual([['entered_by' => $chandni->getKey(), 'amount_paise' => 4000000]]);
    });
});

/*
| A held figure taken back
*/

it('clears the figure Chandni held when she empties its box and approves, so nothing is owed', function () {
    TenantContext::run($this->meridian, function () {
        $exit = chandniHoldsDeepaksFinanceOver40000();
        $at = $exit->getKey();

        Livewire::actingAs(owedAtMeridian('chandni'))->test(MyQueue::class)
            ->assertSet("answers.{$at}.3.recover_from_them", 40000)
            ->set("answers.{$at}.3.recover_from_them", '')
            ->call('decide', $at, 3, 'approved')
            ->assertHasNoErrors()
            ->assertNotified('Recorded.');

        $finance = CaseStep::query()->where('case_id', $at)->where('sequence', 3)->sole();

        // The reason goes with it, since it is only asked once there is a figure to explain.
        expect($finance->outcome)->toBe('approved')
            ->and($finance->payload)->toEqual(['imprest_card_returned' => false])
            ->and(linesOn($exit))->toBeEmpty();
    });
});

it('keeps the box Chandni emptied empty when another box on the card is refused first', function () {
    TenantContext::run($this->meridian, function () {
        $exit = chandniHoldsDeepaksFinanceOver40000();
        $at = $exit->getKey();

        // The refusal leaves the card as she typed it, so her second press still sends the
        // box she emptied, and the 40,000 is not filed or claimed from Deepak.
        Livewire::actingAs(owedAtMeridian('chandni'))->test(MyQueue::class)
            ->set("answers.{$at}.3.recover_from_them", '')
            ->set("answers.{$at}.3.pay_to_them", '12.345')
            ->call('decide', $at, 3, 'approved')
            ->assertHasErrors(["answers.{$at}.3.pay_to_them"])
            ->assertSetStrict("answers.{$at}.3.recover_from_them", '')
            ->set("answers.{$at}.3.pay_to_them", '12.34')
            ->call('decide', $at, 3, 'approved')
            ->assertHasNoErrors()
            ->assertNotified('Recorded.');

        expect(linesOn($exit)->map->only(['direction', 'amount_paise'])->all())
            ->toEqual([['direction' => 'payable', 'amount_paise' => 1234]])
            ->and(claimsToldOn($exit))->toBeEmpty();
    });
});

it('files a held figure asked only once a document is attached, when the clearance is approved from the queue', function () {
    Storage::fake('local');

    TenantContext::run($this->meridian, function () {
        $form = FormDefinition::factory()->create(['name' => 'IT clearance']);
        FormField::factory()->on($form)->at(1)->asking('damage_report', 'Laptop damage report', FormField::File)->create();
        FormField::factory()->on($form)->at(2)->asking('damage_amount', 'Amount to recover for the damage', FormField::Money)
            ->askedWhen('damage_report', 'is_set')->settlingAs(SettlementLine::Recovery)->create();
        $form->publish();

        $process = ProcessTemplate::factory()->named('it_exit', 'IT exit')->about('employee')->create();
        ProcessStep::factory()->of($process)->at(1, 1)->named('IT clearance')->asking($form)
            ->heldBy('chandni@meridian.test')->clearance()->create();
        $process->publish();

        $exit = aLeaverOn($process);
        $chandni = owedAtMeridian('chandni');

        (new CaseEngine)->claim($exit, 1, $chandni);
        (new CaseEngine)->decide($exit->fresh(), 1, 'held', $chandni, [
            'damage_report' => UploadedFile::fake()->create('report.pdf', 40, 'application/pdf'),
            'damage_amount' => 15000,
        ], 'Waiting on the repair quote');

        // The card hands back every answer but the document it already holds.
        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->assertSetStrict("answers.{$exit->getKey()}.1.damage_amount", 15000)
            ->call('decide', $exit->getKey(), 1, 'approved')
            ->assertHasNoErrors()
            ->assertNotified('Recorded.');

        expect(linesOn($exit)->map->only(['direction', 'amount_paise'])->all())
            ->toEqual([['direction' => 'recovery', 'amount_paise' => 1500000]])
            ->and(claimsToldOn($exit))->toHaveCount(1);
    });
});

it('clears a held figure Chandni empties as she closes the clearance as disputed', function () {
    TenantContext::run($this->meridian, function () {
        $exit = chandniHoldsDeepaksFinanceOver40000();

        (new CaseEngine)->resolveHold($exit, 3, 'closed_disputed', owedAtMeridian('chandni'), 'Deepak disputes the whole claim', [
            'imprest_card_returned' => false,
            'recover_from_them' => null,
        ]);

        expect(linesOn($exit))->toBeEmpty();
    });
});

it('writes a line for a figure sent through a link, in nobody\'s name', function () {
    TenantContext::run($this->meridian, function () {
        $process = ProcessTemplate::factory()->named('leaver_claim', 'Leaver claim')->about('employee')->create();
        ProcessStep::factory()->of($process)->at(1, 1)->named('Leaver states the claim')
            ->collecting('claim_amount', FormField::Money, SettlementLine::Recovery)->external()->offering('approved')->create();
        $process->publish();

        $exit = aLeaverOn($process);

        (new CaseEngine)->decideThroughALink(basename((new StepLink)->issue($exit, 1)), 'approved', ['claim_amount' => 900]);

        expect(linesOn($exit)->map->only(['entered_by', 'amount_paise'])->all())
            ->toEqual([['entered_by' => null, 'amount_paise' => 90000]]);
    });
});

it('writes the line in the same write as the answer, so a failure undoes both', function () {
    TenantContext::run($this->meridian, function () {
        $exit = deepaksExitAwaitingFinance();

        SettlementLine::created(fn () => throw new RuntimeException('The write failed.'));

        expect(fn () => (new CaseEngine)->decide($exit, 3, 'approved', owedAtMeridian('chandni'), [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
        ]))->toThrow(RuntimeException::class, 'The write failed.');

        expect(linesOn($exit))->toBeEmpty()
            ->and(CaseStep::query()->where('case_id', $exit->getKey())->where('sequence', 3)->exists())->toBeFalse();
    });
});

/*
| A correction is the clearance answered again
*/

it('keeps the first figure on the attempt a send-back replaced, and counts only the new one', function () {
    TenantContext::run($this->meridian, function () {
        $exit = financeSentBackAfterClaiming40000();
        $chandni = owedAtMeridian('chandni');

        (new CaseEngine)->decide($exit, 1, 'approved', $chandni, ['claim_amount' => 35000]);

        $attempts = CaseStep::query()->where('case_id', $exit->getKey())->where('sequence', 1)->orderBy('id')->get();

        expect(linesOn($exit)->map(fn (SettlementLine $line) => [$line->case_step_id, $line->amount_paise])->all())
            ->toEqual([[$attempts[0]->getKey(), 4000000], [$attempts[1]->getKey(), 3500000]])
            ->and($attempts[0]->superseded_at)->not->toBeNull()
            ->and($attempts[1]->superseded_at)->toBeNull();

        // The replaced attempt's line is history, not a figure still owed a line, so the exit finishes.
        (new CaseEngine)->decide($exit->fresh(), 2, 'approved', $chandni);

        expect($exit->fresh()->closed_at)->not->toBeNull();
    });
});

/*
| Nobody changes a line
*/

it('refuses a change to a line at the database', function () {
    TenantContext::run($this->meridian, function () {
        (new CaseEngine)->decide(deepaksExitAwaitingFinance(), 3, 'approved', owedAtMeridian('chandni'), [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
        ]);

        DB::table('settlement_lines')->update(['amount_paise' => 1]);
    });
})->throws(QueryException::class, 'settlement_lines is add-only');

it('refuses a removal of a line at the database', function () {
    TenantContext::run($this->meridian, function () {
        (new CaseEngine)->decide(deepaksExitAwaitingFinance(), 3, 'approved', owedAtMeridian('chandni'), [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
        ]);

        DB::table('settlement_lines')->delete();
    });
})->throws(QueryException::class, 'settlement_lines is add-only');

it('refuses to empty the lines table wholesale', function () {
    DB::statement('truncate settlement_lines');
})->throws(QueryException::class, 'settlement_lines is add-only');

/*
| Only the questions marked as money owed one way or the other
*/

it('writes no line for Vertex\'s net amount, a total typed by hand, beside the recovery it nets', function () {
    $this->seed(VertexSeeder::class);

    TenantContext::run(Tenant::query()->where('slug', VertexSeeder::Slug)->sole(), function () {
        $nehas = ProcessCase::query()->sole();

        (new CaseEngine)->decide($nehas, 6, 'approved', User::query()->where('work_email', 'sanjay@vertex.test')->sole(), [
            'imprest_card_recovered' => true,
            'finance_recovery_amount' => 100,
            'finance_recovery_reason' => 'Canteen dues',
            'finance_net_amount' => 5000,
        ]);

        expect(linesOn($nehas)->map->only(['field_key', 'reason', 'amount_paise'])->all())
            ->toEqual([['field_key' => 'finance_recovery_amount', 'reason' => 'Canteen dues', 'amount_paise' => 10000]]);
    });
});

it('finishes an exit whose only figure was never marked as money owed', function () {
    TenantContext::run($this->meridian, function () {
        $process = ProcessTemplate::factory()->named('exit_with_a_total', 'Exit')->about('employee')->create();
        ProcessStep::factory()->of($process)->at(1, 1)->named('Finance clearance')
            ->collecting('net_amount')
            ->heldByTheRoleAnywhere('finance_head')->offering('approved')->create();
        $process->publish();

        $exit = aLeaverOn($process);

        (new CaseEngine)->decide($exit, 1, 'approved', owedAtMeridian('chandni'), ['net_amount' => 5000]);

        expect(linesOn($exit))->toBeEmpty()
            ->and($exit->fresh()->closed_at)->not->toBeNull();
    });
});

it('refuses marking a question that is not money as owed', function () {
    TenantContext::run($this->meridian, function () {
        FormField::factory()->asking('laptops_out', 'Laptops not returned', FormField::Number)->settlingAs('recovery')->create();
    });
})->throws(QueryException::class, 'form_fields_only_money_is_owed');

/*
| The one gate on finishing
*/

it('refuses HR\'s final check while a figure finance filed never became a line', function () {
    TenantContext::run($this->meridian, function () {
        $exit = deepaksExitAwaitingFinance();
        everythingButFinanceOnDeepaks($exit);

        // Filed the way an exit filed before this module was: approved, a figure on it, no line.
        CaseStep::factory()->of($exit)->at(3)->heldBy(owedAtMeridian('chandni'))->decided('approved')
            ->create(['payload' => ['imprest_card_returned' => false, 'recover_from_them' => 40000]]);

        expect(fn () => (new CaseEngine)->decide($exit->fresh(), 5, 'approved', owedAtMeridian('rakesh')))
            ->toThrow(ProcessRefused::class, '[Amount to recover from them] on [Finance clearance]');

        expect($exit->fresh()->closed_at)->toBeNull()
            ->and(CaseStep::query()->where('case_id', $exit->getKey())->where('sequence', 5)->exists())->toBeFalse();
    });
});

it('lets the exit finish with a disputed line on it', function () {
    TenantContext::run($this->meridian, function () {
        $exit = chandniHoldsDeepaksFinanceOver40000();
        everythingButFinanceOnDeepaks($exit);

        (new CaseEngine)->resolveHold($exit->fresh(), 3, 'closed_disputed', owedAtMeridian('chandni'), 'Deepak disputes the figure');
        (new CaseEngine)->decide($exit->fresh(), 5, 'approved', owedAtMeridian('rakesh'));

        expect($exit->fresh()->closed_at)->not->toBeNull()
            ->and(linesOn($exit))->toHaveCount(1);
    });
});

/*
| Whole paise
*/

it('refuses a figure with three decimal places, and takes one with two', function () {
    TenantContext::run($this->meridian, function () {
        $exit = deepaksExitAwaitingFinance();
        $chandni = owedAtMeridian('chandni');
        $answers = ['imprest_card_returned' => false, 'recover_from_them' => 1234.567];

        expect(fn () => (new CaseEngine)->decide($exit, 3, 'approved', $chandni, $answers))
            ->toThrow(ProcessRefused::class, 'Amount to recover from them field must have 0-2 decimal places');

        expect(linesOn($exit))->toBeEmpty();

        (new CaseEngine)->decide($exit->fresh(), 3, 'approved', $chandni, [...$answers, 'recover_from_them' => 1234.56]);

        expect(linesOn($exit)->sole()->amount_paise)->toBe(123456);
    });
});

it('refuses a figure typed with digits too many, and takes the largest it allows to the last paisa', function () {
    TenantContext::run($this->meridian, function () {
        $exit = deepaksExitAwaitingFinance();
        $chandni = owedAtMeridian('chandni');
        $answers = ['imprest_card_returned' => false, 'recover_from_them' => '100000000000000000'];

        expect(fn () => (new CaseEngine)->decide($exit, 3, 'approved', $chandni, $answers))
            ->toThrow(ProcessRefused::class, 'Amount to recover from them field must not be greater than 999999999999.99');

        expect(linesOn($exit))->toBeEmpty();

        (new CaseEngine)->decide($exit->fresh(), 3, 'approved', $chandni, [...$answers, 'recover_from_them' => '999999999999.99']);

        expect(linesOn($exit)->sole()->amount_paise)->toBe(99999999999999);
    });
});

it('turns figures with paise in them into whole paise that add up to the last paisa', function () {
    $this->seed(VertexSeeder::class);

    TenantContext::run(Tenant::query()->where('slug', VertexSeeder::Slug)->sole(), function () {
        $nehas = ProcessCase::query()->sole();

        // 0.29 and 1.15 are the two a cast gets wrong: a hundred times each is a hair under 29
        // and 115, and a cast throws the hair away with the paisa.
        (new CaseEngine)->decide($nehas, 7, 'approved', User::query()->where('work_email', 'meera@vertex.test')->sole(), [
            'hr_id_card_returned' => true,
            'hr_biometric_deactivated' => true,
            'notice_recovery_amount' => 0.29,
            'telecom_recovery_amount' => 1234.56,
            'hr_other_recovery_amount' => 19.99,
            'hr_other_reason' => 'Uniform',
            'encashment_payable_amount' => 1.15,
            'notice_payable_amount' => '7.1',
        ]);

        $paise = fn (string $direction): int => (int) linesOn($nehas)->where('direction', $direction)->sum('amount_paise');

        // 0.29 + 1234.56 + 19.99 = 1254.84, and 1.15 + 7.10 = 8.25, worked by hand.
        expect($paise('recovery'))->toBe(125484)
            ->and($paise('payable'))->toBe(825)
            ->and(linesOn($nehas)->pluck('amount_paise', 'field_key')->all())->toEqual([
                'notice_recovery_amount' => 29,
                'notice_payable_amount' => 710,
                'encashment_payable_amount' => 115,
                'telecom_recovery_amount' => 123456,
                'hr_other_recovery_amount' => 1999,
            ]);
    });
});

/*
| The wall between client companies
*/

it('shows each client company its own lines and nobody else\'s, with no scope in the way', function () {
    $this->seed(VertexSeeder::class);
    $vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    TenantContext::run($this->meridian, function () {
        (new CaseEngine)->decide(deepaksExitAwaitingFinance(), 3, 'approved', owedAtMeridian('chandni'), [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
        ]);
    });

    TenantContext::run($vertex, function () {
        (new CaseEngine)->decide(ProcessCase::query()->sole(), 6, 'approved', User::query()->where('work_email', 'sanjay@vertex.test')->sole(), [
            'imprest_card_recovered' => true,
            'finance_payable_amount' => 300,
        ]);
    });

    $seenBy = fn (Tenant $company): array => TenantContext::run($company, fn (): array => array_map(
        fn (object $row): array => [(int) $row->tenant_id, (int) $row->amount_paise],
        DB::select('select tenant_id, amount_paise from settlement_lines'),
    ));

    expect($seenBy($this->meridian))->toBe([[$this->meridian->getKey(), 4000000]])
        ->and($seenBy($vertex))->toBe([[$vertex->getKey(), 30000]]);
});

/*
| Step 2: the leaver is told of each claim
*/

it('tells Deepak at his own address of the 40,000 claimed against him, dated on his company\'s calendar, and nothing of what he is owed', function () {
    // Half past one in the morning at Meridian, and still the evening before in UTC.
    $this->travelTo($at = CarbonImmutable::parse('tomorrow 20:00', 'UTC'));

    TenantContext::run($this->meridian, function () use ($at) {
        $exit = deepaksExitAwaitingFinance();

        (new CaseEngine)->decide($exit, 3, 'approved', owedAtMeridian('chandni'), [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
            'recovery_reason' => 'advance',
            'pay_to_them' => 1250.5,
        ]);

        $told = claimsToldOn($exit)->sole();

        expect($told->recipient_address)->toBe('deepak@personal.example')
            ->and($told->step_sequence)->toBe(3)
            ->and($told->status)->toBe(NotificationDelivery::Sent)
            ->and($told->subject)->toBe('A claim of ₹40,000.00 has been raised against you')
            ->and($told->body)->toContain('Finance clearance has claimed ₹40,000.00 against you on '.$at->addDay()->format('j F Y').', for this reason: Salary advance');
    });
});

it('tells Deepak nothing while finance is held, and of the figure Chandni settled on when she closes it as disputed', function () {
    TenantContext::run($this->meridian, function () {
        $exit = chandniHoldsDeepaksFinanceOver40000();

        expect(claimsToldOn($exit))->toBeEmpty();

        (new CaseEngine)->resolveHold($exit, 3, 'closed_disputed', owedAtMeridian('chandni'), 'Deepak disputes the figure', [
            'imprest_card_returned' => false,
            'recover_from_them' => 35000,
            'recovery_reason' => 'advance',
        ]);

        expect(claimsToldOn($exit)->pluck('subject')->all())->toBe(['A claim of ₹35,000.00 has been raised against you']);
    });
});

it('tells Deepak of the held figure when Rakesh overrides the hold', function () {
    TenantContext::run($this->meridian, function () {
        $exit = chandniHoldsDeepaksFinanceOver40000();

        (new CaseEngine)->resolveHold($exit, 3, 'force_closed', owedAtMeridian('rakesh'), 'Chandni is on leave');

        expect(claimsToldOn($exit)->pluck('subject')->all())->toBe(['A claim of ₹40,000.00 has been raised against you']);
    });
});

it('tells the leaver of a claim filed through a link', function () {
    TenantContext::run($this->meridian, function () {
        $process = ProcessTemplate::factory()->named('leaver_claim', 'Leaver claim')->about('employee')->create();
        ProcessStep::factory()->of($process)->at(1, 1)->named('Leaver states the claim')
            ->collecting('claim_amount', FormField::Money, SettlementLine::Recovery)->external()->offering('approved')->create();
        $process->publish();

        $exit = aLeaverOn($process);

        (new CaseEngine)->decideThroughALink(basename((new StepLink)->issue($exit, 1)), 'approved', ['claim_amount' => 900]);

        expect(claimsToldOn($exit)->map->only(['recipient_address', 'subject'])->all())
            ->toEqual([['recipient_address' => 'farah@home.test', 'subject' => 'A claim of ₹900.00 has been raised against you']]);
    });
});

it('tells Neha of each claim on its own, and reads a claim with no reason as the question it answered', function () {
    $this->seed(VertexSeeder::class);

    TenantContext::run(Tenant::query()->where('slug', VertexSeeder::Slug)->sole(), function () {
        $nehas = ProcessCase::query()->sole();

        (new CaseEngine)->decide($nehas, 7, 'approved', User::query()->where('work_email', 'meera@vertex.test')->sole(), [
            'hr_id_card_returned' => true,
            'hr_biometric_deactivated' => true,
            'notice_recovery_amount' => 500,
            'hr_other_recovery_amount' => 19.99,
            'hr_other_reason' => 'Uniform',
            'encashment_payable_amount' => 1.15,
        ]);

        $told = claimsToldOn($nehas)->pluck('body');

        expect($told)->toHaveCount(2)
            ->and($told[0])->toContain('HR clearance has claimed ₹500.00 against you')
            ->toContain('for this reason: Notice period shortfall to recover')
            ->and($told[1])->toContain('HR clearance has claimed ₹19.99 against you')
            ->toContain('for this reason: Uniform');
    });
});

it('tells the leaver of a claim answered again after a send-back only where it changed', function (?int $again, array $subjects, string $saying) {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'Asia/Kolkata'));

    TenantContext::run($this->meridian, function () use ($again, $subjects, $saying) {
        $exit = financeSentBackAfterClaiming40000();

        (new CaseEngine)->decide($exit, 1, 'approved', owedAtMeridian('chandni'), ['claim_amount' => $again]);

        $told = claimsToldOn($exit);

        expect($told->pluck('subject')->all())->toBe($subjects)
            ->and($told->last()->body)->toContain($saying);
    });
})->with([
    'the same figure' => [40000, ['A claim of ₹40,000.00 has been raised against you'], 'Finance clearance has claimed ₹40,000.00 against you'],
    'a new figure' => [35000, [
        'A claim of ₹40,000.00 has been raised against you',
        'A claim against you has changed from ₹40,000.00 to ₹35,000.00',
    ], 'Finance clearance has changed its claim against you from ₹40,000.00 to ₹35,000.00 on 24 November 2026. It was raised for this reason: Claim amount'],
    'no figure' => [null, [
        'A claim of ₹40,000.00 has been raised against you',
        'A claim against you has changed from ₹40,000.00 to nothing',
    ], 'from ₹40,000.00 to nothing on 24 November 2026'],
]);

it('tells the leaver nothing new while the clearance sent back is on hold', function () {
    TenantContext::run($this->meridian, function () {
        $exit = financeSentBackAfterClaiming40000();
        $chandni = owedAtMeridian('chandni');

        (new CaseEngine)->claim($exit, 1, $chandni);
        (new CaseEngine)->decide($exit->fresh(), 1, 'held', $chandni, [], 'Checking the advance again');

        expect(claimsToldOn($exit))->toHaveCount(1);
    });
});

it('tells Deepak nothing when the answer that filed the claim is undone', function () {
    TenantContext::run($this->meridian, function () {
        $exit = deepaksExitAwaitingFinance();

        // The answer's own history is written after its lines, so this fails with the claim
        // already owed a message.
        CaseEvent::created(function (CaseEvent $event): void {
            if ($event->type === 'step_acted') {
                throw new RuntimeException('The write failed.');
            }
        });

        Mail::mailer()->getSymfonyTransport()->flush();

        expect(fn () => (new CaseEngine)->decide($exit, 3, 'approved', owedAtMeridian('chandni'), [
            'imprest_card_returned' => false,
            'recover_from_them' => 40000,
        ]))->toThrow(RuntimeException::class, 'The write failed.');

        // Read off the mailer rather than the delivery records, which the undone write takes
        // with it whether or not the message went.
        expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    });
});
