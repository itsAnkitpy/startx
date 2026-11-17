<?php

use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Models\NotificationDelivery;
use App\Models\ProcessCase;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Sender;
use App\Notifications\TemplateCatalogue;
use App\Notifications\TryingAgain;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\RawMessage;

/*
| When a message does not arrive (module 06, step 6).
|
| Run against the demo company seeded at nine on Monday 16 November 2026, India's clock.
| Chandni raised Deepak's exit. Its HR clearance is Rakesh's and Priya's, and both are
| reminded at nine on Tuesday morning, half way through its two working days.
|
| A refusal is the mail server saying no as the message is handed over, which is the half of
| "did not arrive" this step catches (finding 21). Mailpit refusing an address does the same
| thing on Ankit's machine.
*/

beforeEach(function () {
    $this->travelTo(retryOnClock('2026-11-16 09:00'));

    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    Mail::mailer()->getSymfonyTransport()->flush();
    TenantContext::run($this->meridian, fn () => NotificationDelivery::query()->delete());
});

function retryOnClock(string $wallTime): CarbonImmutable
{
    return CarbonImmutable::parse($wallTime, 'Asia/Kolkata');
}

function retryColleague(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

function retryExitOf(string $first): ProcessCase
{
    return ProcessCase::query()
        ->whereRelation('subject', 'work_email', $first.'@meridian.test')
        ->whereRelation('template', 'key', 'exit')
        ->orderBy('id')
        ->firstOrFail();
}

/** The scheduled pass, run once at a time on Meridian's clock, as the scheduler runs it. */
function retryPassAt(string $wallTime): void
{
    test()->travelTo(retryOnClock($wallTime));

    Artisan::call('steps:chase');
}

/**
 * Have the mail server refuse these addresses from now on, the way a real one refuses a
 * mailbox it does not have, and take everything else as before.
 */
function retryRefusing(string ...$addresses): void
{
    Mail::mailer()->setSymfonyTransport(new class($addresses) extends ArrayTransport
    {
        /** @var list<string> every address refused, once per try */
        public array $refused = [];

        /** @param  list<string>  $addresses */
        public function __construct(private readonly array $addresses)
        {
            parent::__construct();
        }

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $to = ($envelope ?? Envelope::create($message))->getRecipients()[0]->getAddress();

            if (in_array($to, $this->addresses, true)) {
                $this->refused[] = $to;

                throw new TransportException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 mailbox unavailable".');
            }

            return parent::send($message, $envelope);
        }
    });
}

/** How many times each address has been refused since the last call to refuse. */
function retryRefusedCount(string $address): int
{
    return collect(Mail::mailer()->getSymfonyTransport()->refused)->filter(fn (string $to) => $to === $address)->count();
}

/** The bodies of every email that left for one address. @return list<string> */
function retryMailTo(string $address): array
{
    return Mail::mailer()->getSymfonyTransport()->messages()
        ->filter(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress() === $address)
        ->map(fn ($sent) => (string) $sent->getOriginalMessage()->getTextBody())
        ->values()->all();
}

/** Every notice that a message did not arrive, about one case. */
function retryNoticesAbout(ProcessCase $case): Collection
{
    return NotificationDelivery::query()
        ->where('case_id', $case->getKey())
        ->where('template_key', TemplateCatalogue::MessageUndelivered)
        ->get();
}

function retryRemindersTo(User $person, ProcessCase $case): NotificationDelivery
{
    return NotificationDelivery::query()
        ->where('case_id', $case->getKey())
        ->where('recipient_user_id', $person->getKey())
        ->where('template_key', TemplateCatalogue::StepReminderHolder)
        ->sole();
}

