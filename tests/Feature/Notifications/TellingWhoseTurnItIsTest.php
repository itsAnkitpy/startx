<?php

use App\Filament\Pages\RaiseARequest;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TemplateCatalogue;
use App\Process\CaseEngine;
use App\Process\StepLink;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| "Your turn", and whoever raised a case hearing about every answer on it.
|
| Run against the demo company seeded at nine on Monday 16 November 2026, India's clock.
| Both are told by the engine at the moment of the answer that causes them — not by the
| scheduled pass — so a step already waiting when this went live is never announced.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Kolkata'));

    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    Mail::mailer()->getSymfonyTransport()->flush();
});

function turnColleague(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/**
 * Every message that has left since this was last asked, as its address and subject line.
 *
 * @return list<string>
 */
function turnMailThatLeft(): array
{
    $transport = Mail::mailer()->getSymfonyTransport();

    $left = $transport->messages()
        ->map(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress()
            .' — '.$sent->getOriginalMessage()->getSubject())
        ->values()->all();

    $transport->flush();

    return $left;
}

/** The body of the last message that left for one address, narrowed by its subject line. */
function turnBodyTo(string $address, string $subjectSays = ''): string
{
    return (string) Mail::mailer()->getSymfonyTransport()->messages()
        ->filter(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress() === $address
            && str_contains((string) $sent->getOriginalMessage()->getSubject(), $subjectSays))
        ->last()
        ?->getOriginalMessage()->getTextBody();
}

/** A hiring request Anjali raises the way the raise screen does, for a salary of her choosing. */
function turnHiringRequestCosting(int $salary): ProcessCase
{
    $hiring = ProcessTemplate::query()->where('key', 'hiring_request')->where('status', ProcessTemplate::Published)->sole();
    $anjali = turnColleague('anjali');

    return DB::transaction(function () use ($hiring, $anjali, $salary): ProcessCase {
        $engine = new CaseEngine;
        $request = $engine->open($hiring, by: $anjali);

        $engine->decide($request, 1, 'approved', $anjali, [
            'department' => DB::table('org_units')->where('name', 'Shimla branch')->value('id'),
            'designation' => DB::table('designations')->where('name', 'Operations Officer')->value('id'),
            'replaces_a_leaver' => 'replacement',
            'positions' => 1,
            'annual_ctc' => $salary,
            'employment_type' => 'permanent',
            'target_start_date' => '2027-01-15',
            'justification' => 'A second shift at the Shimla depot.',
        ]);

        return $request;
    });
}

/**
 * A request any administrator may raise, so its first step belongs to Chandni and Priya
 * both, and whose second step is Chandni's as finance.
 */
function turnRequestAnyAdministratorRaises(): ProcessTemplate
{
    $request = ProcessTemplate::factory()->named('equipment_request', 'Equipment request')->about('none')->create();
    ProcessStep::factory()->of($request)->at(1, 1)->named('Raise the request')->heldByTheRoleAnywhere('administrator')->offering('approved')->create();
    ProcessStep::factory()->of($request)->at(2, 2)->named('Finance approval')->heldByTheRoleAnywhere('finance_head')->offering('approved')->create();
    $request->publish();

    return $request;
}

it('tells the next group its turn once when the last two steps before it close in the same second', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit_two_at_once', 'Exit, two clearances at once')->about('employee')->create();
        ProcessStep::factory()->of($exit)->at(1, 1)->named('HR clearance')->heldByTheRole('hr_head')->offering('approved')->create();
        ProcessStep::factory()->of($exit)->at(2, 1)->named('Finance clearance')->heldByTheRoleAnywhere('finance_head')->offering('approved')->create();
        ProcessStep::factory()->of($exit)->at(3, 2)->named('Manager sign-off')->offering('approved')
            ->state(['assignee_rule' => ['kind' => 'reporting_manager']])->create();
        $exit->publish();

        // Opening it tells both HR heads over Shimla. Not Chandni, who holds finance and has
        // just pressed the button.
        $case = (new CaseEngine)->open($exit, turnColleague('deepak'), turnColleague('chandni'));
        $number = '#'.$case->number;

        expect(collect(turnMailThatLeft())->filter(fn (string $sent) => str_contains($sent, 'is waiting on you'))->values()->all())
            ->toEqualCanonicalizing([
                "rakesh@meridian.test — HR clearance is waiting on you — {$number}",
                "priya@meridian.test — HR clearance is waiting on you — {$number}",
            ]);

        // Both clearances answered in the same second. The first leaves the group open, so
        // nobody is next yet; the second closes it, and Rakesh, Deepak's manager, is told once.
        (new CaseEngine)->decide($case, 1, 'approved', turnColleague('priya'));
        expect(collect(turnMailThatLeft())->filter(fn (string $sent) => str_contains($sent, 'is waiting on you'))->all())->toBe([]);

        (new CaseEngine)->decide($case, 2, 'approved', turnColleague('chandni'));
        expect(collect(turnMailThatLeft())->filter(fn (string $sent) => str_contains($sent, 'is waiting on you'))->values()->all())
            ->toBe(["rakesh@meridian.test — Manager sign-off is waiting on you — {$number}"]);

        // And the scheduled pass, which used to be where this was going to live, adds nothing.
        Artisan::call('steps:chase');

        expect(NotificationDelivery::query()
            ->where('case_id', $case->getKey())
            ->where('template_key', TemplateCatalogue::StepAvailableHolder)
            ->where('step_sequence', 3)
            ->count())->toBe(1);
    });
});

