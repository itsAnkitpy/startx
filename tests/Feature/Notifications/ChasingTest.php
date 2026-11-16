<?php

use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Models\CaseStep;
use App\Models\Delegation;
use App\Models\NotificationDelivery;
use App\Models\Office;
use App\Models\OfficeHoliday;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TemplateCatalogue;
use App\Process\AssigneeResolver;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| Chasing a late step: the one scheduled pass, and the button on a case.
|
| Run against the demo company seeded at nine on Monday 16 November 2026, India's clock, so
| every time below is a time somebody at Meridian would read. Deepak's exit opens then, and
| its HR clearance — Rakesh's and Priya's, two working days — is half gone at nine on
| Tuesday, three-quarters gone at nine that evening, and due at nine on Wednesday.
*/

beforeEach(function () {
    $this->travelTo(onMeridiansClock('2026-11-16 09:00'));

    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    Mail::mailer()->getSymfonyTransport()->flush();
    TenantContext::run($this->meridian, fn () => NotificationDelivery::query()->delete());
});

function onMeridiansClock(string $wallTime): CarbonImmutable
{
    return CarbonImmutable::parse($wallTime, 'Asia/Kolkata');
}

function meridianColleague(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

function meridianExitOf(string $first): ProcessCase
{
    return ProcessCase::query()
        ->whereRelation('subject', 'work_email', $first.'@meridian.test')
        ->whereRelation('template', 'key', 'exit')
        ->sole();
}

/** The scheduled pass, run once, as the scheduler runs it. */
function chaseLateSteps(): void
{
    Artisan::call('steps:chase');
}

/**
 * Every message that has left since this was last asked, as its address and subject line —
 * about one case where one is named, because the demo's other exits are being chased too.
 *
 * @return list<string>
 */
function chasesThatLeft(?ProcessCase $about = null): array
{
    $transport = Mail::mailer()->getSymfonyTransport();

    $left = $transport->messages()
        ->filter(fn ($sent) => $about === null || str_ends_with($sent->getOriginalMessage()->getSubject(), '#'.$about->number))
        ->map(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress()
            .' — '.$sent->getOriginalMessage()->getSubject())
        ->values()->all();

    $transport->flush();

    return $left;
}

/** The body of the last message that left for one address. */
function lastChaseTo(string $address): string
{
    return (string) Mail::mailer()->getSymfonyTransport()->messages()
        ->filter(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress() === $address)
        ->last()
        ?->getOriginalMessage()->getTextBody();
}

it('reminds a clearance nobody has opened, twice, then tells whoever is above it, and never writes a step row', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridianExitOf('deepak');
        $number = '#'.$exit->number;

        $this->travelTo(onMeridiansClock('2026-11-17 08:59'));
        chaseLateSteps();
        expect(chasesThatLeft($exit))->toBe([]);

        // Half way. Meridian rewrote this message, and its words are the ones that go.
        $this->travelTo(onMeridiansClock('2026-11-17 09:00'));
        chaseLateSteps();
        expect(lastChaseTo('rakesh@meridian.test'))->toContain('is still open and is due by 18 November 2026, 9:00 am');
        expect(chasesThatLeft($exit))->toEqualCanonicalizing([
            "rakesh@meridian.test — Second ask: HR clearance on {$number}",
            "priya@meridian.test — Second ask: HR clearance on {$number}",
        ]);

        $this->travelTo(onMeridiansClock('2026-11-17 21:00'));
        chaseLateSteps();
        expect(chasesThatLeft($exit))->toHaveCount(2);

        // At the deadline it goes above them, to the HR director the step escalates to.
        $this->travelTo(onMeridiansClock('2026-11-18 09:00'));
        chaseLateSteps();
        expect(lastChaseTo('chandni@meridian.test'))->toContain('It is waiting on Rakesh Menon and Priya Nair.');
        expect(chasesThatLeft($exit))->toBe(["chandni@meridian.test — Out of time — HR clearance on {$number}"]);

        $this->travelTo(onMeridiansClock('2026-11-18 15:00'));
        chaseLateSteps();
        expect(chasesThatLeft($exit))->toBe([]);

        expect(CaseStep::query()->where('case_id', $exit->getKey())->where('sequence', 1)->exists())->toBeFalse()
            ->and(NotificationDelivery::query()->where('case_id', $exit->getKey())->where('step_sequence', 1)->count())->toBe(5);
    });
});

