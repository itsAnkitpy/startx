<?php

use App\Filament\Auth\EditProfile;
use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\ProcessCase;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Sender;
use App\Notifications\TemplateCatalogue;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| When each person is told, and the daily summary (module 06, step 5).
|
| Run against the demo company seeded at nine on Monday 16 November 2026, India's clock.
| Chandni raised Deepak's exit and holds its finance clearance, so Priya clearing HR at ten
| gives her two messages at once: it is her turn, and her request has moved on. HR clearance
| is Rakesh's and Priya's, three-quarters gone at nine on Tuesday evening and due at nine on
| Wednesday morning.
*/

beforeEach(function () {
    $this->travelTo(bundleOnClock('2026-11-16 09:00'));

    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    Mail::mailer()->getSymfonyTransport()->flush();
    TenantContext::run($this->meridian, fn () => NotificationDelivery::query()->delete());
});

function bundleOnClock(string $wallTime): CarbonImmutable
{
    return CarbonImmutable::parse($wallTime, 'Asia/Kolkata');
}

function bundleColleague(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

function bundleDeepaksExit(): ProcessCase
{
    return ProcessCase::query()
        ->whereRelation('subject', 'work_email', 'deepak@meridian.test')
        ->whereRelation('template', 'key', 'exit')
        ->sole();
}

/** Priya clears HR on Deepak's exit, which tells Chandni two things. */
function bundlePriyaClearsHr(): void
{
    (new CaseEngine)->decide(bundleDeepaksExit(), 1, 'approved', bundleColleague('priya'), ['id_card_returned' => true]);
}

/** The scheduled pass, run once, as the scheduler runs it. */
function bundlePass(): void
{
    Artisan::call('steps:chase');
}

/**
 * Every email that has left since this was last asked, as its address and subject line,
 * and the bodies of the daily summaries among them.
 *
 * @return array{left: list<string>, summaries: array<string, string>}
 */
function bundleMailSinceLastAsked(): array
{
    $transport = Mail::mailer()->getSymfonyTransport();

    $left = ['left' => [], 'summaries' => []];

    foreach ($transport->messages() as $sent) {
        $address = $sent->getEnvelope()->getRecipients()[0]->getAddress();
        $subject = $sent->getOriginalMessage()->getSubject();

        $left['left'][] = $address.' — '.$subject;

        if ($subject === 'Your daily summary') {
            $left['summaries'][$address] = (string) $sent->getOriginalMessage()->getTextBody();
        }
    }

    $transport->flush();

    return $left;
}

it('holds "your turn" and news of a request somebody raised for one email at their own hour', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = bundleColleague('chandni');
        $chandni->update(['daily_summary_hour' => 18]);

        $this->travelTo(bundleOnClock('2026-11-16 10:00'));
        bundlePriyaClearsHr();

        $held = NotificationDelivery::query()->where('recipient_user_id', $chandni->getKey())->orderBy('id')->get();

        expect($held->pluck('template_key')->all())->toEqualCanonicalizing([
            TemplateCatalogue::StepAvailableHolder,
            TemplateCatalogue::CaseMovedInitiator,
        ])
            ->and($held->pluck('status')->unique()->all())->toBe([NotificationDelivery::Waiting])
            ->and(collect(bundleMailSinceLastAsked()['left'])->filter(fn (string $sent) => str_starts_with($sent, 'chandni@')))->toBeEmpty();

        $this->travelTo(bundleOnClock('2026-11-16 17:59'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['summaries'])->toBe([]);

        $this->travelTo(bundleOnClock('2026-11-16 18:00'));
        bundlePass();
        $summaries = bundleMailSinceLastAsked()['summaries'];

        expect(array_keys($summaries))->toBe(['chandni@meridian.test']);
        foreach ($held as $message) {
            expect($summaries['chandni@meridian.test'])->toContain($message->subject);
        }

        expect($held->fresh()->pluck('status')->unique()->all())->toBe([NotificationDelivery::Sent]);

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => bundleDeepaksExit()->getKey()])
            ->assertSee('Sent in their daily summary');

        $this->travelTo(bundleOnClock('2026-11-16 18:01'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['summaries'])->toBe([]);
    });
});