it('tries a refused message again after five minutes, thirty minutes and two hours, then tells whoever raised the case, once', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = retryColleague('chandni');
        $deepaks = retryExitOf('deepak');

        retryRefusing('priya@meridian.test');
        retryPassAt('2026-11-17 09:00');

        $reminder = retryRemindersTo(retryColleague('priya'), $deepaks);

        expect($reminder->status)->toBe(NotificationDelivery::Failed)
            ->and($reminder->howItWent())->toBe('Not sent yet — trying again');

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->assertSee('Not sent yet — trying again')
            ->assertSee('550 5.1.1 mailbox unavailable');

        // Each time, a minute early and then on the minute.
        $triesAgain = ['09:04' => 0, '09:05' => 1, '09:34' => 1, '09:35' => 2, '11:34' => 2, '11:35' => 3];

        foreach ($triesAgain as $time => $count) {
            retryPassAt('2026-11-17 '.$time);
            expect($reminder->fresh()->retries)->toBe($count, "by {$time}");
        }

        expect(retryRefusedCount('priya@meridian.test'))->toBe(4)
            ->and($reminder->fresh()->status)->toBe(NotificationDelivery::Undelivered)
            ->and(retryRemindersTo(retryColleague('rakesh'), $deepaks)->status)->toBe(NotificationDelivery::Sent);

        // Chandni raised the exit, so she is the one told, once, and nothing is tried after.
        retryPassAt('2026-11-17 11:36');
        retryPassAt('2026-11-17 14:00');

        $notices = retryNoticesAbout($deepaks);

        expect($notices)->toHaveCount(1)
            ->and((int) $notices->sole()->recipient_user_id)->toBe((int) $chandni->getKey())
            ->and($notices->sole()->status)->toBe(NotificationDelivery::Sent)
            ->and(retryRefusedCount('priya@meridian.test'))->toBe(4)
            ->and(retryMailTo('chandni@meridian.test'))->toContain($notices->sole()->body)
            ->and($notices->sole()->body)->toContain('We could not send "A reminder that this is waiting on you" to Priya Nair (priya@meridian.test). The reason given was: Expected response code')
            ->and($notices->sole()->body)->toContain('You raised #'.$deepaks->number);

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->assertSee('Could not be sent')
            ->assertDontSee('Not sent yet — trying again')
            ->assertSee('A message did not reach somebody');
    });
});

it('sends a refused message on a later try and bothers nobody when the outage is short', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = retryExitOf('deepak');

        retryRefusing('priya@meridian.test');
        retryPassAt('2026-11-17 09:00');

        retryRefusing();
        retryPassAt('2026-11-17 09:05');

        $reminder = retryRemindersTo(retryColleague('priya'), $deepaks);

        expect($reminder->status)->toBe(NotificationDelivery::Sent)
            ->and($reminder->retries)->toBe(1)
            ->and($reminder->failure_reason)->toBeNull()
            ->and($reminder->sent_at->equalTo(retryOnClock('2026-11-17 09:05')))->toBeTrue()
            ->and(retryMailTo('priya@meridian.test'))->toBe([$reminder->body])
            ->and(retryNoticesAbout($deepaks))->toBeEmpty();
    });
});

it('tries a refused message again at the address the person has now, once somebody corrects it', function () {
    TenantContext::run($this->meridian, function () {
        $priya = retryColleague('priya');
        $deepaks = retryExitOf('deepak');

        retryRefusing('priya@meridian.test');
        retryPassAt('2026-11-17 09:00');

        test()->travelTo(retryOnClock('2026-11-17 09:02'));
        $priya->update(['work_email' => 'priya.nair@meridian.test']);

        retryPassAt('2026-11-17 09:05');

        $reminder = retryRemindersTo($priya, $deepaks);

        expect($reminder->status)->toBe(NotificationDelivery::Sent)
            ->and($reminder->recipient_address)->toBe('priya.nair@meridian.test')
            ->and(retryMailTo('priya.nair@meridian.test'))->toBe([$reminder->body])
            ->and(retryRefusedCount('priya@meridian.test'))->toBe(1)
            ->and(retryNoticesAbout($deepaks))->toBeEmpty();

        Livewire::actingAs(retryColleague('chandni'))->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->assertSee('Priya Nair · priya.nair@meridian.test');
    });
});

it('stops trying a message about a step once the step is answered, and keeps trying news about the case', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = retryExitOf('deepak');

        retryRefusing('rakesh@meridian.test', 'chandni@meridian.test');
        retryPassAt('2026-11-17 09:00');

        // Priya clears HR, which tells Chandni her request moved on. That news names the step
        // just answered, and is about the case as a whole.
        test()->travelTo(retryOnClock('2026-11-17 09:02'));
        (new CaseEngine)->decide($deepaks, 1, 'approved', retryColleague('priya'), ['id_card_returned' => true]);

        $movedOn = NotificationDelivery::query()
            ->where('case_id', $deepaks->getKey())
            ->where('template_key', TemplateCatalogue::CaseMovedInitiator)
            ->sole();

        retryPassAt('2026-11-17 09:05');
        retryPassAt('2026-11-17 09:07');

        $reminder = retryRemindersTo(retryColleague('rakesh'), $deepaks);

        expect($reminder->status)->toBe(NotificationDelivery::Undelivered)
            ->and($reminder->failure_reason)->toBe(TryingAgain::StepAlreadyAnswered)
            ->and($reminder->retries)->toBe(0)
            ->and(retryRefusedCount('rakesh@meridian.test'))->toBe(1)
            ->and($movedOn->fresh()->status)->toBe(NotificationDelivery::Failed)
            ->and($movedOn->fresh()->retries)->toBe(1);

        // Given up in the end, and Chandni is not told about a message to herself.
        retryPassAt('2026-11-17 09:37');
        retryPassAt('2026-11-17 11:37');

        expect($movedOn->fresh()->status)->toBe(NotificationDelivery::Undelivered)
            ->and(retryNoticesAbout($deepaks))->toBeEmpty();
    });
});