it('tells somebody covering whose work it is, and gives a due date only where a step has one', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh is away and Priya holds his hiring approvals. A hiring request is about
        // nobody, so there is no date to give. Anjali raised and answered the first step
        // herself, so she is told nothing about it.
        $request = turnHiringRequestCosting(900000);

        expect(turnBodyTo('priya@meridian.test'))
            ->toContain("is now waiting on you, on Rakesh Menon's behalf while you are covering for them.")
            ->not->toContain('due by');
        expect(turnMailThatLeft())->toBe(['priya@meridian.test — Line-of-business approval is waiting on you — #'.$request->number]);

        // Deepak's exit does have a clock: finance's two working days start the moment HR
        // clears it.
        (new CaseEngine)->decide(ProcessCase::query()->whereRelation('subject', 'first_name', 'Deepak')->sole(), 1, 'approved', turnColleague('priya'), ['id_card_returned' => true]);

        expect(turnBodyTo('chandni@meridian.test', 'is waiting on you'))
            ->toContain('Finance clearance on #')
            ->toContain('is now waiting on you. It is due by 18 November 2026, 9:00 am.');
    });
});

it('tells whoever raised a request about every answer on it, and a turning down with its reason', function () {
    TenantContext::run($this->meridian, function () {
        $request = turnHiringRequestCosting(2400000);
        $number = '#'.$request->number;
        turnMailThatLeft();

        (new CaseEngine)->decide($request, 2, 'approved', turnColleague('priya'));

        expect(turnBodyTo('anjali@meridian.test'))
            ->toContain('was approved by Priya Nair.')
            ->toContain('It is now waiting on Director approval.');
        expect(turnMailThatLeft())->toEqualCanonicalizing([
            "anjali@meridian.test — {$number} — Line-of-business approval was approved",
            "chandni@meridian.test — Director approval is waiting on you — {$number}",
        ]);

        (new CaseEngine)->decide($request, 3, 'rejected', turnColleague('chandni'), reason: 'No budget for a second shift until April.');

        expect(turnBodyTo('anjali@meridian.test'))->toContain('The reason given was: No budget for a second shift until April.');
        expect(turnMailThatLeft())->toBe(["anjali@meridian.test — {$number} has been turned down"]);
    });
});

it('tells whoever raised it about a send-back, and the step it went back to is somebody turn again', function () {
    TenantContext::run($this->meridian, function () {
        $request = turnHiringRequestCosting(2400000);
        $number = '#'.$request->number;

        (new CaseEngine)->decide($request, 2, 'approved', turnColleague('priya'));
        turnMailThatLeft();

        // Chandni sends it back to the branch. Priya was told about that step once already;
        // it is hers again, so she is told again.
        (new CaseEngine)->decide($request, 3, 'sent_back', turnColleague('chandni'), reason: 'Is this a new post or a replacement?', sendBackTo: 2);

        expect(turnMailThatLeft())->toEqualCanonicalizing([
            "anjali@meridian.test — {$number} — Director approval was sent back to Line-of-business approval",
            "priya@meridian.test — Line-of-business approval is waiting on you — {$number}",
        ]);
    });
});

it('tells whoever raised an exit about a hold, and never tells the person an exit is about', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = ProcessCase::query()->whereRelation('subject', 'first_name', 'Deepak')->sole();
        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // Rakesh raised Deepak's; Anjali handed in her own resignation through the product.
        ProcessCase::query()->whereKey($deepaks->getKey())->update(['initiated_by' => turnColleague('rakesh')->getKey()]);
        ProcessCase::query()->whereKey($anjalis->getKey())->update(['initiated_by' => turnColleague('anjali')->getKey()]);

        (new CaseEngine)->decide($deepaks->refresh(), 1, 'approved', turnColleague('priya'), ['id_card_returned' => true]);
        turnMailThatLeft();

        (new CaseEngine)->decide($deepaks, 2, 'held', turnColleague('chandni'), ['imprest_card_returned' => false], 'The imprest card has not come back.');

        expect(turnBodyTo('rakesh@meridian.test'))
            ->toContain('was put on hold by Chandni Verma.')
            ->toContain('It is now waiting on Finance clearance.');
        expect(turnMailThatLeft())->toBe(['rakesh@meridian.test — #'.$deepaks->number.' — Finance clearance was put on hold']);

        (new CaseEngine)->decide($anjalis->refresh(), 1, 'approved', turnColleague('priya'), ['id_card_returned' => false]);

        expect(collect(turnMailThatLeft())->filter(fn (string $sent) => str_starts_with($sent, 'anjali'))->all())->toBe([]);
    });
});