it('leaves a message written after the hour for the next day', function () {
    TenantContext::run($this->meridian, function () {
        bundleColleague('chandni')->update(['daily_summary_hour' => 18]);

        $this->travelTo(bundleOnClock('2026-11-16 18:30'));
        bundlePriyaClearsHr();

        $this->travelTo(bundleOnClock('2026-11-16 18:31'));
        bundlePass();
        $this->travelTo(bundleOnClock('2026-11-17 17:59'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['summaries'])->toBe([]);

        $this->travelTo(bundleOnClock('2026-11-17 18:00'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['summaries'])->toHaveKey('chandni@meridian.test');
    });
});

it('lists fifty, says how many more, and counts every one of them as sent', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = bundleColleague('chandni');
        $chandni->update(['daily_summary_hour' => 18]);

        foreach (range(1, 53) as $n) {
            (new Sender)->send(bundleDeepaksExit(), TemplateCatalogue::CaseMovedInitiator, $chandni, [
                'step.name' => "Check {$n}",
                'step.outcome' => 'approved',
            ]);
        }

        $this->travelTo(bundleOnClock('2026-11-16 18:00'));
        bundlePass();

        $body = bundleMailSinceLastAsked()['summaries']['chandni@meridian.test'];
        $held = NotificationDelivery::query()->where('recipient_user_id', $chandni->getKey())
            ->where('template_key', TemplateCatalogue::CaseMovedInitiator)->orderBy('id')->get();

        expect($body)->toContain($held[49]->subject)
            ->not->toContain($held[50]->subject)
            ->toContain('And 3 more.')
            ->and($held->pluck('status')->unique()->all())->toBe([NotificationDelivery::Sent])
            ->and($held->pluck('digest_batch_id')->unique())->toHaveCount(1);
    });
});

it('chases somebody on a summary the moment the chase is due, when the deadline falls before their summary', function () {
    TenantContext::run($this->meridian, function () {
        // Ten in the morning: Wednesday's would land an hour after the nine o'clock deadline.
        foreach (['rakesh', 'priya', 'chandni'] as $first) {
            bundleColleague($first)->update(['daily_summary_hour' => 10]);
        }

        $number = '#'.bundleDeepaksExit()->number;

        $this->travelTo(bundleOnClock('2026-11-17 21:00'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['left'])->toContain(
            "rakesh@meridian.test — Second ask: HR clearance on {$number}",
            "priya@meridian.test — Second ask: HR clearance on {$number}",
        );

        $this->travelTo(bundleOnClock('2026-11-18 09:00'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['left'])->toContain("chandni@meridian.test — Out of time — HR clearance on {$number}");
    });
});

it('warns of a missed legal deadline on a Sunday to people on a summary', function () {
    TenantContext::run($this->meridian, function () {
        bundleColleague('chandni')->update(['daily_summary_hour' => 18]);
        bundleColleague('priya')->update(['daily_summary_hour' => 18]);

        $exit = bundleDeepaksExit();
        ProcessCase::query()->whereKey($exit->getKey())->update(['statutory_from' => '2026-11-12', 'statutory_due_at' => '2026-11-14']);

        $this->travelTo(bundleOnClock('2026-11-15 00:30'));
        bundlePass();

        expect(bundleMailSinceLastAsked()['left'])->toContain(
            'chandni@meridian.test — Past its legal deadline — #'.$exit->number,
            'priya@meridian.test — Past its legal deadline — #'.$exit->number,
        );
    });
});

it('sends a chase pressed by hand at once to somebody on a summary', function () {
    TenantContext::run($this->meridian, function () {
        bundleColleague('priya')->update(['daily_summary_hour' => 18]);
        $anjali = bundleColleague('anjali');
        $request = ProcessCase::query()->whereRelation('template', 'key', 'hiring_request')
            ->where('initiated_by', $anjali->getKey())->orderBy('id')->firstOrFail();

        $this->travelTo(bundleOnClock('2026-11-22 11:00'));

        Livewire::actingAs($anjali)->test(ViewCase::class, ['record' => $request->getKey()])
            ->callAction('chase', ['sequence' => 2])
            ->assertNotified('Chased Priya Nair');

        expect(bundleMailSinceLastAsked()['left'])->toBe(['priya@meridian.test — Anjali Rao is waiting on Line-of-business approval — #'.$request->number]);
    });
});

it('tells the person a case is about at once, whatever they chose', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = bundleColleague('anjali');
        $anjali->update(['daily_summary_hour' => 18]);

        $exit = ProcessTemplate::query()->where('key', 'exit')->where('status', ProcessTemplate::Published)->sole();
        $case = (new CaseEngine)->open($exit, $anjali, bundleColleague('chandni'));

        $opened = NotificationDelivery::query()->where('case_id', $case->getKey())
            ->where('template_key', TemplateCatalogue::CaseOpenedSubject)->sole();

        expect($opened->status)->toBe(NotificationDelivery::Sent);
    });
});

