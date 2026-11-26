<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use Illuminate\Support\Collection;

/**
 * Every message this product sends, with our own wording in it.
 *
 * A client edits these words; they cannot add a message, delete one, or change which
 * moment sends it. That is the shape both Keka and BambooHR ship — the vendor supplies a
 * catalogue and the customer layers their own wording over it — and it is what stops a
 * step being configured to send a message nobody wrote.
 *
 * **What lives here and what lives on the row, and why the line is drawn where it is.**
 * The row a client owns holds only the two things a client may change: the subject line
 * and the body. Everything else about a message — its name, who it goes to, the moment it
 * is sent, which tokens it may use, and whether it skips somebody's daily bundle — is
 * here in code, because none of it is a client's to change and a column nobody may write
 * to is a column that only ever disagrees with the code that decides the answer.
 *
 * **A message we add here later reaches every client**, which is the rule Ankit settled on
 * 6 September 2026. Adding a definition to this list and inserting its row for existing
 * clients in a migration is the whole job; the insert leaves a row a client has already
 * reworded exactly as they wrote it, because it only ever adds keys that are missing.
 *
 * @see NotificationTemplate for the row, and for what a client may change on it
 */
class TemplateCatalogue
{
    /**
     * Sent to the person the case is about. Three of them, settled with Ankit on 6 September
     * 2026: the opening, any money claimed against them, and the close. Nothing about the
     * approvals in between, because a departing employee cannot act on "Finance has your
     * file" and telling them invites an argument.
     *
     * **And a fourth since 20 November 2026, the case turned down**, with who turned it down
     * and why. Module 07 let Deepak raise his own resignation, and one turned down at his
     * manager's approval had told him it started and never that it stopped. It is the end of
     * the case rather than an approval in between, which is how Pocket HRMS and Keka treat it
     * too: Pocket HRMS emails the employee the manager's remarks, and Keka shows the reason on
     * their profile. It carries no link, because the case's page is not theirs to read.
     *
     * **And two more since module 07 step 2: their last working day moved, and their exit
     * withdrawn by somebody else.** Both change what they are owed and when, which is the
     * same reason the opening is sent, and neither is an approval in between. No link either.
     */
    public const CaseOpenedSubject = 'case_opened_subject';

    public const MoneyClaimedSubject = 'money_claimed_subject';

    /**
     * And one since module 08 step 2, settled with Ankit on 23 November 2026: a claim changed
     * or dropped when its clearance is answered again after a send-back. Without it Deepak,
     * told of 40,000, hears nothing when it goes, and reads 35,000 as a second claim.
     */
    public const MoneyClaimChangedSubject = 'money_claim_changed_subject';

    public const CaseClosedSubject = 'case_closed_subject';

    public const CaseTurnedDownSubject = 'case_turned_down_subject';

    public const LastDayMovedSubject = 'last_day_moved_subject';

    public const CaseWithdrawnSubject = 'case_withdrawn_subject';

    /**
     * The same two moments, for everybody holding an open step and whoever raised the case.
     * A clearance holder planning against a settlement date that has moved is planning
     * against the wrong day, and one still working on a withdrawn exit is wasting it.
     */
    public const LastDayMovedWorkingOnIt = 'last_day_moved_working_on_it';

    public const CaseWithdrawnWorkingOnIt = 'case_withdrawn_working_on_it';

    /** Sent to whoever raised the case, who today has to go and look for all three. */
    public const CaseMovedInitiator = 'case_moved_initiator';

    public const CaseRejectedInitiator = 'case_rejected_initiator';

    /**
     * Added 7 September 2026, because the two the plan named are both about a step being
     * answered and neither reads correctly about a case that has simply finished. Whoever
     * raised a case is deliberately not written to when it opens: they clicked the button
     * a second earlier and are looking at the screen, and the volume complaint in this
     * module's own research is precisely about mail on every state change of one request.
     */
    public const CaseClosedInitiator = 'case_closed_initiator';