it('never tells anybody that a notice of a message not arriving did not arrive either', function () {
    TenantContext::run($this->meridian, function () {
        $deepaks = retryExitOf('deepak');

        retryRefusing('priya@meridian.test', 'chandni@meridian.test');

        foreach (['09:00', '09:05', '09:35', '11:35', '11:40', '12:10', '14:10', '15:00'] as $time) {
            retryPassAt('2026-11-17 '.$time);
        }

        $notices = retryNoticesAbout($deepaks);

        expect($notices)->toHaveCount(1)
            ->and($notices->sole()->status)->toBe(NotificationDelivery::Undelivered)
            ->and(retryRefusedCount('chandni@meridian.test'))->toBeGreaterThanOrEqual(4);
    });
});

it('tells nobody when the person a case is about raised it themselves', function () {
    TenantContext::run($this->meridian, function () {
        // Everybody at work, so whoever HR clearance is waiting on is refused "your turn".
        retryRefusing(...array_map(fn (string $first) => $first.'@meridian.test', ['chandni', 'rakesh', 'priya', 'deepak', 'anjali']));

        $rohits = (new CaseEngine)->open(
            ProcessTemplate::query()->where('key', 'exit')->where('status', ProcessTemplate::Published)->sole(),
            retryColleague('rohit'),
            retryColleague('rohit'),
        );

        foreach (['09:05', '09:35', '11:35'] as $time) {
            retryPassAt('2026-11-16 '.$time);
        }

        $givenUp = NotificationDelivery::query()
            ->where('case_id', $rohits->getKey())
            ->where('status', NotificationDelivery::Undelivered)
            ->pluck('template_key')->unique()->all();

        expect($givenUp)->toBe([TemplateCatalogue::StepAvailableHolder])
            ->and(retryNoticesAbout($rohits))->toBeEmpty();
    });
});

/** Chandni on a summary at six, with two of Anjali's hiring requests and her own exit in it. */
function retryChandnisSummary(): array
{
    $chandni = retryColleague('chandni');
    $chandni->update(['daily_summary_hour' => 18]);

    $anjalis = ProcessCase::query()->whereRelation('template', 'key', 'hiring_request')
        ->where('initiated_by', retryColleague('anjali')->getKey())->orderBy('id')->take(2)->get();

    $sender = new Sender;

    foreach ([...$anjalis, retryExitOf('deepak')] as $case) {
        $sender->send($case, TemplateCatalogue::StepAvailableHolder, $chandni->fresh(), ['step.name' => 'Director approval']);
    }

    return [$chandni, $anjalis];
}

it('tries a daily summary again whole, as it was, and marks every message in it by how that went', function () {
    TenantContext::run($this->meridian, function () {
        test()->travelTo(retryOnClock('2026-11-16 10:00'));
        [$chandni, $anjalis] = retryChandnisSummary();

        retryRefusing('chandni@meridian.test');
        retryPassAt('2026-11-16 18:00');

        $summary = NotificationDelivery::query()->where('template_key', TemplateCatalogue::DailyDigest)->sole();

        expect($summary->status)->toBe(NotificationDelivery::Failed);

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => retryExitOf('deepak')->getKey()])
            ->assertSee('Not sent yet — trying their daily summary again');

        retryRefusing();
        retryPassAt('2026-11-16 18:05');

        $carried = NotificationDelivery::query()->where('digest_batch_id', $summary->getKey())->get();

        expect($summary->fresh()->status)->toBe(NotificationDelivery::Sent)
            // Anjali's seeded exit is past its deadline, so Chandni's escalation is tried
            // again in the same minute; the summary is the one that matters here.
            ->and(collect(retryMailTo('chandni@meridian.test'))->filter(fn (string $body) => $body === $summary->body))->toHaveCount(1)
            ->and($carried)->toHaveCount(3)
            ->and($carried->pluck('status')->unique()->all())->toBe([NotificationDelivery::Sent])
            ->and($carried->pluck('failure_reason')->filter()->all())->toBe([])
            ->and(NotificationDelivery::query()->where('template_key', TemplateCatalogue::DailyDigest)->count())->toBe(1);

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => retryExitOf('deepak')->getKey()])
            ->assertSee('Sent in their daily summary');
    });
});