it('sends a step only a link can answer its link the moment it opens, and tells nobody here', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = ProcessCase::query()->whereRelation('subject', 'first_name', 'Deepak')->sole();
        $engine = new CaseEngine;

        $engine->decide($deepaks, 1, 'approved', turnColleague('priya'), ['id_card_returned' => true]);
        $engine->decide($deepaks, 2, 'approved', turnColleague('chandni'), ['imprest_card_returned' => true]);
        turnMailThatLeft();

        $engine->decide($deepaks, 3, 'approved', turnColleague('rakesh'));

        expect(turnMailThatLeft())->toEqualCanonicalizing([
            'deepak@personal.example — Meridian Logistics — Leaver confirms the handover',
            'chandni@meridian.test — #'.$deepaks->number.' — Manager sign-off was approved',
        ]);
    });
});

it('tells nobody it is their turn on a first step the raise screen has just answered', function () {
    TenantContext::run($this->meridian, function () {
        $request = turnRequestAnyAdministratorRaises();

        // Raising it is Priya answering its first step, which Chandni holds as well. Chandni
        // hears that finance's approval is hers, and never that the first step is.
        Livewire::actingAs(turnColleague('priya'))->test(RaiseARequest::class)
            ->set('processId', $request->getKey())
            ->call('raise')
            ->assertHasNoErrors();

        $number = '#'.ProcessCase::query()->where('template_id', $request->getKey())->sole()->number;

        expect(turnMailThatLeft())->toBe(["chandni@meridian.test — Finance approval is waiting on you — {$number}"]);
    });
});

it('sends nothing about a request whose raise is undone before it is saved', function () {
    TenantContext::run($this->meridian, function () {
        $request = turnRequestAnyAdministratorRaises();

        // The raise screen saves a request and its first answer as one write. Undone once
        // the request is open, the news it had for Chandni goes with it.
        expect(fn () => DB::transaction(function () use ($request): void {
            (new CaseEngine)->open($request, by: turnColleague('priya'));

            throw new RuntimeException('The raise came apart.');
        }))->toThrow(RuntimeException::class, 'The raise came apart.');

        expect(turnMailThatLeft())->toBe([]);
    });
});

it('never tells somebody who raised their own exit that it was turned down', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh handed in his own resignation, then turns down his own handover through
        // the link. The person an exit is about is told three things, and this is not one.
        $rakeshs = ProcessCase::query()
            ->whereRelation('subject', 'work_email', 'rakesh@meridian.test')
            ->whereRelation('template', 'key', 'exit')
            ->sole();
        ProcessCase::query()->whereKey($rakeshs->getKey())->update(['initiated_by' => turnColleague('rakesh')->getKey()]);

        $token = basename((new StepLink)->issue($rakeshs->refresh(), 4));
        turnMailThatLeft();

        (new CaseEngine)->decideThroughALink($token, 'rejected');

        expect($rakeshs->fresh()->closed_at)->not->toBeNull()
            ->and(turnMailThatLeft())->toBe([]);
    });
});

it('rewords the two messages for a client still on our words, and leaves words a client wrote alone', function () {
    TenantContext::run($this->meridian, function () {
        NotificationTemplate::query()->where('key', TemplateCatalogue::StepAvailableHolder)->toBase()
            ->update(['body' => 'It is due by {{ step.due_at }}.', 'reworded' => false]);
        NotificationTemplate::query()->where('key', TemplateCatalogue::CaseMovedInitiator)->toBase()
            ->update(['body' => 'Our own words, {{ step.name }}.', 'reworded' => true]);
    });

    (require database_path('migrations/2026_11_16_105352_word_your_turn_and_a_moved_request_for_every_answer.php'))->up();

    TenantContext::run($this->meridian, function () {
        expect(NotificationTemplate::query()->where('key', TemplateCatalogue::StepAvailableHolder)->sole()->body)
            ->toBe(TemplateCatalogue::definitionOf(TemplateCatalogue::StepAvailableHolder)['body'])
            ->and(NotificationTemplate::query()->where('key', TemplateCatalogue::CaseMovedInitiator)->sole()->body)
            ->toBe('Our own words, {{ step.name }}.');
    });
});