it('measures a step from when it became available, not from when the case opened', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridianExitOf('deepak');

        // HR clears it at three on Monday afternoon, so finance's two working days start then.
        $this->travelTo(onMeridiansClock('2026-11-16 15:00'));
        (new CaseEngine)->decide($exit, 1, 'approved', meridianColleague('priya'), ['id_card_returned' => true]);
        chasesThatLeft();

        $this->travelTo(onMeridiansClock('2026-11-17 14:59'));
        chaseLateSteps();
        expect(chasesThatLeft($exit))->toBe([]);

        $this->travelTo(onMeridiansClock('2026-11-17 15:00'));
        chaseLateSteps();
        expect(chasesThatLeft($exit))->toBe(['chandni@meridian.test — Second ask: Finance clearance on #'.$exit->number]);
    });
});

it('chases whoever is covering for somebody away, says whose work it is, and leaves the person away alone', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridianExitOf('deepak');

        Delegation::query()->create([
            'user_id' => meridianColleague('rakesh')->getKey(),
            'delegate_id' => meridianColleague('rohit')->getKey(),
            'process_key' => 'exit',
            'effective_from' => '2026-11-16',
            'effective_to' => '2026-11-30',
        ]);

        $this->travelTo(onMeridiansClock('2026-11-17 09:00'));
        chaseLateSteps();

        expect(lastChaseTo('rohit@meridian.test'))->toContain("is still open, on Rakesh Menon's behalf while you are covering for them and is due by");
        expect(collect(chasesThatLeft($exit))->map(fn (string $sent) => strstr($sent, ' — ', true))->all())
            ->toEqualCanonicalizing(['rohit@meridian.test', 'priya@meridian.test']);
    });
});

it('holds a reminder to the next working day at the recipient own office, and sends the latest one only', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridianExitOf('deepak');

        // Finance is Chandni's, in Pune. Deepak's exit counts against Shimla, which works
        // on Tuesday; Pune does not.
        $this->travelTo(onMeridiansClock('2026-11-16 10:00'));
        (new CaseEngine)->decide($exit, 1, 'approved', meridianColleague('priya'), ['id_card_returned' => true]);
        OfficeHoliday::query()->create([
            'office_id' => Office::query()->where('name', 'Pune office')->value('id'),
            'date' => '2026-11-17',
            'name' => 'A Pune holiday',
        ]);
        chasesThatLeft();

        $this->travelTo(onMeridiansClock('2026-11-17 10:00'));
        chaseLateSteps();
        expect(chasesThatLeft($exit))->toBe([]);

        // Both of its stages fell due on the holiday. Wednesday morning gets one chase.
        $this->travelTo(onMeridiansClock('2026-11-18 00:05'));
        chaseLateSteps();
        expect(chasesThatLeft($exit))->toBe(['chandni@meridian.test — Second ask: Finance clearance on #'.$exit->number]);
    });
});

it('tells whoever is above a step at its deadline even on a day their own office is closed', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridianExitOf('deepak');

        // Chandni, the HR director the clearance escalates to, works in Pune, and Pune is
        // closed on the Wednesday it runs out.
        OfficeHoliday::query()->create([
            'office_id' => Office::query()->where('name', 'Pune office')->value('id'),
            'date' => '2026-11-18',
            'name' => 'A Pune holiday',
        ]);

        $this->travelTo(onMeridiansClock('2026-11-18 09:00'));
        chaseLateSteps();

        expect(chasesThatLeft($exit))->toBe(['chandni@meridian.test — Out of time — HR clearance on #'.$exit->number]);
    });
});

