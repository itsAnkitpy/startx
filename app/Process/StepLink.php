<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\ProcessCase;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The link that lets somebody with no account answer one step, once.
 *
 * A step marked `external` has no resolved set at all — that is the point of it rather
 * than a gap in it. Permission to act is this link and nothing else, checked on the
 * server every time it is opened and again when an answer is submitted, so an employee
 * who obtains the link is refused in exactly the same words as a stranger.
 *
 * **The link is capped by time and by how many times it is opened, and it can always be
 * asked for again.** DocuSign expires an unauthenticated recipient's link after five
 * clicks or forty-eight hours, and the complaint in their own community is never the cap
 * — it is having no way to get another one. A cap with no way back is a support ticket
 * dressed as a security control, so the person holding a dead link is offered a fresh one
 * to **the address already on the record** and never to an address they type.
 *
 * **The token is opaque and checked against the database rather than signed.** Laravel's
 * signed URLs carry an expiry inside the address and verify without storing anything,
 * which is genuinely less code — but they cannot count opens, cannot be withdrawn, and
 * cannot be re-issued, and all three are required here. Keeping the expiry in the row
 * beside the count means one answer to "does this still work" instead of two that can
 * disagree. What is stored is a hash of the token, so the database never holds anything
 * that would open a step if it leaked.
 *
 * **The row it lives on is the step's own attempt row.** A link is not a separate thing
 * to be reconciled with the step later: issuing one is the moment somebody first touches
 * the step, which is exactly when `case_steps` grows a row, and the same one-live-attempt
 * rule that stops two colleagues claiming a shared step stops a step having two live
 * links. Re-issuing marks the old row replaced and puts the new one behind it, which is
 * the send-back's order and the only one that rule allows — so every link ever sent stays
 * readable and nothing is overwritten.
 *
 * ponytail: the message is a plain line of text sent through Laravel's mailer. Module 06
 * owns letters, templates and the delivery log that records what was actually sent;
 * replace this with its sender when that arrives, and the record of who was asked is
 * already here in the case's own history.
 */
final class StepLink
{
    /**
     * How long a link works for, and how many times it may be opened. Both are DocuSign's
     * shipped numbers rather than invented ones. Constants rather than client settings
     * until a client asks for their own; module 12's screens are where that would live.
     */
    public const LastsHours = 48;

    public const Opens = 5;

    /** What the case's own record calls a link going out. */
    public const IssuedEvent = 'link_issued';

    private AvailableSteps $reader;

    private AssigneeResolver $assignees;

    public function __construct()
    {
        $this->reader = new AvailableSteps;
        $this->assignees = new AssigneeResolver;
    }

    /**
     * Send a fresh link for a step, and hand back the address it points at.
     *
     * Only for a step it is actually somebody's turn to answer, read through the same
     * reader every other door uses: a link for a step three groups away would sit in an
     * inbox working on nothing, and would put a row on a step nobody has reached.
     *
     * Any live link for the step is marked replaced first, so asking again never leaves
     * two working links for one answer.
     */
    public function issue(ProcessCase $case, int $sequence): string
    {
        return DB::transaction(function () use ($case, $sequence): string {
            // The case's own row for the rest of this, and its live rows read again
            // behind it — the same order every other write in the engine takes. Two
            // people pressing "send me a new link" in the same second would otherwise
            // both replace the same attempt and both insert behind it, and the database's
            // one-live-attempt rule would turn the loser into a server error on a page
            // somebody outside the company is looking at.
            ProcessCase::query()->whereKey($case->getKey())->lockForUpdate()->first();
            $case->unsetRelation('liveSteps');

            $available = $this->reader->for($case)->first(
                fn (AvailableStep $step) => $step->step->sequence === $sequence
            ) ?? throw ProcessRefused::itIsNotThatStepsTurn($sequence);

            $step = $available->step;

            if (! $this->assignees->isForSomebodyWithNoAccount($step)) {
                throw ProcessRefused::thatStepIsNotAnsweredByALink($step->name);
            }

            $person = $this->whoAnswersIt($case, $step->name);
            $token = Str::random(48);

            $available->attempt?->forceFill(['superseded_at' => now()])->save();

            CaseStep::create([
                'case_id' => $case->getKey(),
                'sequence' => $sequence,
                'external_assignee' => [
                    'name' => $person['name'],
                    'email' => $person['email'],
                    'token' => hash('sha256', $token),
                    'expires_at' => CarbonImmutable::now()->addHours(self::LastsHours)->toIso8601String(),
                    'opens' => 0,
                ],
            ]);

            $address = $this->addressFor($case, $token);

            // Named in the case's own history, because "who was asked" has to be
            // answerable a year later and the link itself is gone by then. The address is
            // written in full: this is the record a tribunal reads, and a masked address
            // there proves nothing about who was asked.
            CaseEvent::create([
                'case_id' => $case->getKey(),
                'actor_id' => null,
                'type' => self::IssuedEvent,
                'payload' => [
                    'step' => $step->name,
                    'sequence' => $sequence,
                    'sent_to' => $person['email'],
                ],
            ]);

            Mail::raw(
                $person['name'].",\n\n"
                    .$case->tenant->name.' needs you to answer one step: '.$step->name.".\n\n"
                    .$address."\n\n"
                    .'The link works for the next '.self::LastsHours.' hours and can be opened '
                    .self::Opens." times. If it stops working, it will offer you a new one.\n",
                fn ($message) => $message->to($person['email'])
                    ->subject($case->tenant->name.' — '.$step->name)
            );

            return $address;
        });
    }