    /**
     * The link somebody with no account answers a step through. Its words are ours rather
     * than the client's, so it has no row and no wording here — only the two facts a
     * screen listing what was sent needs in order to label it. The wording moves into the
     * catalogue when the wording screen can insist a client keeps the link itself in a
     * sentence they have rewritten.
     */
    public const LinkToAnswerAStep = 'link_to_answer_a_step';

    /** The link a leaver answers the documents issued on her exit through, ours for the same reason. */
    public const LinkToTheirDocuments = 'link_to_their_documents';

    /** Sent to whoever a step is waiting on, and to whoever is above them when it is late. */
    public const StepAvailableHolder = 'step_available_holder';

    public const StepReminderHolder = 'step_reminder_holder';

    public const StepNudgeHolder = 'step_nudge_holder';

    public const StepEscalationManager = 'step_escalation_manager';

    /**
     * Sent to whoever was holding a step when HR overrode the hold, added at module 07 step
     * 3. Without it the step simply leaves their queue.
     */
    public const HoldOverriddenHolder = 'hold_overridden_holder';

    /**
     * Sent to whoever issued a leaver's documents (module 09 step 4): one refused, or every
     * one answered. Not to whoever raised the exit, who can be the leaver herself.
     */
    public const DocumentRefusedIssuer = 'document_refused_issuer';

    public const DocumentsAnsweredIssuer = 'documents_answered_issuer';

    /** The two messages about the product itself failing rather than about a step. */
    public const CaseBreachWarning = 'case_breach_warning';

    public const MessageUndelivered = 'message_undelivered_initiator';

    /** The daily bundle, which is a message about several other messages. */
    public const DailyDigest = 'daily_digest';

