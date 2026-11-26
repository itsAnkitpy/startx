<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\DocumentLink;
use App\Models\IssuedDocument;
use App\Models\NotificationDelivery;
use App\Models\ProcessCase;
use App\Models\User;
use App\Notifications\Sender;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The link a leaver answers the documents issued on her exit through (module 09 step 4).
 *
 * It copies a step's link (P8): no account behind it, a token kept only as a hash, alive for
 * {@see StepLink}'s 48 hours or five opens, and a fresh one always to the personal address on
 * the record, never to one typed in. It lives on a row of its own, one per email sent, rather
 * than on a step, because the exit has closed by the time it goes, and rather than on each
 * document, because one email carries one link for all of them (P13).
 *
 * Its page shows every document issued on the exit. A letter is received; the statement is
 * accepted, or refused with a reason (P9). Each answer is given once and changes nothing about
 * the exit. It goes into the exit's history, and whoever issued the document is told of a
 * refusal and of the last answer.
 *
 * ponytail: the link's email is retried in the words on its row, so a retry after a fresh
 * link has gone out carries the dead one, which offers a fresh one in turn.
 */
final class DocumentLinks
{
    /** The fewest characters a refusal's reason may have, the old tool's floor (finding 7). */
    public const ReasonAtLeast = 10;

    /** The most a reason may hold, as a note on a step's link. */
    public const ReasonAtMost = 2000;

    public const SentEvent = 'documents_link_sent';

    public const AnsweredEvent = 'document_answered';

    /**
     * Send the leaver a fresh link to her documents, replacing any she holds, and hand back
     * its address.
     *
     * @param  User|null  $by  whoever pressed the button on the exit's page, where a person sent it
     */
    public function send(ProcessCase $case, ?User $by = null): string
    {
        return DB::transaction(function () use ($case, $by): string {
            // The exit's own row first, as every write on it takes, so two presses in the same
            // second cannot both leave a live link.
            ProcessCase::query()->whereKey($case->getKey())->lockForUpdate()->first();

            $person = $case->subject;
            $address = trim((string) $person?->personal_email);

            if (! $person instanceof User || $address === '') {
                throw ProcessRefused::thereIsNowhereToSendTheirDocuments($person?->name ?? 'the person this case is about');
            }

            DocumentLink::query()
                ->where('case_id', $case->getKey())
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            $token = Str::random(48);

            DocumentLink::create([
                'case_id' => $case->getKey(),
                'token' => hash('sha256', $token),
                'sent_to' => $address,
                'expires_at' => now()->addHours(StepLink::LastsHours),
            ]);

            $documents = $case->issuedDocuments()->get();

            // Where it went is in full, as for a step's link: the record a tribunal reads.
            CaseEvent::create([
                'case_id' => $case->getKey(),
                'actor_id' => $by?->getKey(),
                'type' => self::SentEvent,
                'payload' => [
                    'documents' => $documents->pluck('kind')->all(),
                    'sent_to' => $address,
                ],
            ]);

            $opensAt = $case->tenant->addressFor('/documents/'.$token);
            $company = $case->tenant->name;
            $statement = $documents->contains('kind', IssuedDocument::SettlementStatement);

            (new Sender)->sendOurOwnWords(
                $case,
                TemplateCatalogue::LinkToTheirDocuments,
                $person,
                $company.' — your documents',
                $person->name.",\n\n"
                    .$company.' has issued your '
                    .$documents->map(fn (IssuedDocument $document): string => strtolower($document->name()))->join(', ', ' and ')
                    .". Open this link to read, download and answer each one:\n\n"
                    .$opensAt."\n\n"
                    .($statement ? "You can accept the settlement statement, or refuse it and say why.\n\n" : '')
                    .'The link works for the next '.StepLink::LastsHours.' hours and can be opened '
                    .StepLink::Opens." times. If it stops working, it will offer you a new one.\n",
                null,
                $by,
            );

            return $opensAt;
        });
    }

    /**
     * Send a fresh link from the exit's page, while a document waits for an answer: for a
     * leaver whose first email never arrived, such as to an address since corrected (P14).
     *
     * Once a day for each exit, as a chase is, so the button cannot be used to fill the
     * leaver's inbox. A press whose email is still being tried counts; one given up on does
     * not.
     *
     * ponytail: two presses in the same instant both find none sent today and both send, as
     * for the chase button. The button is disabled while its request runs; take the exit's
     * lock before the check if that ever proves not enough.
     */
    public function sendByHand(ProcessCase $case, User $by): void
    {
        $about = $case->subject?->name ?? 'the person this case is about';

        if ($case->issuedDocuments()->whereNull('answered_at')->doesntExist()) {
            throw ProcessRefused::everyDocumentIsAnswered($about);
        }

        // Laravel writes a time into a query exactly as it reads, so the client's midnight
        // goes in as UTC rather than as a wall-clock time UTC would misread.
        $startOfToday = CarbonImmutable::now($case->tenant->timezone)->startOfDay()->utc();

        $sentToday = NotificationDelivery::query()
            ->where('case_id', $case->getKey())
            ->where('template_key', TemplateCatalogue::LinkToTheirDocuments)
            ->whereNotNull('triggered_by')
            ->where('status', '!=', NotificationDelivery::Undelivered)
            ->where('created_at', '>=', $startOfToday)
            ->exists();

        if ($sentToday) {
            throw ProcessRefused::aFreshLinkAlreadyWentToday($about);
        }

        $this->send($case, $by);
    }