it('sends what was waiting in one email as soon as somebody goes back to being told at once', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = bundleColleague('chandni');
        $chandni->update(['daily_summary_hour' => 18]);

        $this->travelTo(bundleOnClock('2026-11-16 10:00'));
        bundlePriyaClearsHr();

        $this->travelTo(bundleOnClock('2026-11-16 11:00'));
        $chandni->update(['daily_summary_hour' => null]);
        bundlePass();

        expect(bundleMailSinceLastAsked()['summaries'])->toHaveKey('chandni@meridian.test')
            ->and(NotificationDelivery::query()->where('status', NotificationDelivery::Waiting)->exists())->toBeFalse();
    });
});

it('sends a summary at the hour on the person own clock, not the company', function () {
    TenantContext::run($this->meridian, function () {
        // Six in the evening in Dubai is half past seven in India.
        $priya = bundleColleague('priya');
        $priya->update(['daily_summary_hour' => 18, 'timezone' => 'Asia/Dubai']);

        (new Sender)->send(bundleDeepaksExit(), TemplateCatalogue::CaseMovedInitiator, $priya, ['step.name' => 'A check']);

        $this->travelTo(bundleOnClock('2026-11-16 19:29'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['summaries'])->toBe([]);

        $this->travelTo(bundleOnClock('2026-11-16 19:30'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['summaries'])->toHaveKey('priya@meridian.test');
    });
});

it('lets a person choose a summary at nine in the morning on their own page, and the next message waits for it', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = bundleColleague('chandni');

        Livewire::actingAs($chandni)->test(EditProfile::class)
            ->assertOk()
            ->assertFormSet(['told' => 'at_once'])
            ->assertFormFieldHidden('daily_summary_hour')
            ->fillForm(['told' => 'daily', 'daily_summary_hour' => 9])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($chandni->fresh()->daily_summary_hour)->toBe(9);

        $this->travelTo(bundleOnClock('2026-11-16 10:00'));
        bundlePriyaClearsHr();

        $this->travelTo(bundleOnClock('2026-11-17 08:59'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['summaries'])->toBe([]);

        $this->travelTo(bundleOnClock('2026-11-17 09:00'));
        bundlePass();
        expect(bundleMailSinceLastAsked()['summaries'])->toHaveKey('chandni@meridian.test');
    });
});

it('opens for somebody who holds no role, and saves going back to at once and to the company clock', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = bundleColleague('anjali');
        $anjali->update(['daily_summary_hour' => 7, 'timezone' => 'Asia/Dubai']);

        Livewire::actingAs($anjali)->test(EditProfile::class)
            ->assertOk()
            ->assertFormSet(['told' => 'daily', 'daily_summary_hour' => 7, 'timezone' => 'Asia/Dubai'])
            ->fillForm(['told' => 'at_once', 'timezone' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($anjali->fresh())
            ->daily_summary_hour->toBeNull()
            ->timezone->toBeNull();
    });

    $anjali = TenantContext::run($this->meridian, fn () => bundleColleague('anjali'));

    $this->actingAs($anjali)
        ->get('http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/profile')
        ->assertOk()
        ->assertSee('When you are told');
});

it('sends nothing else of the page to the browser but its own three answers', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(bundleColleague('chandni'))->test(EditProfile::class)
            ->assertSet('data', fn (array $data): bool => array_keys($data) === ['told', 'daily_summary_hour', 'timezone']);
    });
});

it('refuses wording for the summary that leaves out its list, however the token is spaced', function () {
    TenantContext::run($this->meridian, function () {
        $summary = NotificationTemplate::query()->where('key', TemplateCatalogue::DailyDigest)->sole();

        Livewire::actingAs(bundleColleague('chandni'))
            ->test(EditNotificationTemplate::class, ['record' => $summary->getKey()])
            ->fillForm(['subject' => 'Your day at Meridian', 'body' => "Dear {{ person.name }},\n\nEverything is in your queue: {{ queue.link }}"])
            ->call('save')
            ->assertHasFormErrors(['body' => 'The daily summary has to keep {{ items }}. That is where the list of what is waiting goes.']);

        expect($summary->fresh()->reworded)->toBeFalse();

        Livewire::actingAs(bundleColleague('chandni'))
            ->test(EditNotificationTemplate::class, ['record' => $summary->getKey()])
            ->fillForm(['subject' => 'Your day at Meridian', 'body' => "Dear {{ person.name }},\n\n{{items}}"])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($summary->fresh()->body)->toBe("Dear {{ person.name }},\n\n{{items}}");
    });
});