    /**
     * The catalogue itself.
     *
     * `goes_to` and `sent_when` are plain sentences rather than anything the code reads.
     * They are the two questions somebody looking at this screen actually has — who reads
     * this, and what makes it go out — and putting them beside the wording is what makes
     * the list readable without our help.
     *
     * `bypasses_digest` marks a message that goes out the moment it is due even when the
     * person would rather have a daily summary, and the list is closed. Everything chasing
     * a deadline: a chase, a chase somebody pressed by hand, the escalation above the
     * holder, and the warning that a legal deadline has gone — a chase-up that waits until
     * six in the evening can land after the deadline it was chasing. The three messages to
     * the person a case is about, which go to a personal address because a work account
     * ends with the job. And the notice that a message did not arrive, because whoever gets
     * it has a phone call to make that afternoon. The last two settled with Ankit on
     * 17 November 2026. And a document refused through its link, and the email saying every
     * document is answered, which is the only one when the last answer is the refusal, since
     * a refused statement is money in dispute (Ankit, 26 November 2026). What is left for a summary is "your turn" and the news to whoever
     * raised a case, which is where the volume is.
     *
     * @return array<string, array{name: string, goes_to: string, sent_when: string, bypasses_digest: bool, tokens: list<string>, subject: string, body: string}>
     */
    public static function definitions(): array
    {
        return [
            self::CaseOpenedSubject => [
                'name' => 'Your case has been started',
                'goes_to' => 'The person the case is about',
                'sent_when' => 'The moment the case is opened',
                'bypasses_digest' => true,
                'tokens' => ['person.name', 'company.name', 'process.name', 'case.number'],
                'subject' => 'Your {{ process.name }} at {{ company.name }} has been started',
                'body' => <<<'TEXT'
                    Dear {{ person.name }},

                    {{ company.name }} has started your {{ process.name }}. Its reference is {{ case.number }}.

                    There is nothing you need to do unless somebody asks you for something directly. We will write to you again if any money is claimed against you, saying which department has claimed it and how much, and once more when this is finished.

                    {{ company.name }}
                    TEXT,
            ],

            self::MoneyClaimedSubject => [
                'name' => 'A claim has been raised against you',
                'goes_to' => 'The person the case is about',
                'sent_when' => 'A department claims money against them',
                'bypasses_digest' => true,
                'tokens' => [
                    'person.name', 'company.name', 'process.name', 'case.number',
                    'claim.department', 'claim.amount', 'claim.reason', 'claim.raised_on',
                ],
                'subject' => 'A claim of {{ claim.amount }} has been raised against you',
                'body' => <<<'TEXT'
                    Dear {{ person.name }},

                    {{ claim.department }} has claimed {{ claim.amount }} against you on {{ claim.raised_on }}, for this reason: {{ claim.reason }}

                    We are telling you now, while it is happening, rather than leaving you to find it on a statement later. It will appear on your final statement as a claim, with the department's name and the date beside it.

                    If you disagree with it, reply to this message or speak to {{ claim.department }}. Disagreeing with a claim does not hold up the rest of your {{ process.name }}, and it does not hold up money you are owed.

                    {{ company.name }}
                    TEXT,
            ],

            self::MoneyClaimChangedSubject => [
                'name' => 'A claim against you has changed',
                'goes_to' => 'The person the case is about',
                'sent_when' => 'A department changes or drops a claim it made against them',
                'bypasses_digest' => true,
                'tokens' => [
                    'person.name', 'company.name', 'process.name', 'case.number',
                    'claim.department', 'claim.was', 'claim.amount', 'claim.reason', 'claim.changed_on',
                ],
                'subject' => 'A claim against you has changed from {{ claim.was }} to {{ claim.amount }}',
                'body' => <<<'TEXT'
                    Dear {{ person.name }},

                    {{ claim.department }} has changed its claim against you from {{ claim.was }} to {{ claim.amount }} on {{ claim.changed_on }}. It was raised for this reason: {{ claim.reason }}

                    This replaces what we told you before about this claim. Your final statement will show the new figure.

                    If you disagree with it, reply to this message or speak to {{ claim.department }}. Disagreeing with a claim does not hold up the rest of your {{ process.name }}, and it does not hold up money you are owed.

                    {{ company.name }}
                    TEXT,
            ],

            self::CaseClosedSubject => [
                'name' => 'Your case has been completed',
                'goes_to' => 'The person the case is about',
                'sent_when' => 'The case is completed',
                'bypasses_digest' => true,
                'tokens' => ['person.name', 'company.name', 'process.name', 'case.number', 'case.closed_on'],
                'subject' => 'Your {{ process.name }} at {{ company.name }} is complete',
                'body' => <<<'TEXT'
                    Dear {{ person.name }},

                    Your {{ process.name }} was completed on {{ case.closed_on }}. Its reference is {{ case.number }}.

                    Thank you.

                    {{ company.name }}
                    TEXT,
            ],

            self::CaseTurnedDownSubject => [
                'name' => 'Your case has been turned down',
                'goes_to' => 'The person the case is about',
                'sent_when' => 'Somebody turns it down',
                'bypasses_digest' => true,
                'tokens' => ['person.name', 'company.name', 'process.name', 'case.number', 'step.answered_by', 'step.reason'],
                'subject' => 'Your {{ process.name }} at {{ company.name }} has been turned down',
                'body' => <<<'TEXT'
                    Dear {{ person.name }},

                    {{ step.answered_by }} has turned down your {{ process.name }}. Its reference is {{ case.number }}.

                    The reason given was: {{ step.reason }}

                    Nothing more will happen on it. If you have any questions, speak to {{ step.answered_by }} or to HR.

                    {{ company.name }}
                    TEXT,
            ],

            self::LastDayMovedSubject => [
                'name' => 'Your last working day has changed',
                'goes_to' => 'The person the case is about',
                'sent_when' => 'Somebody changes their last working day',
                'bypasses_digest' => true,
                'tokens' => [
                    'person.name', 'company.name', 'process.name', 'case.number',
                    'case.last_working_day_was', 'case.last_working_day', 'case.deadline', 'case.changed_by', 'case.reason',
                ],
                'subject' => 'Your last working day at {{ company.name }} is now {{ case.last_working_day }}',
                'body' => <<<'TEXT'
                    Dear {{ person.name }},

                    {{ case.changed_by }} has changed your last working day from {{ case.last_working_day_was }} to {{ case.last_working_day }}. The reference of your {{ process.name }} is {{ case.number }}.

                    The reason given was: {{ case.reason }}

                    Everything you are owed is now due by {{ case.deadline }}. If this is not what you agreed, speak to {{ case.changed_by }} or to HR.

                    {{ company.name }}
                    TEXT,
            ],

            self::CaseWithdrawnSubject => [
                'name' => 'Your case has been withdrawn',
                'goes_to' => 'The person the case is about',
                'sent_when' => 'Somebody else withdraws it',
                'bypasses_digest' => true,
                'tokens' => ['person.name', 'company.name', 'process.name', 'case.number', 'case.changed_by', 'case.reason'],
                'subject' => 'Your {{ process.name }} at {{ company.name }} has been withdrawn',
                'body' => <<<'TEXT'
                    Dear {{ person.name }},

                    {{ case.changed_by }} has withdrawn your {{ process.name }}. Its reference is {{ case.number }}.

                    The reason given was: {{ case.reason }}

                    Nothing more will happen on it. If you have any questions, speak to {{ case.changed_by }} or to HR.

                    {{ company.name }}
                    TEXT,
            ],

            self::LastDayMovedWorkingOnIt => [
                'name' => 'The last working day on an exit has changed',
                'goes_to' => 'Everybody holding an open step on it, and whoever raised it',
                'sent_when' => 'Somebody changes the last working day',
                'bypasses_digest' => false,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'case.last_working_day_was', 'case.last_working_day', 'case.deadline', 'case.changed_by', 'case.reason',
                ],
                'subject' => '{{ case.number }} — last working day is now {{ case.last_working_day }}',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ case.changed_by }} has changed the last working day on {{ case.number }}, {{ case.what_it_is_about }}, from {{ case.last_working_day_was }} to {{ case.last_working_day }}. Everything owed to them is now due by {{ case.deadline }}.