it('holds a reminder for somebody with no office against the calendar the case counts on', function () {
    TenantContext::run($this->meridian, function () {
        // Rohit's exit has nobody over Pune to clear it, so it falls to the stand-in — here
        // somebody with an account and no job at any office.
        $kavita = User::factory()->named('Kavita Rao')->create(['work_email' => 'kavita@meridian.test']);
        app(Settings::class)->set(AssigneeResolver::StandInSetting, (int) $kavita->getKey());

        // Opened on Thursday morning, so its three-quarter mark is Friday evening and its
        // deadline Monday morning.
        ProcessCase::query()->whereKey(meridianExitOf('rohit')->getKey())
            ->update(['opened_at' => onMeridiansClock('2026-11-12 09:00')->utc()]);

        $rohits = meridianExitOf('rohit');

        $this->travelTo(onMeridiansClock('2026-11-14 10:00'));
        chaseLateSteps();
        expect(chasesThatLeft($rohits))->toBe([]);

        $this->travelTo(onMeridiansClock('2026-11-16 08:00'));
        chaseLateSteps();
        expect(chasesThatLeft($rohits))->toBe(['kavita@meridian.test — Second ask: HR clearance on #'.$rohits->number]);
    });
});

it('sends one chase, not three, when several fell due while nothing was sending', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridianExitOf('deepak');

        $this->travelTo(onMeridiansClock('2026-11-17 23:00'));
        chaseLateSteps();
        expect(chasesThatLeft($exit))->toHaveCount(2);

        $this->travelTo(onMeridiansClock('2026-11-17 23:01'));
        chaseLateSteps();
        expect(chasesThatLeft($exit))->toBe([]);
    });
});

it('chases at the stages the step own rule names, so removing one is a change of data', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit_chased_once', 'Exit, chased once')->about('employee')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('HR clearance')
            ->heldByTheRole('hr_head')->offering('approved')->dueIn(48)->nudgingAt(0.75)->create();

        $exit->publish();
        (new CaseEngine)->open($exit, meridianColleague('anjali'), meridianColleague('chandni'));
        NotificationDelivery::query()->delete();

        $this->travelTo(onMeridiansClock('2026-11-17 09:00'));
        chaseLateSteps();
        chasesThatLeft();
        expect(NotificationDelivery::query()->whereRelation('case', 'template_id', $exit->getKey())->exists())->toBeFalse();

        $this->travelTo(onMeridiansClock('2026-11-17 21:00'));
        chaseLateSteps();
        expect(NotificationDelivery::query()->whereRelation('case', 'template_id', $exit->getKey())->count())->toBe(2);
    });
});

it('warns about a passed legal deadline on a Sunday, once, by the client own calendar', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridianExitOf('deepak');
        ProcessCase::query()->whereKey($exit->getKey())->update(['statutory_from' => '2026-11-12', 'statutory_due_at' => '2026-11-14']);

        // Half past midnight on Sunday in India is still Saturday on UTC's calendar, which
        // is the legal deadline's own day.
        $this->travelTo(onMeridiansClock('2026-11-15 00:30'));
        chaseLateSteps();
        expect(lastChaseTo('chandni@meridian.test'))->toContain('was due by 14 November 2026 and is not finished. It is waiting on HR clearance.');
        // Chandni raised it; Priya, an administrator, can change settings too.
        expect(chasesThatLeft($exit))->toEqualCanonicalizing([
            'chandni@meridian.test — Past its legal deadline — #'.$exit->number,
            'priya@meridian.test — Past its legal deadline — #'.$exit->number,
        ]);

        chaseLateSteps();
        expect(chasesThatLeft($exit))->toBe([]);
    });
});

it('never warns the person a case is about that it is past its legal deadline, even when they raised it', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridianExitOf('deepak');

        // Deepak handed in his own resignation through the product.
        ProcessCase::query()->whereKey($exit->getKey())->update([
            'initiated_by' => meridianColleague('deepak')->getKey(),
            'statutory_from' => '2026-11-12',
            'statutory_due_at' => '2026-11-14',
        ]);

        chaseLateSteps();

        expect(chasesThatLeft($exit))->toEqualCanonicalizing([
            'chandni@meridian.test — Past its legal deadline — #'.$exit->number,
            'priya@meridian.test — Past its legal deadline — #'.$exit->number,
        ]);
    });
});

