<?php

namespace App\Notifications;

use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\ProcessCase;
use App\Models\User;
use App\Process\CaseHistory;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one thing in this product that sends a message and writes down that it did.
 *
 * It renders the client's own wording, records the message in the words it actually went
 * out in, and hands it to the mail server. Every message about a case comes through here,
 * so "did they actually get told?" is answerable from our own database rather than from a
 * mail provider's dashboard — which is the claim this module is sold on.
 *
 * **Nothing here is queued, and that is a decision rather than an omission.** The queue
 * runs on the database and needs a worker running, so a queued send would mean nothing
 * arrives until somebody starts one. It moves onto the queue when the scheduled pass in
 * step 3 makes the volume worth it. What matters more is that the send happens *after* the
 * case's own write has committed, which is the caller's job: holding a database lock open
 * across a conversation with a mail server is the thing to avoid, not the send itself.
 *
 * @see Wording for why a client's words are substituted rather than rendered
 * @see NotificationDelivery for the record it writes
 */
final class Sender
{
    /** How much of a mail server's complaint is worth keeping. */
    private const RoomForAFailure = 500;

    /**
     * Send one of the messages a client may reword, and record it as worded.
     *
     * The words come off the client's own row rather than from the catalogue, because the
     * catalogue only holds what we shipped and this is the message that actually goes out.
     *
     * @param  User|array{name: string, email: string}  $to  somebody here, or somebody with no account
     * @param  array<string, string|int|float|null>  $values  facts this message needs beyond the case's own
     */
    public function send(
        ProcessCase $case,
        string $key,
        User|array $to,
        array $values = [],
        ?int $stepSequence = null,
    ): ?NotificationDelivery {
        $template = NotificationTemplate::query()->where('key', $key)->first();

        if (! $template instanceof NotificationTemplate) {
            // Only reachable at a client company that existed before this message did and
            // never got the migration that adds it. Said out loud rather than passed over,
            // and never thrown: a message that has no words is not a reason to fail the
            // case somebody just raised.
            Log::warning('No wording for a message this product tried to send.', [
                'message' => $key,
                'case' => $case->getKey(),
            ]);

            return null;
        }

        $person = $this->whoAndWhere($case, $to);
        $values = [...$this->factsAbout($case, $person['name'], $person['user']?->timezone), ...$values];

        return $this->deliver(
            $case,
            $key,
            $person,
            Wording::filledIn($template->subject, $values),
            Wording::filledIn($template->body, $values),
            $stepSequence,
        );
    }

    /**
     * Send a message whose words are ours rather than the client's, and record it the same
     * way.
     *
     * Today that is the link somebody with no account answers a step through: it is the
     * only message whose body has to carry a working link, and the wording screen cannot
     * yet insist a client keeps a token in a sentence they have rewritten.
     *
     * @param  User|array{name: string, email: string}  $to  somebody here, or somebody with no account
     */
    public function sendOurOwnWords(
        ProcessCase $case,
        string $key,
        User|array $to,
        string $subject,
        string $body,
        ?int $stepSequence = null,
    ): ?NotificationDelivery {
        return $this->deliver($case, $key, $this->whoAndWhere($case, $to), $subject, $body, $stepSequence);
    }

    /**
     * Write the record, hand the message over, and say on the row how that went.
     *
     * **The row is written before the send and not after.** A row saying we tried and
     * never heard back is an honest thing for a screen to show; a message that went out
     * with nothing recorded is the silent failure this whole module exists to remove.
     *
     * @param  array{name: string, email: string, user: User|null}  $person
     */
    private function deliver(
        ProcessCase $case,
        string $key,
        array $person,
        string $subject,
        string $body,
        ?int $stepSequence,
    ): ?NotificationDelivery {
        // Cut to what the record holds. A client's subject line may be the full 255 on its
        // own, and the facts put into it can only make it longer — a refused record would be
        // an error page on a clearance that has already been given.
        $subject = mb_substr(trim($subject), 0, 255);
        $body = rtrim($body);

        if ($subject === '' || trim($body) === '') {
            // A client whose subject line or body is made only of tokens, every one of
            // which came out empty. The table refuses a record with no words in it, and
            // an error page on the exit somebody just raised is a worse answer than this.
            Log::warning('A message came out with no words in it and was not sent.', [
                'message' => $key,
                'case' => $case->getKey(),
            ]);

            return null;
        }

        $delivery = NotificationDelivery::create([
            'case_id' => $case->getKey(),
            'step_sequence' => $stepSequence,
            'recipient_user_id' => $person['user']?->getKey(),
            'recipient_address' => $person['email'],
            'template_key' => $key,
            'channel' => NotificationDelivery::Email,
            'subject' => $subject,
            'body' => $body,
            'status' => NotificationDelivery::Sending,
        ]);

        try {
            Mail::raw($body, fn (Message $message) => $message
                ->to($person['email'], $person['name'])
                ->subject($subject));
        } catch (Throwable $refused) {
            // Caught rather than allowed out: a mail server that is down must not undo a
            // clearance somebody has just given. Retrying it, and telling whoever raised
            // the case that their message never arrived, is step 6.
            $delivery->status = NotificationDelivery::Failed;
            $delivery->failure_reason = Str::limit($refused->getMessage(), self::RoomForAFailure);
            $delivery->save();

            return $delivery;
        }

        $delivery->status = NotificationDelivery::Sent;
        $delivery->sent_at = now();
        $delivery->save();

        return $delivery;
    }

    /**
     * Who this message is for, and the address it reaches them at.
     *
     * **The person a case is about is written to at their personal address where the
     * record has one**, because the case is often their own exit and a work account goes
     * with the job. Everybody else is written to at work. That is the same rule the link
     * for somebody with no account already follows, and it is why module 01 records a
     * personal address at all.
     *
     * @param  User|array{name: string, email: string}  $to
     * @return array{name: string, email: string, user: User|null}
     */
    private function whoAndWhere(ProcessCase $case, User|array $to): array
    {
        if (is_array($to)) {
            return ['name' => $to['name'], 'email' => $to['email'], 'user' => null];
        }

        $personal = trim((string) $to->personal_email);

        $address = $case->subject?->is($to) && $personal !== ''
            ? $personal
            : trim((string) $to->work_email);

        return ['name' => $to->name, 'email' => $address, 'user' => $to];
    }

    /**
     * The facts every message about a case can use, whoever it is going to.
     *
     * Only the tokens this step's own two messages need. A token with no value renders as
     * nothing, so the messages that wait on later steps read no worse for the ones that
     * are not here yet — they simply have nothing to say until the step that knows the
     * answer adds it.
     *
     * @return array<string, string>
     */
    private function factsAbout(ProcessCase $case, string $recipient, ?string $recipientsTimezone): array
    {
        return [
            'person.name' => $recipient,
            'company.name' => $case->tenant->name,
            'process.name' => $case->template->name,
            'case.number' => '#'.$case->number,
            'case.what_it_is_about' => (new CaseHistory)->whatItIsAbout($case),
            'case.link' => $case->tenant->addressFor('/admin/cases/'.$case->getKey()),
            // On the recipient's own calendar, else the client's. Stored in UTC, a case
            // finished at half past midnight in India would otherwise read as finished the
            // day before.
            'case.closed_on' => $case->closed_at?->timezone($recipientsTimezone ?? $case->tenant->timezone)->format('j F Y') ?? '',
        ];
    }
}