                    The reason given was: {{ case.reason }}

                    Open it here: {{ case.link }}

                    TEXT,
            ],

            self::CaseWithdrawnWorkingOnIt => [
                'name' => 'A case has been withdrawn',
                'goes_to' => 'Everybody holding an open step on it, and whoever raised it',
                'sent_when' => 'Somebody withdraws it',
                'bypasses_digest' => false,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link', 'case.changed_by', 'case.reason',
                ],
                'subject' => '{{ case.number }} has been withdrawn',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ case.changed_by }} has withdrawn {{ case.number }}, {{ case.what_it_is_about }}. Nothing on it is waiting on anybody any more.

                    The reason given was: {{ case.reason }}

                    You can read the whole story of it here: {{ case.link }}

                    TEXT,
            ],

            self::CaseMovedInitiator => [
                'name' => 'Your request has moved on',
                'goes_to' => 'Whoever raised the case',
                'sent_when' => 'Every time a step of it is answered',
                'bypasses_digest' => false,
                // A step answered is approved, put on hold or sent back, so the sentence
                // says which: "has finished" read as untrue of the other two.
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'step.name', 'step.outcome', 'step.answered_by', 'case.waiting_on',
                ],
                'subject' => '{{ case.number }} — {{ step.name }} was {{ step.outcome }}',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ step.name }} on {{ case.number }}, {{ case.what_it_is_about }}, was {{ step.outcome }} by {{ step.answered_by }}.

                    It is now waiting on {{ case.waiting_on }}.

                    You can read the whole story of it here: {{ case.link }}

                    TEXT,
            ],

            self::CaseRejectedInitiator => [
                'name' => 'Your request has been turned down',
                'goes_to' => 'Whoever raised the case',
                'sent_when' => 'Somebody turns it down',
                'bypasses_digest' => false,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'step.name', 'step.answered_by', 'step.reason',
                ],
                'subject' => '{{ case.number }} has been turned down',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ step.answered_by }} has turned down {{ case.number }}, {{ case.what_it_is_about }}, at {{ step.name }}.

                    The reason given was: {{ step.reason }}

                    You can read the whole story of it here: {{ case.link }}

                    TEXT,
            ],

            self::CaseClosedInitiator => [
                'name' => 'A request you raised is finished',
                'goes_to' => 'Whoever raised the case',
                'sent_when' => 'The case is completed',
                'bypasses_digest' => false,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'case.closed_on',
                ],
                'subject' => '{{ case.number }} is finished',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ case.number }}, {{ case.what_it_is_about }}, was finished on {{ case.closed_on }}. Every step of it has been answered and there is nothing left waiting on anybody.

                    You raised it, so you are being told rather than left to go and look.

                    You can read the whole story of it here: {{ case.link }}

                    TEXT,
            ],

            self::StepAvailableHolder => [
                'name' => 'It is your turn',
                'goes_to' => 'Whoever the step is waiting on',
                'sent_when' => 'Everything blocking the step is finished',
                'bypasses_digest' => false,
                // `step.due_by` is the whole sentence or nothing: a hiring request is about
                // nobody, so its steps have no due date and "It is due by ." is what a bare
                // date would have left. `step.due_at` stays for anybody who wrote their own.
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'step.name', 'step.due_at', 'step.due_by', 'step.on_behalf_of',
                ],
                'subject' => '{{ step.name }} is waiting on you — {{ case.number }}',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ step.name }} on {{ case.number }}, {{ case.what_it_is_about }}, is now waiting on you{{ step.on_behalf_of }}.{{ step.due_by }}

                    Open it here: {{ case.link }}

                    TEXT,
            ],

            self::StepReminderHolder => [
                'name' => 'A reminder that this is waiting on you',
                'goes_to' => 'Whoever is holding the work today, including anybody covering',
                'sent_when' => 'Part of the way through the step\'s own time limit',
                'bypasses_digest' => true,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'step.name', 'step.due_at', 'step.on_behalf_of',
                ],
                'subject' => 'Still waiting on you — {{ step.name }}, {{ case.number }}',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ step.name }} on {{ case.number }}, {{ case.what_it_is_about }}, is still waiting on you{{ step.on_behalf_of }}. It is due by {{ step.due_at }}.

                    Open it here: {{ case.link }}

                    TEXT,
            ],

            self::StepNudgeHolder => [
                'name' => 'Somebody has asked you to look at this',
                'goes_to' => 'Whoever is holding the work today, including anybody covering',
                'sent_when' => 'A person presses the chase button on the case',
                'bypasses_digest' => true,
                // No due date, because a hiring request is about nobody and its steps have
                // none — the sentence would have read "It is due by ." on Anjali's chase.
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'step.name', 'step.on_behalf_of', 'chased_by.name',
                ],
                'subject' => '{{ chased_by.name }} is waiting on {{ step.name }} — {{ case.number }}',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ chased_by.name }} has asked you to look at {{ step.name }} on {{ case.number }}, {{ case.what_it_is_about }}{{ step.on_behalf_of }}.

                    Open it here: {{ case.link }}

                    TEXT,
            ],

            self::StepEscalationManager => [
                'name' => 'Something on your team has run out of time',
                'goes_to' => 'Whoever is above the person holding the step',
                'sent_when' => 'The step passes its own time limit',
                'bypasses_digest' => true,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'step.name', 'step.due_at', 'step.held_by',
                ],
                'subject' => 'Out of time — {{ step.name }} on {{ case.number }}',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ step.name }} on {{ case.number }}, {{ case.what_it_is_about }}, was due by {{ step.due_at }} and has not been answered. It is waiting on {{ step.held_by }}.

                    You can answer it yourself from here: {{ case.link }}

                    TEXT,
            ],

            self::HoldOverriddenHolder => [
                'name' => 'Your hold has been overridden',
                'goes_to' => 'Whoever was holding the step',
                'sent_when' => 'Somebody overrides the hold from the exit\'s page',
                'bypasses_digest' => false,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'step.name', 'step.answered_by', 'step.reason',
                ],
                'subject' => '{{ case.number }} — your hold on {{ step.name }} was overridden',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ step.answered_by }} has overridden your hold on {{ step.name }} on {{ case.number }}, {{ case.what_it_is_about }}, so it no longer stops the exit going ahead. It is recorded as overridden, not as cleared by you.

                    The reason given was: {{ step.reason }}

                    Anything you recorded on it, such as an amount to recover, stays on it.

                    You can read the whole story of it here: {{ case.link }}

                    TEXT,
            ],

            self::DocumentRefusedIssuer => [
                'name' => 'A document you issued was refused',
                'goes_to' => 'Whoever issued the document',
                'sent_when' => 'The person it was issued to refuses it through their link',
                // At once, even to somebody on a summary: a refused statement is money in
                // dispute (Ankit, 26 November 2026).
                'bypasses_digest' => true,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'document.name', 'document.answered_by', 'document.reason',
                ],
                'subject' => '{{ case.number }} — {{ document.answered_by }} refused the {{ document.name }}',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ document.answered_by }} has refused the {{ document.name }} you issued on {{ case.number }}, {{ case.what_it_is_about }}.

                    The reason given was: {{ document.reason }}

                    Nothing else has changed: the exit stays as it is, and what happens next is for you to decide.

                    You can read the whole story of it here: {{ case.link }}

                    TEXT,
            ],

            self::DocumentsAnsweredIssuer => [
                'name' => 'Every document you issued is answered',
                'goes_to' => 'Whoever issued the documents',
                'sent_when' => 'The person they were issued to has answered the last of them',
                // At once too: when the last answer is a refusal, this is the only email
                // that says so.
                'bypasses_digest' => true,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'document.answered_by', 'documents.answers',
                ],
                'subject' => '{{ case.number }} — {{ document.answered_by }} has answered every document',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ document.answered_by }} has answered every document issued on {{ case.number }}, {{ case.what_it_is_about }}:

                    {{ documents.answers }}

                    You can read the whole story of it here: {{ case.link }}

                    TEXT,
            ],

            self::CaseBreachWarning => [
                'name' => 'A case has missed its legal deadline',
                'goes_to' => 'Whoever raised the case, and everybody who can change settings',
                'sent_when' => 'The case passes the deadline the law sets',
                'bypasses_digest' => true,
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'case.deadline', 'case.waiting_on',
                ],
                'subject' => 'Past its legal deadline — {{ case.number }}',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ case.number }}, {{ case.what_it_is_about }}, was due by {{ case.deadline }} and is not finished. It is waiting on {{ case.waiting_on }}.

                    This is the deadline the law sets, not one of ours. It has been recorded against the case.

                    Open it here: {{ case.link }}

                    TEXT,
            ],

            self::MessageUndelivered => [
                'name' => 'A message did not reach somebody',
                'goes_to' => 'Whoever raised the case',
                'sent_when' => 'A message could not be sent',
                'bypasses_digest' => true,
                // Reworded 17 November 2026 to read right for all three ways it is sent: a
                // message given up on, a link with no address to go to, and a daily summary
                // that carried several of this person's cases, sent on one of them.
                'tokens' => [
                    'person.name', 'case.number', 'case.what_it_is_about', 'case.link',
                    'failed.recipient', 'failed.address', 'failed.what_it_was', 'failed.reason',
                ],
                'subject' => 'A message about {{ case.number }} did not arrive',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    We could not send {{ failed.what_it_was }} to {{ failed.recipient }} ({{ failed.address }}). The reason given was: {{ failed.reason }}

                    You raised {{ case.number }}, {{ case.what_it_is_about }}, so you are being told rather than left to find out. They cannot be reached by email and nobody is waiting on a message they never got, so it is worth contacting them another way, or correcting their address.

                    Open the case here: {{ case.link }}

                    TEXT,
            ],

            self::DailyDigest => [
                'name' => 'Your daily summary',
                'goes_to' => 'Anybody who has chosen a daily summary rather than being told at once',
                'sent_when' => 'Once a day, at the hour that person chose',
                'bypasses_digest' => false,
                'tokens' => ['person.name', 'items', 'items.more', 'queue.link'],
                'subject' => 'Your daily summary',
                'body' => <<<'TEXT'
                    {{ person.name }},

                    {{ items }}
                    {{ items.more }}

                    Everything waiting on you is here: {{ queue.link }}

                    TEXT,
            ],
        ];
    }

    /**
     * The messages this product sends whose words are not the client's to change.
     *
     * They carry no wording and no tokens, because the wording lives where the message is
     * built. What they carry is the two facts a screen listing what was sent needs in
     * order to label a row: what the message is called, and what made it go out.
     *
     * @return array<string, array{name: string, goes_to: string, sent_when: string, bypasses_digest: bool, tokens: list<string>}>
     */
    public static function ourOwnMessages(): array
    {
        return [
            self::LinkToAnswerAStep => [
                'name' => 'A link to answer one step',
                'goes_to' => 'Somebody with no account here',
                'sent_when' => 'A step is waiting on somebody who cannot sign in',
                'bypasses_digest' => true,
                'tokens' => [],
            ],

            self::LinkToTheirDocuments => [
                'name' => 'A link to the documents issued to them',
                'goes_to' => 'The person the documents were issued to, at their personal address',
                'sent_when' => 'Their documents are issued, or a fresh link is asked for',
                'bypasses_digest' => true,
                'tokens' => [],
            ],
        ];
    }

    /**
     * The words a client sends for one message, whether or not they have changed them.
     *
     * Only the client's own catalogue, deliberately: this is what decides whether a row of
     * wording may exist at all, and a message whose words are ours must never grow one.
     *
     * @return array{name: string, goes_to: string, sent_when: string, bypasses_digest: bool, tokens: list<string>, subject: string, body: string}|null
     */
    public static function definitionOf(?string $key): ?array
    {
        return $key === null ? null : (self::definitions()[$key] ?? null);
    }

    /**
     * What a message is called and what sends it, whichever of the two lists it is in.
     *
     * What a record of a message going out reads, because that record covers every message
     * this product sends and not only the ones a client may reword.
     *
     * @return array{name: string, goes_to: string, sent_when: string, bypasses_digest: bool, tokens: list<string>}|null
     */
    public static function anyMessage(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        return self::definitions()[$key] ?? self::ourOwnMessages()[$key] ?? null;
    }

    /**
     * Put the whole catalogue in front of the client company in scope, and hand back what
     * is now there.
     *
     * Runs against whichever client company is in scope, the same as the starting roles
     * beside it — every row is stamped from that rather than passed in. **It only ever
     * adds a key that is missing**, so it is also what a later migration calls to put a
     * new message in front of clients who already exist, and a client who has reworded a
     * message keeps their own words when it runs again.
     *
     * @return Collection<string, NotificationTemplate>
     */
    public static function seed(): Collection
    {
        $existing = NotificationTemplate::query()->pluck('key')->all();

        foreach (self::definitions() as $key => $definition) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            // `key` is set directly rather than filled, because it is deliberately not
            // one of the fields a form may write — a form carrying it would let somebody
            // repoint their reminder wording at the message that tells a leaver money has
            // been claimed against them. This is the only place a key is ever written.
            $template = new NotificationTemplate;
            $template->key = $key;
            $template->fill([
                'subject' => $definition['subject'],
                'body' => $definition['body'],
            ]);
            $template->save();
        }

        return NotificationTemplate::query()->get()->keyBy('key');
    }
}