it('tells each person who raised a case in a daily summary given up on, once, saying how many', function () {
    TenantContext::run($this->meridian, function () {
        test()->travelTo(retryOnClock('2026-11-16 10:00'));
        [$chandni, $anjalis] = retryChandnisSummary();

        retryRefusing('chandni@meridian.test');

        foreach (['18:00', '18:05', '18:35', '20:35', '21:00'] as $time) {
            retryPassAt('2026-11-16 '.$time);
        }

        $notices = NotificationDelivery::query()->where('template_key', TemplateCatalogue::MessageUndelivered)->get();

        // Anjali raised two of the three, and hears once. Chandni raised the third, and the
        // summary was hers.
        expect($notices)->toHaveCount(1)
            ->and((int) $notices->sole()->recipient_user_id)->toBe((int) retryColleague('anjali')->getKey())
            ->and($anjalis->modelKeys())->toContain($notices->sole()->case_id)
            ->and($notices->sole()->body)->toContain('We could not send their daily summary, which held 2 messages about cases you raised, to Chandni Verma (chandni@meridian.test).');

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => retryExitOf('deepak')->getKey()])
            ->assertSee('Could not be sent in their daily summary');
    });
});

it('shows a link with nowhere to go as could not be sent, tells whoever raised the case at once, and sends a fresh one from the chase button once the address is in', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = retryColleague('chandni');
        $deepak = retryColleague('deepak');
        $deepaks = retryExitOf('deepak');
        $engine = new CaseEngine;

        $deepak->update(['personal_email' => null]);

        $engine->decide($deepaks, 1, 'approved', retryColleague('priya'), ['id_card_returned' => true]);
        $engine->decide($deepaks, 2, 'approved', $chandni, ['imprest_card_returned' => true]);
        $engine->decide($deepaks, 3, 'approved', retryColleague('rakesh'));

        $link = NotificationDelivery::query()->where('case_id', $deepaks->getKey())
            ->where('template_key', TemplateCatalogue::LinkToAnswerAStep)->sole();

        expect($link->status)->toBe(NotificationDelivery::Undelivered)
            ->and($link->recipient_address)->toBe('')
            ->and((int) $link->recipient_user_id)->toBe((int) $deepak->getKey())
            ->and($link->failure_reason)->toContain('no personal email address recorded for Deepak Iyer')
            ->and(retryNoticesAbout($deepaks))->toHaveCount(1)
            ->and(retryMailTo('chandni@meridian.test'))->toContain(retryNoticesAbout($deepaks)->sole()->body)
            ->and(retryNoticesAbout($deepaks)->sole()->body)->toContain('We could not send "A link to answer one step" to Deepak Iyer (no address on record).');

        // Never tried again: nothing changes until somebody fills the address in.
        retryPassAt('2026-11-16 09:05');
        retryPassAt('2026-11-16 09:35');

        expect(NotificationDelivery::query()->where('template_key', TemplateCatalogue::LinkToAnswerAStep)->count())->toBe(1)
            ->and(retryNoticesAbout($deepaks))->toHaveCount(1);

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->assertSee('Deepak Iyer · no address on record')
            ->assertSee('Could not be sent')
            ->callAction('chase', ['sequence' => 4])
            ->assertNotified('Nobody was chased');

        $deepak->update(['personal_email' => 'deepak@personal.example']);

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->callAction('chase', ['sequence' => 4])
            ->assertHasNoActionErrors()
            ->assertNotified('Chased Deepak Iyer')
            ->assertSee('Chandni Verma pressed the chase button');

        expect(retryMailTo('deepak@personal.example'))->toHaveCount(1)
            ->and(retryMailTo('deepak@personal.example')[0])->toContain('/step/');

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $deepaks->getKey()])
            ->callAction('chase', ['sequence' => 4])
            ->assertNotified('Nobody was chased');

        expect(retryMailTo('deepak@personal.example'))->toHaveCount(1);
    });
});