    /**
     * Send another link for the same exit, to the address on the record, asked for by whoever
     * holds this one. Only the live link may ask, as for a step's link: an older copy asking
     * again would replace the leaver's working link as often as it liked.
     */
    public function sendAgain(DocumentLink $link): string
    {
        if ($link->superseded_at !== null) {
            throw ProcessRefused::aNewerDocumentsLinkHasGoneOut();
        }

        return $this->send($link->case);
    }

    /**
     * The row a token opens, whatever state it is in, so the person holding a dead one is
     * offered a fresh one. Inside the client company's wall, so a token opens only at the
     * address it was sent for; on the platform's own address it opens nothing.
     */
    public function find(string $token): ?DocumentLink
    {
        if (TenantContext::id() === null) {
            return null;
        }

        return DocumentLink::query()->where('token', hash('sha256', $token))->first();
    }

    /**
     * Whether this link still works, in one place for its page, its files and its answers.
     *
     * The opens count only stops the page opening again. A document opened or answered from a
     * page already open costs nothing, so the fifth open can still be answered from.
     */
    public function refuseUnlessItStillWorks(DocumentLink $link, bool $toOpenThePage = false): void
    {
        $dead = $link->superseded_at !== null
            || $link->expires_at->isPast()
            || ($toOpenThePage && $link->opens >= StepLink::Opens);

        if ($dead) {
            throw ProcessRefused::thatDocumentsLinkNoLongerOpens();
        }
    }

    /** Count one opening of the page. */
    public function opened(DocumentLink $link): void
    {
        $link->increment('opens');
    }

    /**
     * Record the leaver's answer to one document, once.
     *
     * Checked again under the exit's lock, so a fresh link sent a moment ago, or the same
     * answer pressed twice, is refused rather than written. The database refuses a second
     * answer and any answer the document's kind does not take on its own as well.
     */
    public function answer(DocumentLink $link, IssuedDocument $document, string $answer, ?string $reason): IssuedDocument
    {
        return DB::transaction(function () use ($link, $document, $answer, $reason): IssuedDocument {
            ProcessCase::query()->whereKey($link->case_id)->lockForUpdate()->first();

            $link->refresh();
            $this->refuseUnlessItStillWorks($link);

            // Single-purpose: a link answers only the documents of its own exit.
            if ((int) $document->case_id !== (int) $link->case_id) {
                throw ProcessRefused::thatDocumentsLinkNoLongerOpens();
            }

            $document->refresh();
            $case = $link->case;
            $name = strtolower($document->name());

            if ($document->answer !== null) {
                throw ProcessRefused::thatDocumentIsAlreadyAnswered($name, lcfirst((string) $document->answerReads($case->tenant->timezone)));
            }

            if (! in_array($answer, $document->answersOffered(), true)) {
                throw ProcessRefused::notAnAnswerThatDocumentTakes($name);
            }

            $reason = $answer === 'refused' ? trim((string) $reason) : null;

            if ($reason !== null && mb_strlen($reason) < self::ReasonAtLeast) {
                throw ProcessRefused::aRefusalNeedsAReason($name, self::ReasonAtLeast);
            }

            if ($reason !== null && mb_strlen($reason) > self::ReasonAtMost) {
                throw ProcessRefused::thatReasonIsTooLong(self::ReasonAtMost);
            }

            $document->forceFill(['answer' => $answer, 'reason' => $reason, 'answered_at' => now()])->save();

            $about = (string) $case->subject?->name;

            // Against the address, never a user, as an answer through a step's link is.
            CaseEvent::create([
                'case_id' => $case->getKey(),
                'actor_id' => null,
                'type' => self::AnsweredEvent,
                'payload' => array_filter([
                    'document' => $document->kind,
                    'issued_document_id' => $document->getKey(),
                    'answer' => $answer,
                    'reason' => $reason,
                    'answered_by' => ['name' => $about, 'email' => $link->sent_to],
                ], fn (mixed $value): bool => $value !== null),
            ]);

            $this->tellWhoeverIssuedIt($case, $document, $about);

            return $document;
        });
    }

    /**
     * Tell whoever issued the document of the last answer, which names every one, or else of
     * a refusal: one email, never both for one press. Not whoever raised the exit, who can be
     * the leaver herself. Sent once the answer is saved, never from inside the write.
     */
    private function tellWhoeverIssuedIt(ProcessCase $case, IssuedDocument $document, string $about): void
    {
        $issuer = $document->issuedBy;
        $every = $case->issuedDocuments()->get();
        $timezone = $issuer->timezone ?? $case->tenant->timezone;

        if ($every->every(fn (IssuedDocument $each): bool => $each->answer !== null)) {
            DB::afterCommit(fn (): mixed => (new Sender)->send($case, TemplateCatalogue::DocumentsAnsweredIssuer, $issuer, [
                'document.answered_by' => $about,
                'documents.answers' => $every
                    ->map(fn (IssuedDocument $each): string => $each->name().': '.lcfirst((string) $each->answerReads($timezone))
                        .($each->reason === null ? '' : '. The reason given was: '.$each->reason))
                    ->join("\n"),
            ]));

            return;
        }

        if ($document->answer === 'refused') {
            DB::afterCommit(fn (): mixed => (new Sender)->send($case, TemplateCatalogue::DocumentRefusedIssuer, $issuer, [
                'document.name' => strtolower($document->name()),
                'document.answered_by' => $about,
                'document.reason' => (string) $document->reason,
            ]));
        }
    }
}
