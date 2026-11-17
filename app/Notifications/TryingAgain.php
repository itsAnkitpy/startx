<?php

namespace App\Notifications;

use App\Models\NotificationDelivery;
use App\Models\ProcessCase;
use App\Models\User;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use Closure;
use Illuminate\Support\Collection;

/**
 * A message that did not arrive: tried again, given up on, and whoever raised the case told.
 *
 * **Tried again after 5 minutes, 30 minutes and 2 hours, then given up**, by the one scheduled
 * pass. Settled with Ankit on 17 November 2026 from finding 19: a mail server's days of trying
 * are worth nothing to a chase about a two-working-day step, and one quick retry loses the
 * message to a ten-minute outage. Every refusal is tried again, including one that looks
 * permanent, because our own mail server refusing a wrong password looks just as permanent.
 *
 * **Nothing is stored to know a try is due.** The row's own last change is when it was last
 * tried, and its count of tries again says which wait comes next.
 *
 * **Whoever raised the case is told once per message given up on**, because they are the one
 * who can pick up the phone and the person it was for cannot be reached. Not where the message
 * was to them, was itself one of these notices, is on a case nobody raised, or is on a case
 * raised by the person it is about.
 */
final class TryingAgain
{
    /** Minutes to wait after each failed try before the next. Past the last, it is given up. */
    public const After = [5, 30, 120];

    /** What the case shows against a message about a step somebody has since answered. */
    public const StepAlreadyAnswered = 'Stopped trying: the step was already answered';

    /**
     * The messages about one step, which stop being tried once it is answered: Sanjay must not
     * be told at two that he is late with a clearance he gave at noon. Named by message rather
     * than by having a step number, because news to whoever raised a case names the step it
     * moved on and is about the case as a whole.
     */
    private const AboutAStep = [
        TemplateCatalogue::StepAvailableHolder,
        TemplateCatalogue::StepReminderHolder,
        TemplateCatalogue::StepNudgeHolder,
        TemplateCatalogue::StepEscalationManager,
        TemplateCatalogue::LinkToAnswerAStep,
    ];

    public function __construct(private readonly Sender $sender = new Sender) {}

    /**
     * Every message in the client company in scope whose next try is due, each ready to be
     * sent on its own beside the chases and summaries of the same pass.
     *
     * A message a daily summary carried is never tried on its own: the summary is tried whole,
     * as it was, and the messages in it take its result.
     *
     * @return list<Closure(): mixed>
     */
    public function owed(): array
    {
        return NotificationDelivery::query()
            ->where('status', NotificationDelivery::Failed)
            ->whereNull('digest_batch_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (NotificationDelivery $message) => $message->updated_at
                ->addMinutes(self::After[$message->retries] ?? 0)
                ->lessThanOrEqualTo(now()))
            ->map(fn (NotificationDelivery $message) => fn () => $this->tryAgain($message))
            ->values()
            ->all();
    }

    /**
     * Write down a link that could not be sent at all, and tell whoever raised the case now.
     *
     * Not tried again: nothing changes by the next try until somebody fills the address in,
     * and once they have, the chase button on the case sends a fresh link. Written here rather
     * than by the link itself, because the link refuses inside its own write and that write is
     * undone with the refusal.
     *
     * The body is why it never went, because there are no words it went out in.
     */
    public function couldNotSendTheLink(AvailableStep $step, string $why): void
    {
        $case = $step->case;

        $message = (new NotificationDelivery)->forceFill([
            'case_id' => $case->getKey(),
            'step_sequence' => $step->step->sequence,
            'recipient_user_id' => $case->subject?->getKey(),
            'recipient_address' => '',
            'template_key' => TemplateCatalogue::LinkToAnswerAStep,
            'channel' => NotificationDelivery::Email,
            // The subject line the link would have gone out with.
            'subject' => $case->tenant->name.' — '.$step->step->name,
            'body' => $why,
            'status' => NotificationDelivery::Undelivered,
            'failure_reason' => $why,
        ]);
        $message->save();

        $this->tellWhoeverRaisedIt($message);
    }