    /**
     * The row a token opens, whatever state it is in.
     *
     * Replaced and used links are found too rather than filtered out, so the person
     * holding one is told to ask for another instead of being told their link never
     * existed. Scoped to the client company by the wall every query carries, so a token
     * only ever opens a step at the address it was sent for.
     */
    public function find(string $token): ?CaseStep
    {
        // A link opened on the platform's own address rather than the company's has no
        // company in scope, and the wall refuses every read outright rather than answering
        // with nothing. That is right everywhere else and would be a server error here, so
        // it is answered as a token that opens nothing — which is what it is.
        if (TenantContext::id() === null) {
            return null;
        }

        return CaseStep::query()
            ->where('external_assignee->token', hash('sha256', $token))
            ->latest('id')
            ->first();
    }

    /**
     * Whether this link still opens anything, in one place so that opening it and
     * answering through it can never disagree.
     *
     * All four failures say the same sentence. A different one for each would tell
     * somebody working through guessed addresses which guesses were nearly right, and to
     * the person holding a real link they all mean the same thing anyway.
     */
    public function refuseUnlessItStillWorks(CaseStep $link): void
    {
        $held = (array) $link->external_assignee;

        $dead = $link->superseded_at !== null
            || $link->acted_at !== null
            || ($held['used_at'] ?? null) !== null
            || (int) ($held['opens'] ?? 0) >= self::Opens
            || CarbonImmutable::parse($held['expires_at'] ?? null)->isPast();

        if ($dead) {
            throw ProcessRefused::thatLinkNoLongerOpens();
        }
    }

    /**
     * Count one opening of the link.
     *
     * Counted when the page is drawn rather than when an answer is sent, which is what a
     * click cap means everywhere it ships. It costs the recipient an open every time they
     * look at the step without answering, and that is the trade the cap is: five looks is
     * generous for one question, and the way back is a new link rather than a bigger
     * number.
     */
    public function opened(CaseStep $link): void
    {
        $held = (array) $link->external_assignee;
        $held['opens'] = (int) ($held['opens'] ?? 0) + 1;

        $link->external_assignee = $held;
        $link->save();
    }

    /**
     * Send another link for whatever step this one was for, to the address on the record.
     *
     * **The address is never taken from the request.** Somebody holding a dead link may
     * ask for a new one and that is all they may do: choosing where it goes would turn a
     * finished link into a way of having the next one delivered somewhere else.
     */
    public function issueAgain(CaseStep $link): string
    {
        // Only the link that is still the live one, which is always the link the person
        // it belongs to is holding. Every older link is somebody else's copy — forwarded,
        // sitting in an archive, or read out of a mailbox — and letting one of those ask
        // again would let whoever holds it replace the recipient's working link over and
        // over, so the person the step is actually waiting on could never answer it, and
        // every attempt would put another message in their personal inbox.
        if ($link->superseded_at !== null) {
            throw ProcessRefused::aNewerLinkHasAlreadyGoneOut();
        }

        return $this->issue($link->case, (int) $link->sequence);
    }

    /**
     * Who answers this step, and where to reach them.
     *
     * The person the case is about, at the personal address module 01 records for them —
     * the only address in the product that outlives the account. A candidate's own address
     * arriving on the case's answer form is the other half the plan names, and it waits
     * for module 04 to build forms: naming the cell it comes from before those exist would
     * mean either honouring a guess or breaking a file a client has already typed.
     *
     * @return array{name: string, email: string}
     */
    private function whoAnswersIt(ProcessCase $case, string $step): array
    {
        $person = $case->subject;
        $address = $person instanceof User ? trim((string) $person->personal_email) : '';

        if ($address === '') {
            throw ProcessRefused::thereIsNowhereToSendTheLink(
                $step,
                $person instanceof User ? $person->name : 'the person this case is about',
            );
        }

        return ['name' => $person->name, 'email' => $address];
    }

    /**
     * The client company's own address with the token on it.
     *
     * Built from the company's subdomain rather than from `route()`, because the link is
     * usually made where there is no request to read a host from — a scheduled pass, a
     * command, a seeder — and `route()` would answer with the platform's own domain, which
     * has no client company in scope and would refuse the token.
     */
    private function addressFor(ProcessCase $case, string $token): string
    {
        $scheme = str_starts_with((string) config('app.url'), 'https://') ? 'https' : 'http';

        return $scheme.'://'.$case->tenant->slug.'.'.config('tenancy.central_domain').'/step/'.$token;
    }
}