it('counts a step deadline on the client own calendar', function () {
    TenantContext::run($this->meridian, function () {
        // One in the morning on Saturday in India, which is still Friday evening on UTC's
        // calendar. Shimla does not work Saturdays, so the clock starts on Monday.
        ProcessCase::query()->whereKey(meridianExitOf('deepak')->getKey())
            ->update(['opened_at' => onMeridiansClock('2026-11-14 01:00')->utc()]);

        $clearance = (new AvailableSteps)->for(meridianExitOf('deepak'))->sole();

        expect($clearance->dueAt->equalTo(onMeridiansClock('2026-11-18 00:00')))->toBeTrue();
    });
});

it('chases by hand from the case, at once on a Sunday, once a day, and names who pressed it', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = meridianColleague('anjali');
        $request = ProcessCase::query()->whereRelation('template', 'key', 'hiring_request')
            ->where('initiated_by', $anjali->getKey())->orderBy('id')->firstOrFail();

        // Sunday, when nothing that waits for a working day would go.
        $this->travelTo(onMeridiansClock('2026-11-22 11:00'));

        Livewire::actingAs($anjali)->test(ViewCase::class, ['record' => $request->getKey()])
            ->callAction('chase', ['sequence' => 2])
            ->assertHasNoActionErrors()
            ->assertNotified('Chased Priya Nair')
            ->assertSee('Anjali Rao pressed the chase button');

        // Rakesh is away and Priya is covering his hiring approvals, so she is the one asked.
        expect(chasesThatLeft())->toBe(['priya@meridian.test — Anjali Rao is waiting on Line-of-business approval — #'.$request->number]);

        Livewire::actingAs($anjali)->test(ViewCase::class, ['record' => $request->getKey()])
            ->callAction('chase', ['sequence' => 2])
            ->assertNotified('Nobody was chased');
        expect(chasesThatLeft())->toBe([]);

        $this->travelTo(onMeridiansClock('2026-11-23 00:01'));

        Livewire::actingAs($anjali)->test(ViewCase::class, ['record' => $request->getKey()])
            ->callAction('chase', ['sequence' => 2])
            ->assertNotified('Chased Priya Nair');

        expect(NotificationDelivery::query()->where('template_key', TemplateCatalogue::StepNudgeHolder)->pluck('triggered_by')->all())
            ->toBe([$anjali->getKey(), $anjali->getKey()]);
    });
});

it('says so when a chase pressed by hand did not go out, and lets it be pressed again', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = meridianColleague('anjali');
        $request = ProcessCase::query()->whereRelation('template', 'key', 'hiring_request')
            ->where('initiated_by', $anjali->getKey())->orderBy('id')->firstOrFail();

        $this->travelTo(onMeridiansClock('2026-11-22 11:00'));

        $mailer = Mail::getFacadeRoot();
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Connection could not be established.'));

        Livewire::actingAs($anjali)->test(ViewCase::class, ['record' => $request->getKey()])
            ->callAction('chase', ['sequence' => 2])
            ->assertNotified('Nobody was chased');

        Mail::swap($mailer);

        Livewire::actingAs($anjali)->test(ViewCase::class, ['record' => $request->getKey()])
            ->callAction('chase', ['sequence' => 2])
            ->assertNotified('Chased Priya Nair');

        expect(NotificationDelivery::query()->where('template_key', TemplateCatalogue::StepNudgeHolder)->orderBy('id')->pluck('status')->all())
            ->toBe([NotificationDelivery::Failed, NotificationDelivery::Sent]);
    });
});

it('offers no chase on a step only a link can answer', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(meridianColleague('chandni'))->test(ViewCase::class, ['record' => meridianExitOf('rakesh')->getKey()])
            ->assertActionHidden('chase');
    });
});

it('runs one pass at a time, and a pass that died part-way stops chasing for ten minutes, not a day', function () {
    $pass = collect(app(Schedule::class)->events())->sole();

    expect($pass->withoutOverlapping)->toBeTrue()
        ->and($pass->expiresAt)->toBe(10);
});