    /**
     * Try one message again in the words on its row, and give it up after the last wait.
     */
    private function tryAgain(NotificationDelivery $message): NotificationDelivery
    {
        if ($this->itsStepIsAnswered($message)) {
            $message->status = NotificationDelivery::Undelivered;
            $message->failure_reason = self::StepAlreadyAnswered;
            $message->save();

            return $message;
        }

        // At the address the person has now, so one corrected after Priya's was refused is the
        // one tried next, and the row says where it went. Somebody with no account, or with no
        // address left to reach them at, keeps the one on the row.
        if ($message->recipient instanceof User) {
            $message->recipient_address = $this->sender->whoAndWhere($message->case, $message->recipient)['email']
                ?: $message->recipient_address;
        }

        $message->retries++;
        $this->sender->handOver($message);

        $givenUp = $message->status === NotificationDelivery::Failed && $message->retries >= count(self::After);

        if ($givenUp) {
            $message->status = NotificationDelivery::Undelivered;
            $message->save();
        }

        if ($message->template_key === TemplateCatalogue::DailyDigest) {
            NotificationDelivery::query()->where('digest_batch_id', $message->getKey())->update([
                'status' => $message->status,
                'sent_at' => $message->sent_at,
                'failure_reason' => $message->failure_reason,
            ]);
        }

        if ($givenUp) {
            $this->tellWhoeverRaisedIt($message);
        }

        return $message;
    }

    private function itsStepIsAnswered(NotificationDelivery $message): bool
    {
        if (! in_array($message->template_key, self::AboutAStep, true) || $message->step_sequence === null) {
            return false;
        }

        return (new AvailableSteps)->for($message->case)
            ->doesntContain(fn (AvailableStep $step) => (int) $step->step->sequence === (int) $message->step_sequence);
    }

    /**
     * A notice that a message did not arrive is always to whoever raised the case, so one
     * given up on tells nobody without a rule of its own.
     */
    private function tellWhoeverRaisedIt(NotificationDelivery $message): void
    {
        if ($message->template_key === TemplateCatalogue::DailyDigest) {
            $this->tellAboutASummary($message);

            return;
        }

        $raisedBy = $this->whoToTell($message->case, $message->recipient_user_id);

        if ($raisedBy instanceof User) {
            $this->sender->send($message->case, TemplateCatalogue::MessageUndelivered, $raisedBy, [
                'failed.what_it_was' => '"'.$message->name().'"',
                ...$this->whoItWasFor($message),
            ]);
        }
    }

    /**
     * A summary given up on tells each person who raised a case in it once, on one of those
     * cases, saying how many: Priya, with forty of her exits in Chandni's summary, gets one
     * email rather than forty.
     */
    private function tellAboutASummary(NotificationDelivery $summary): void
    {
        NotificationDelivery::query()
            ->where('digest_batch_id', $summary->getKey())
            ->with('case.initiatedBy')
            ->orderBy('id')
            ->get()
            ->filter(fn (NotificationDelivery $message) => $this->whoToTell($message->case, $summary->recipient_user_id) instanceof User)
            ->groupBy(fn (NotificationDelivery $message) => (int) $message->case->initiated_by)
            ->each(function (Collection $theirs) use ($summary): void {
                $case = $theirs->first()->case;
                $count = $theirs->count();

                $this->sender->send($case, TemplateCatalogue::MessageUndelivered, $case->initiatedBy, [
                    'failed.what_it_was' => 'their daily summary, which held '
                        .($count === 1 ? 'one message about a case' : $count.' messages about cases').' you raised,',
                    ...$this->whoItWasFor($summary),
                ]);
            });
    }

    /** Whoever raised the case, unless nobody did, they are who it is about, or it was to them. */
    private function whoToTell(ProcessCase $case, int|string|null $recipient): ?User
    {
        $raisedBy = $case->initiatedBy;

        if (! $raisedBy instanceof User
            || (int) $raisedBy->getKey() === (int) $case->subject_user_id
            || (int) $raisedBy->getKey() === (int) $recipient) {
            return null;
        }

        return $raisedBy;
    }

    /**
     * @return array{'failed.recipient': string, 'failed.address': string, 'failed.reason': string}
     */
    private function whoItWasFor(NotificationDelivery $message): array
    {
        return [
            'failed.recipient' => $message->recipient?->name ?? $message->recipient_address,
            'failed.address' => $message->recipient_address !== '' ? $message->recipient_address : 'no address on record',
            'failed.reason' => (string) $message->failure_reason,
        ];
    }
}
