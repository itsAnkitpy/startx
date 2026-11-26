<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\EmployeeAsset;
use App\Models\EmploymentRecord;
use App\Models\IssuedDocument;
use App\Models\LetterSetup;
use App\Models\Office;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\SettlementLine;
use App\Models\Succession;
use App\Models\User;
use App\Notifications\Chasing;
use App\Notifications\Sender;
use App\Notifications\TemplateCatalogue;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Running a case: opening one, acting on its steps, sending it back, and ending it.
 *
 * Whose turn it is lives next door in {@see AvailableSteps} and is worked out rather
 * than written, so everything here goes through that reader before it writes anything.
 * A step nobody has touched has no row, and this is the only class that creates one.
 *
 * The one thing it never does is decide a case's state. That is read off the case's own
 * timestamps, so closing means writing a closing time and nothing else, and there is no
 * status column anywhere for the engine and the steps to disagree about.
 */
final class CaseEngine
{
    /**
     * How long a cancellation reason may be, matching the column that holds it. Refused
     * here in a sentence rather than left to the database, which answers a pasted
     * paragraph with an error page.
     */
    private const LongestReason = 255;

    /**
     * Section 17(2) of the Code on Wages, 2019: everything owed to somebody who leaves is
     * payable "within two working days" of their leaving. Working days, so it is counted
     * against the calendar of the office they worked in and not by adding forty-eight
     * hours.
     */
    private const WorkingDaysToSettle = 2;

    /** Why a case closed, where it closed because every step of it was answered. */
    private const Completed = 'completed';

    /** What an exit's history calls the leaver's sign-in going off after their last day. */
    public const SignInSwitchedOffEvent = 'sign_in_switched_off';

    /**
     * Section 56(3) of the Code on Social Security, 2020: gratuity is paid within thirty
     * days of becoming payable. The Code's section 164 repealed the Payment of Gratuity
     * Act, 1972, which is what this used to cite. Thirty ordinary days, not thirty working
     * days, so the office calendar must not touch this one.
     */
    private const CalendarDaysToPayGratuity = 30;

    /**
     * Section 53(1) of the Code: gratuity is owed on leaving after five years of
     * continuous service, and the five years are waived where employment ends "due to
     * death or disablement or expiration of fixed term employment".
     *
     * Death is not a way of leaving in this product yet, because it needs a flow that
     * writes to nobody and asks nothing of the person. Until that flow exists, the gratuity
     * deadline after a death is not tracked, and module 07 records that as a known gap.
     */
    private const YearsOfServiceForGratuity = 5;

    /**
     * A fixed-term employee leaving any way other than their contract ending is owed
     * gratuity from one year. That figure is the government's own factsheet on the Code,
     * not its text, and the difference is a question for the client's lawyer. Until then
     * the deadline is shown: one shown that was not owed costs HR a minute, and one missing
     * that was owed is a legal payment missed with no warning.
     */
    private const YearsOfServiceForGratuityOnAFixedTerm = 1;

    /**
     * The two endings section 53(1) waives the years for, in the words
     * {@see ProcessCase::WaysEmploymentEnds} stores. The waiver for a contract ending is
     * read off how the employment ended rather than off the job row's type, so a job row
     * nobody updated to "Fixed-term contract" still gets its deadline.
     */
    private const EndingsOwedGratuityWhateverTheService = ['disablement', 'fixed_term_ended'];

    /** The job row's own word for a fixed-term contract, from the people screen's list. */
    private const FixedTermEmployment = 'contract';

    private AvailableSteps $reader;

    private AssigneeResolver $assignees;

    private Sender $messages;

    public function __construct()
    {
        $this->reader = new AvailableSteps;
        $this->assignees = new AssigneeResolver;
        $this->messages = new Sender;
    }

    /**
     * Note that somebody is owed a message about this case. Somebody who is not there — a
     * case nobody raised, a case about nobody — is nobody to write to and is passed over in
     * silence.
     *
     * **Nothing is sent from inside a write.** This engine takes row locks while it writes,
     * and a send is a conversation with a mail server — holding one open across the other
     * is how a busy afternoon turns into a queue of blocked clearances. And a write undone
     * after the mail has gone would have told somebody about a case that does not exist.
     * Laravel holds the send until the outermost write has committed, which on the raise
     * screen is the one saving the request and its first answer together rather than the
     * engine's own inside it, and drops it if any of that write is undone.
     *
     * @param  array<string, string>  $values
     * @param  SettlementLine|null  $claim  the amount a claim message tells the person leaving of
     */
    private function tell(ProcessCase $case, string $key, ?User $person, array $values = [], ?int $stepSequence = null, ?SettlementLine $claim = null): void
    {
        if (! $person instanceof User) {
            return;
        }

        DB::afterCommit(fn (): mixed => $this->messages->send($case, $key, $person, $values, $stepSequence, claim: $claim));
    }

    /**
     * Write the lines a clearance owes if it has just passed (module 08), and tell the person
     * leaving of each claim among them.
     *
     * One message for each recovery, at once and never in a daily bundle, because the law
     * gives Deepak seven days to explain before a claim for damage or loss may come off his
     * wages, and this message is when those days start. A payable is not a claim and sends
     * nothing. When it went out is on its delivery record, which names the amount it told
     * of and is where the statement reads the date. A claim with no reason given reads
     * as the question it answered, "Notice period shortfall to recover", rather than as
     * nothing.
     *
     * **A clearance answered again after a send-back is told against what it claimed
     * before**, question by question: the same figure sends nothing, and a figure changed or
     * dropped sends the changed-claim message instead, so Deepak never reads 35,000 as a
     * second claim beside the 40,000, or goes on believing a 40,000 that has gone.
     */
    private function fileTheSettlement(ProcessCase $case, CaseStep $attempt, ProcessStep $step): void
    {
        $now = collect(SettlementLine::fileFor($attempt, $step))
            ->where('direction', SettlementLine::Recovery)
            ->keyBy('field_key');

        // A hold or a send-back on the step answered again is not its figure being filed,
        // and must not read as every claim dropped.
        if (! in_array($attempt->outcome, CaseStep::PassingOutcomes, true)) {
            return;
        }

        $before = SettlementLine::recoveriesBefore($attempt);
        // The same calendar and the same way of writing a date as every other message: the
        // recipient's own, else the client's.
        $today = now()->timezone($case->subject?->timezone ?? $case->tenant->timezone)->format('j F Y');

        foreach ($before->union($now)->keys() as $question) {
            $was = $before->get($question);
            $line = $now->get($question);

            if ($was?->amount_paise === $line?->amount_paise) {
                continue;
            }

            $latest = $line ?? $was;
            $claim = [
                'claim.department' => $step->name,
                'claim.amount' => $line === null ? 'nothing' : SettlementLine::inRupees($line->amount_paise),
                'claim.reason' => $latest->reason ?? $latest->label,
            ];

            if ($was === null) {
                $this->tell($case, TemplateCatalogue::MoneyClaimedSubject, $case->subject, [
                    ...$claim,
                    'claim.raised_on' => $today,
                ], $step->sequence, $latest);
            } else {
                $this->tell($case, TemplateCatalogue::MoneyClaimChangedSubject, $case->subject, [
                    ...$claim,
                    'claim.was' => SettlementLine::inRupees($was->amount_paise),
                    'claim.changed_on' => $today,
                ], $step->sequence, $latest);
            }
        }
    }

    /**
     * Tell whoever each step that has just become available is waiting on that it is their
     * turn, once everything has been saved — the moment Camunda fires its own listener.
     *
     * Nothing is written to know which steps those are. Every step waiting at once is in one
     * group, so after a step is answered anything waiting in another group has just arrived:
     * the next group, or the earlier step a send-back reopened. Two people answering the last
     * two steps of a group in the same second cannot both miss it or both send it, because
     * {@see self::holdTheCaseStill()} makes the second wait for the first and see its work.
     *
     * Not the person whose press made it available: they are looking at the screen.
     *
     * @param  Collection<int, AvailableStep>  $waiting  what is waiting now, after the write
     */
    private function tellWhoseTurnItIs(Collection $waiting, ?ProcessStep $answered, ?User $by): void
    {
        $arrived = $waiting
            ->reject(fn (AvailableStep $step) => $answered !== null && $step->step->group_no === $answered->group_no)
            ->map(fn (AvailableStep $step) => $step->step->sequence)
            ->values()
            ->all();

        if ($arrived === []) {
            return;
        }

        $case = $waiting->first()->case;

        DB::afterCommit(function () use ($case, $arrived, $by): void {
            // Read again, because the write around this one may have answered the step
            // already: Priya raising a request whose first step any administrator holds
            // has answered it by the time anything is sent, and Chandni must not be told
            // it is waiting on her.
            $case->unsetRelation('liveSteps');
            $chasing = new Chasing;

            $this->reader->for($case)
                ->filter(fn (AvailableStep $step) => in_array($step->step->sequence, $arrived, true))
                ->each(fn (AvailableStep $step) => $chasing->itIsYourTurn($step, $by));
        });
    }

    /**
     * Everybody a step being answered has news for: whoever is next, and whoever raised the
     * case, who would otherwise have to go and look — the old tool's first failure.
     *
     * Whoever raised it hears about every step answered, a hold and a send-back included, and
     * a turning down with its reason. Not about their own answer, not once the case has
     * finished (the closing message says that), and never when the case is about them: the
     * person a case is about hears only what {@see TemplateCatalogue::CaseOpenedSubject}
     * lists. A turning down is one of those, whoever raised the case, and so is told to
     * them here — unless the answer was their own, through their link.
     *
     * **A hold closed over somebody tells them**, with who and why (module 07 step 3).
     * Otherwise Chandni comes back from leave to find her clearance gone from her queue and
     * learns why from the history. Whoever raised the case is not told a second time when it
     * is the same person.
     *
     * @param  string  $answeredBy  the name to give, which for a link is somebody with no account
     * @param  User|null  $heldBy  whoever was holding a step that was just closed over them
     */
    private function tellWhatTheAnswerMoved(
        ProcessCase $case,
        ProcessStep $answered,
        string $outcome,
        string $answeredBy,
        ?User $by,
        ?string $reason = null,
        ?ProcessStep $sentBackTo = null,
        ?User $heldBy = null,
    ): void {
        $waiting = $this->reader->for($case);

        $this->tellWhoseTurnItIs($waiting, $answered, $by);

        $why = trim((string) $reason) !== '' ? trim((string) $reason) : 'none';

        // Nobody answers a step on their own case except through a link, so an answer with
        // somebody signed in behind it is never the leaver's own.
        if ($outcome === 'rejected' && $by !== null) {
            $this->tell($case, TemplateCatalogue::CaseTurnedDownSubject, $case->subject, [
                'step.answered_by' => $answeredBy,
                'step.reason' => $why,
            ], $answered->sequence);
        }

        $this->tell($case, TemplateCatalogue::HoldOverriddenHolder, $heldBy, [
            'step.name' => $answered->name,
            'step.answered_by' => $answeredBy,
            'step.reason' => $why,
        ], $answered->sequence);

        $raisedBy = $case->initiatedBy;

        if (! $raisedBy instanceof User || ($by !== null && $raisedBy->is($by)) || $raisedBy->is($case->subject) || $raisedBy->is($heldBy)) {
            return;
        }

        if ($outcome === 'rejected') {
            $this->tell($case, TemplateCatalogue::CaseRejectedInitiator, $raisedBy, [
                'step.name' => $answered->name,
                'step.answered_by' => $answeredBy,
                'step.reason' => $why,
            ], $answered->sequence);

            return;
        }

        if ($waiting->isEmpty()) {
            return;
        }

        $this->tell($case, TemplateCatalogue::CaseMovedInitiator, $raisedBy, [
            'step.name' => $answered->name,
            'step.outcome' => match ($outcome) {
                'held' => 'put on hold',
                'sent_back' => 'sent back to '.$sentBackTo?->name,
                'closed_disputed' => 'closed as disputed',
                'force_closed' => 'closed over the hold',
                default => $outcome,
            },
            'step.answered_by' => $answeredBy,
            'case.waiting_on' => $waiting->map(fn (AvailableStep $step) => $step->step->name)->join(', ', ' and '),
        ], $answered->sequence);
    }

    /**
     * Open a case against a live process, freezing what it will branch on.
     *
     * Two snapshots are taken here and never taken again: the client's own switches, and
     * the answers about the person the case is about. Both exist because the case's own
     * work changes them while it runs — the exit moves Rakesh's three reports onto
     * Chandni on day two, and asking afterwards whether Rakesh manages anyone deletes the
     * handover step his exit was opened with. Only the questions this process actually
     * asks are answered, so a process with no conditions freezes nothing.
     *
     * Both legal deadlines are worked out here and nowhere else. `statutory_from` is the
     * one date they count from — the leaver's last working day for an exit — and the only
     * thing allowed to move them afterwards is a recorded amendment to that date. Neither
     * is ever recomputed because a calendar changed underneath a running case. How the
     * employment ended is stored beside that date, because the gratuity rule reads both.
     *
     * A person has one open exit at a time — one open case carrying a legal clock — and the
     * database is what refuses the second: two HR heads raising the same resignation in the
     * same second are both past any check written here before either has saved.
     *
     * @param  string|null  $employmentEndedBy  one of {@see ProcessCase::WaysEmploymentEnds}
     */
    public function open(
        ProcessTemplate $template,
        ?User $subject = null,
        ?User $by = null,
        ?string $statutoryFrom = null,
        ?string $employmentEndedBy = null,
    ): ProcessCase {
        if ($template->status !== ProcessTemplate::Published) {
            throw ProcessRefused::onlyALiveProcessRuns($template->name, $template->version, $template->status);
        }

        $record = null;

        if ($template->subject_kind === 'employee') {
            if ($subject === null) {
                throw ProcessRefused::thisProcessNeedsAPerson($template->name);
            }

            $record = $this->liveJobRowOf($subject)
                ?? throw ProcessRefused::thePersonHasNoCurrentJobRow($subject->name, $template->name);
        } elseif ($subject !== null) {
            throw ProcessRefused::thisProcessIsNotAboutAPerson($template->name, $template->subject_kind);
        }

        $conditions = $this->conditionsIn($template);

        $office = null;
        $statutoryDueAt = null;
        $gratuityDueAt = null;

        if ($statutoryFrom !== null) {
            $office = $this->calendarTheClocksCountAgainst($record, $template);

            [$statutoryDueAt, $gratuityDueAt] = $this->deadlinesCountedFrom(
                CarbonImmutable::parse($statutoryFrom)->startOfDay(),
                $office,
                $record,
                $employmentEndedBy,
            );
        }

        return DB::transaction(function () use ($template, $subject, $record, $by, $statutoryFrom, $employmentEndedBy, $conditions, $office, $statutoryDueAt, $gratuityDueAt): ProcessCase {
            try {
                $case = ProcessCase::create([
                    'template_id' => $template->getKey(),
                    'subject_user_id' => $subject?->getKey(),
                    'subject_employment_record_id' => $record?->getKey(),
                    'initiated_by' => $by?->getKey(),
                    'opened_at' => now(),
                    'statutory_from' => $statutoryFrom,
                    'employment_ended_by' => $employmentEndedBy,
                    'statutory_due_at' => $statutoryDueAt,
                    'gratuity_due_at' => $gratuityDueAt,
                    'settings_snapshot' => $this->settingsAsked($conditions),
                    'subject_facts_snapshot' => $this->subjectFactsAsked($conditions, $subject, $record),
                ]);
            } catch (UniqueConstraintViolationException $duplicate) {
                if (! str_contains($duplicate->getMessage(), 'cases_one_open_exit_per_person')) {
                    throw $duplicate;
                }

                throw ProcessRefused::theyAlreadyHaveAnOpenCase((string) $subject?->name);
            }

            $opened = [
                'process' => $template->name,
                'version' => $template->version,
            ];

            // Which calendar a legal date was counted against, and whether that calendar
            // had any holidays in it at all. The dates themselves are on the case; these
            // two answer *how* they were arrived at, which is what somebody reading the
            // case a year later — or a screen warning that nobody filled the holiday list
            // in — has to be able to see. Holidays added tomorrow do not change either.
            if ($office !== null) {
                $opened['deadlines_counted_against'] = $office->name;
                $opened['counted_from_an_empty_calendar'] = $office->hasNoHolidaysRecorded();
            }

            $this->record($case, 'case_opened', $by, $opened);

            // Rakesh is told his exit has started, so he knows the clock on his money has
            // begun. Whoever raised it is deliberately not written to here: Chandni pressed
            // the button a second ago and is looking at the screen, and this module's own
            // research is a complaint about exactly that mail. Deepak raising his own does
            // get it a second after pressing, on purpose: it goes to the personal address he
            // has just confirmed, and arriving is the first proof that address works.
            $this->tell($case, TemplateCatalogue::CaseOpenedSubject, $case->subject);

            // A process whose every step this case skips has nothing for anybody to do,
            // and left open it would sit in the queue for ever, breach its statutory
            // deadline and have no step to chase. Publishing already refuses a process
            // with no steps for that exact reason; this is the same case arriving through
            // the conditions instead. An exit for somebody who manages nobody and holds
            // no equipment is a real one.
            //
            // Not through {@see self::closeIfNothingIsOutstanding()}, because the handover is
            // not asked here: refusing to open an exit would leave no exit page to hand the
            // work over from.
            if ($this->reader->for($case)->isEmpty()) {
                $this->close($case, $by, self::Completed);
            }

            $this->tellWhoseTurnItIs($this->reader->for($case), null, $by);

            return $case;
        });
    }

    /**
     * Pick a step up without acting on it yet, which is what claiming from a shared queue
     * means. The row that appears is what stops a second person claiming the same step:
     * the database allows one live attempt per step and the loser is refused.
     */
    public function claim(ProcessCase $case, int $sequence, User $by): CaseStep
    {
        return DB::transaction(function () use ($case, $sequence, $by): CaseStep {
            $available = $this->availableStepOrRefuse($case, $sequence);

            $candidates = $this->refuseUnlessTheStepIsTheirs($available, $by);

            $attempt = $this->attemptToWriteOn($available, $by, $candidates);

            // Picking up a step that is already yours changes nothing, so it says nothing.
            // A second "picked up" line against one pick-up is noise in the one record
            // this product asks a tribunal to read.
            if ($attempt->wasRecentlyCreated) {
                $this->record($available->case, 'step_claimed', $by, [
                    'step' => $available->step->name,
                    'sequence' => $available->step->sequence,
                ]);
            }

            return $attempt;
        });
    }

    /**
     * Record what somebody chose at a step.
     *
     * Only the four a step can offer are accepted here, and only those its own
     * `allowed_outcomes` names. A clearance step that offers approve and hold refuses a
     * rejection server-side rather than by leaving the button off a screen — a button
     * that should not exist gets pressed eventually, and an exit frozen on a stray
     * rejection is a support call and a statutory breach at the same time.
     *
     * @param  array<string, mixed>  $payload  what was typed on the step's form
     * @param  int|null  $sendBackTo  the sequence of the earlier step to reopen
     */
    public function decide(
        ProcessCase $case,
        int $sequence,
        string $outcome,
        User $by,
        array $payload = [],
        ?string $reason = null,
        ?int $sendBackTo = null,
    ): CaseStep {
        if (in_array($outcome, CaseStep::HoldResolutions, true)) {
            throw ProcessRefused::notSomethingAnyoneChooses($outcome);
        }

        return DB::transaction(function () use ($case, $sequence, $outcome, $by, $payload, $reason, $sendBackTo): CaseStep {
            $available = $this->availableStepOrRefuse($case, $sequence);
            $step = $available->step;

            // Before anything about the step itself is checked, because a person with no
            // business at this step has no business being told what it offers either.
            $candidates = $this->refuseUnlessTheStepIsTheirs($available, $by);

            if (! in_array($outcome, (array) $step->allowed_outcomes, true)) {
                throw ProcessRefused::outcomeNotOffered($step->name, $outcome, (array) $step->allowed_outcomes);
            }

            // An exit's letters (module 09 step 3), refused before anything is written on
            // what is known without drawing a page.
            $issuing = $outcome === 'approved' ? $step->issues() : [];

            if ($issuing !== []) {
                $this->refuseUnlessTheLettersMayGo($case, $step);
            }

            // Both outcomes that hand the work back to somebody else have to say why. A
            // hold is an argument another department has to answer and a send-back is a
            // correction somebody has to make, and neither is any use as a bare word.
            // Everything looked at that has a send-back at all demands the comment, and
            // ServiceNow makes its own hold reason compulsory out of the box.
            if (in_array($outcome, ['held', 'sent_back'], true) && trim((string) $reason) === '') {
                throw ProcessRefused::needsAReason($outcome === 'held'
                    ? "Holding [{$step->name}]"
                    : "Sending [{$step->name}] back");
            }

            $target = $outcome === 'sent_back'
                ? $this->sendBackTargetOrRefuse($available, $sendBackTo)
                : null;

            $attempt = $this->attemptToWriteOn($available, $by, $candidates);

            $attempt->outcome = $outcome;
            $attempt->acted_at = now();

            // Old answers and new, through what the step is still asking: see
            // {@see StepForm::answeredAgain()}. The form's own rules are applied here and
            // not only on a screen. Whether a required question is demanded turns on the
            // outcome: approving is the step's answers being filed, while a rejection, a
            // hold and a send-back are each a reason it is not finished. Demanding a full
            // form on those three would make each unreachable in the one case it exists
            // for — Chandni rejects Rakesh's clearance *because* the figure is wrong, and
            // she sends it back to have it corrected.
            $attempt->payload = (new StepForm)->answeredAgain($step, (array) $attempt->payload, $payload, $outcome === 'approved');
            $attempt->save();

            $this->fileTheSettlement($case, $attempt, $step);

            $this->record($case, 'step_acted', $by, array_filter([
                'step' => $step->name,
                'sequence' => $step->sequence,
                'outcome' => $outcome,
                'reason' => $reason,
                'sent_back_to' => $target?->sequence,
                'covering_for' => $this->whoTheyAreCoveringFor($candidates, $by),
            ], fn (mixed $value) => $value !== null));

            if ($target !== null) {
                $this->reopen($case, $target, $by);
            }

            if ($outcome === 'rejected') {
                // Terminates the case. Returning it to whoever opened it instead is a
                // per-step choice the plan names and no step can express yet, so it
                // arrives with the field that carries it rather than as a flag nothing
                // sets.
                $this->close($case, $by, 'rejected', $step->name);
            } else {
                $this->closeIfNothingIsOutstanding($case, $by);
            }

            // Once the exit may finish, so a handover still owed is refused before a page is
            // drawn or a file written. In the same write as the answer, so a document that
            // cannot be drawn or stored undoes it and the step stays open, saying why.
            if ($issuing !== []) {
                $this->issueTheDocuments($case, $issuing, $by);

                // Once they are saved, the leaver's link to answer them (step 4), to the
                // personal address checked above.
                DB::afterCommit(fn (): string => (new DocumentLinks)->send($case));
            }

            $this->tellWhatTheAnswerMoved($case, $step, $outcome, $by->name, $by, $reason, $target);

            return $attempt;
        });
    }

    /**
     * Whether an exit's letters may go now: from the last working day on the company's own
     * calendar (Ankit, 26 November 2026), and only to a leaver with a personal address, the
     * one their link goes to (step 4).
     */
    private function refuseUnlessTheLettersMayGo(ProcessCase $case, ProcessStep $step): void
    {
        $about = $case->subject?->name ?? 'the person this case is about';
        $ahead = $case->lastWorkingDayStillAhead();

        if ($ahead !== null) {
            throw ProcessRefused::notBeforeTheLastWorkingDay($about, $ahead->format('j F Y'));
        }

        if (trim((string) $case->subject?->personal_email) === '') {
            throw ProcessRefused::thereIsNowhereToSendTheLetters($step->name, $about);
        }
    }

    /**
     * Draw, store and record each document an exit's letters step issues: each letter from
     * the company's wording, the statement from our own page, all with the company's
     * letterhead, footer and signature, read once so a picture gone missing refuses the lot.
     *
     * ponytail: only the queue's approval issues. A letters step offers nothing else, so
     * neither way out of a hold and no link can reach one; give them this call if one ever can.
     *
     * @param  list<string>  $kinds
     */
    private function issueTheDocuments(ProcessCase $case, array $kinds, User $by): void
    {
        $setup = LetterSetup::current();
        $around = $setup->around();
        $letter = new Letter;

        // Read again inside this write, so the statement counts every line filed on it.
        $case = $case->fresh();

        foreach ($kinds as $kind) {
            $letter->issue($case, $kind, $kind === IssuedDocument::SettlementStatement
                ? $letter->statementPage($case, $around)
                : $letter->page($letter->tokensFor($case), $setup->wordingOf($kind), $around), $by);
        }
    }

    /**
     * Record what somebody with no account chose, through the link sent to them.
     *
     * **The token is the whole permission and it is checked here, on the server, on every
     * submission.** There is no resolved set behind an external step and no queue it
     * appears in, so nothing else exists to check — which is why this is a door of its own
     * rather than a flag on the ordinary one. The reverse holds just as hard: every
     * employee is refused this step by {@see self::refuseUnlessTheStepIsTheirs()}, so the
     * two doors between them let exactly one person answer, and it is the person the link
     * was sent to.
     *
     * The token is checked again inside the lock and not only before it. The row is read
     * back through the same reader that answers whose turn it is, so a second submission
     * of the same link finds a step that has already been answered and is refused with the
     * step's own words — an employee who obtains the link after the fact cannot replay it
     * either, for the same reason and by the same check.
     *
     * The answer is recorded against the address, never against a user. Nothing in the
     * history reads as though an employee did it, which is what `case_events.actor_id`
     * being allowed to be empty has always been for.
     *
     * @param  array<string, mixed>  $payload  what was typed on the step's form
     */
    public function decideThroughALink(string $token, string $outcome, array $payload = []): CaseStep
    {
        $links = new StepLink;

        $link = $links->find($token) ?? throw ProcessRefused::thatLinkNoLongerOpens();

        return DB::transaction(function () use ($link, $links, $outcome, $payload): CaseStep {
            $available = $this->availableStepOrRefuse($link->case, (int) $link->sequence);
            $step = $available->step;

            if (! $this->assignees->isForSomebodyWithNoAccount($step)) {
                throw ProcessRefused::thatStepIsNotAnsweredByALink($step->name);
            }

            // The live attempt read back under the case's own lock, rather than the row
            // that was found a moment ago outside it. A link replaced by a fresh one
            // between the page being drawn and the answer being sent is refused here.
            $attempt = $available->attempt;

            if ($attempt === null || (int) $attempt->getKey() !== (int) $link->getKey()) {
                throw ProcessRefused::thatLinkNoLongerOpens();
            }

            $links->refuseUnlessItStillWorks($attempt);

            // Holding a step and sending the case back are an employee's moves: one is an
            // argument between departments that HR resolves later, the other names an
            // earlier step this person has never seen.
            if (in_array($outcome, ['held', 'sent_back'], true)) {
                throw ProcessRefused::aLinkOnlyAnswers($step->name, $outcome);
            }

            if (! in_array($outcome, (array) $step->allowed_outcomes, true)) {
                throw ProcessRefused::outcomeNotOffered($step->name, $outcome, (array) $step->allowed_outcomes);
            }

            $answered = (array) $attempt->external_assignee;
            $answered['used_at'] = now()->toIso8601String();

            $attempt->external_assignee = $answered;
            $attempt->outcome = $outcome;
            $attempt->acted_at = now();
            $attempt->payload = array_merge(
                (array) $attempt->payload,
                // The same rules as the queue screen applies, on the door that has no
                // screen behind it. This is the writer the check was missing from: Neha
                // Deshpande answering her own clearance through the link sent to her
                // filed it with every box empty, and the exit closed as though it had
                // been cleared.
                (new StepForm)->onlyWhatThisStepAsks($step, $payload, $outcome === 'approved'),
            );
            $attempt->save();

            $this->fileTheSettlement($link->case, $attempt, $step);

            $this->record($link->case, 'step_acted', null, [
                'step' => $step->name,
                'sequence' => $step->sequence,
                'outcome' => $outcome,
                'answered_by' => [
                    'name' => $answered['name'] ?? null,
                    'email' => $answered['email'] ?? null,
                ],
            ]);

            if ($outcome === 'rejected') {
                $this->close($link->case, null, 'rejected', $step->name);
            } else {
                $this->closeIfNothingIsOutstanding($link->case, null);
            }

            $this->tellWhatTheAnswerMoved($link->case, $step, $outcome, (string) ($answered['name'] ?? ''), null);

            return $attempt;
        });
    }

    /**
     * End a hold, by the only two routes there are.
     *
     * Neither is a button on a step's form, which is why they are here rather than in
     * `decide`. `closed_disputed` is the holding department turning the argument into a
     * disputed settlement line and closing; `force_closed` is HR overriding a hold whose
     * holder is unavailable. Both keep the original holder on the row and name whoever
     * resolved it in the history, because "HR overrode this" and "the department agreed"
     * must not read the same a year later in front of a tribunal.
     *
     * A held department that simply changes its mind does not come through here — it
     * approves, which is what releasing a hold is.
     *
     * **Overriding asks the exit permission itself** (module 07 step 3), over the department
     * the exit pinned, so no door but the exit's page reaches it and Rakesh overrides holds
     * on Shimla's exits and not Pune's.
     *
     * @param  array<string, mixed>  $payload  what the holder typed on the step's form as they
     *                                         closed it as disputed; an override answers nothing
     */
    public function resolveHold(ProcessCase $case, int $sequence, string $outcome, User $by, string $reason, array $payload = []): CaseStep
    {
        $this->refuseIfTheCaseIsAboutThem($case, $by, 'Ending a hold');

        if (! in_array($outcome, CaseStep::HoldResolutions, true)) {
            throw ProcessRefused::notAWayOutOfAHold($outcome);
        }

        // Both ways out pass the step, and they exist because a leaver's settlement cannot
        // wait. A held hiring approval closed either way would be a hire nobody approved.
        if ($case->subject_user_id === null) {
            throw ProcessRefused::aHoldOnlyEndsThisWayOnAnExit();
        }

        if ($outcome === 'force_closed' && $by->cannot('overrideHold', $case)) {
            throw ProcessRefused::thatHoldIsNotYoursToOverride();
        }

        if (trim($reason) === '') {
            throw ProcessRefused::needsAReason("Recording [{$outcome}] on a held step");
        }

        return DB::transaction(function () use ($case, $sequence, $outcome, $by, $reason, $payload): CaseStep {
            $available = $this->availableStepOrRefuse($case, $sequence);
            $step = $available->step;
            $attempt = $available->attempt;

            if ($attempt === null || $attempt->outcome !== 'held') {
                throw ProcessRefused::thatStepIsNotOnHold($step->name, $outcome);
            }

            $theirOwnHold = (int) $attempt->assignee_id === (int) $by->getKey();

            // Turning the argument into a disputed settlement line is the holding
            // department's own decision, so only the person holding it may record it, and
            // only while the step is still theirs — the same two checks an approval passes.
            // Chandni handing finance to Priya takes the card out of her queue; without the
            // first check, a request sent by hand from her browser would still close it and
            // rewrite the figure on it. An override is the opposite: somebody else
            // overruling the department, so never the holder, or "HR overrode this" would be
            // written over a department that is in fact them.
            if ($outcome === 'closed_disputed') {
                $this->refuseUnlessTheStepIsTheirs($available, $by);

                if (! $theirOwnHold) {
                    throw ProcessRefused::somebodyElseHasThatStep($step->name);
                }
            }

            if ($outcome === 'force_closed' && $theirOwnHold) {
                throw ProcessRefused::youAreHoldingItYourself($step->name);
            }

            // Closing as disputed is the holder's last word on the step, so what they typed
            // on the card is kept, through the same rules as a hold: Chandni settles the
            // recovery at 35,000 rather than 40,000 as she closes it, and the figure module
            // 08 turns into a disputed line is the one she meant. Nothing required is
            // demanded, because an unfinished clearance is exactly what is being recorded.
            if ($outcome === 'closed_disputed') {
                $attempt->payload = (new StepForm)->answeredAgain($step, (array) $attempt->payload, $payload, false);
            }

            // Read now, for the message below: whoever held it is still on the row.
            $heldBy = $attempt->assignee;

            // The reason and whoever gave it go to the history and nowhere else. A step's
            // payload is what its form collected, and a case reads its own answers back
            // out of it to decide which steps to open — so putting our own bookkeeping in
            // there makes it answerable by a condition.
            $attempt->outcome = $outcome;
            $attempt->acted_at = now();
            $attempt->save();

            $this->fileTheSettlement($case, $attempt, $step);

            $this->record($case, 'step_acted', $by, [
                'step' => $step->name,
                'sequence' => $step->sequence,
                'outcome' => $outcome,
                'reason' => $reason,
                'held_by' => $attempt->assignee_id,
            ]);

            $this->closeIfNothingIsOutstanding($case, $by);

            $this->tellWhatTheAnswerMoved($case, $step, $outcome, $by->name, $by, $reason, heldBy: $outcome === 'force_closed' ? $heldBy : null);

            return $attempt;
        });
    }

    /**
     * Move the date every legal clock on this case counts from, and move both deadlines
     * with it.
     *
     * **This is the only route by which a case's legal deadline ever changes.** Rakesh's
     * notice is extended by a week, so his last working day moves from Friday 14 August
     * to Friday 21 August, and everything the statute counts from that date moves behind
     * it: the two working days to settle what he is owed, and the thirty to pay his
     * gratuity. A client adding a festival holiday to the Shimla calendar moves neither,
     * which is the whole reason both were worked out once and frozen — a legal date that
     * shifts under a running case is worse than one that is stale and visible.
     *
     * **The job row the case is pinned to is not touched, and that is the point.** The
     * case reads Rakesh's department, designation and manager through that row, and
     * module 01 records a job change by writing a new row rather than editing the old
     * one — so amending the date that way would silently re-point the case and change
     * what its trail says he was. The date lives on the case for exactly this reason.
     *
     * Whether gratuity is owed at all is asked again, because it is a question about the
     * same date: an exit brought forward past somebody's fifth anniversary takes the
     * gratuity deadline away, and one pushed past it gives them one.
     *
     * The calendar is read as it stands now rather than as it stood at open. Amending is
     * a deliberate act with a reason on it, so it is also the one moment a genuinely
     * wrong holiday list gets to be right.
     *
     * **How the employment is ending is corrected here too**, because the gratuity deadline
     * is the same sum: Rohit's fixed-term contract recorded as a resignation is fixed by HR
     * changing it in the same dialog (module 07, grill 3). Both old and new answers go into
     * the record.
     *
     * A moved date tells everybody holding an open step, the leaver and whoever raised it,
     * with the new deadline — see {@see self::tellEverybodyOnIt()}. A corrected ending alone
     * tells nobody: nobody's work is due on a different day.
     *
     * @param  string|null  $employmentEndedBy  one of {@see ProcessCase::WaysEmploymentEnds}, or null to keep it
     * @return bool whether anything changed
     */
    public function amendTheDateTheClocksCountFrom(
        ProcessCase $case,
        string $statutoryFrom,
        User $by,
        string $reason,
        ?string $employmentEndedBy = null,
    ): bool {
        $this->refuseIfTheCaseIsAboutThem($case, $by, 'Moving the date a case counts its deadlines from');

        if ($by->cannot('changeExit', $case)) {
            throw ProcessRefused::thatExitIsNotYoursToChange();
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ProcessRefused::needsAReason('Moving the date a case counts its deadlines from');
        }

        return DB::transaction(function () use ($case, $statutoryFrom, $by, $reason, $employmentEndedBy): bool {
            $this->holdTheCaseStill($case);

            if ($case->state !== ProcessCase::Open) {
                throw ProcessRefused::thisCaseHasAlreadyEnded($case->state);
            }

            $from = CarbonImmutable::parse($statutoryFrom)->startOfDay();
            $endedBy = $employmentEndedBy ?? $case->employment_ended_by;
            $dateMoves = ! $case->statutory_from?->isSameDay($from);

            // The same answers again move nothing, so they say nothing — the same choice
            // picking up a step that is already yours makes. A line in the one record
            // this product asks a tribunal to read should mean something happened.
            if (! $dateMoves && $endedBy === $case->employment_ended_by) {
                return false;
            }

            $record = $case->subjectEmploymentRecord;
            $office = $this->calendarTheClocksCountAgainst($record, $case->template);

            $before = [
                'counted_from' => $case->statutory_from?->toDateString(),
                'statutory_due_at' => $case->statutory_due_at?->toDateString(),
                'gratuity_due_at' => $case->gratuity_due_at?->toDateString(),
                'employment_ended_by' => $case->employment_ended_by,
            ];
            $wasFrom = $case->statutory_from?->format('j F Y') ?? 'no date';

            // Read now: once the database refuses the save below, nothing more can be asked
            // of it inside this write, the person's name included.
            $leaver = $case->subject;

            [$statutoryDueAt, $gratuityDueAt] = $this->deadlinesCountedFrom($from, $office, $record, $endedBy);

            $case->statutory_from = $from;
            $case->employment_ended_by = $endedBy;
            $case->statutory_due_at = $statutoryDueAt;
            $case->gratuity_due_at = $gratuityDueAt;

            // An exit opened with no date never counted towards the one-open-exit rule, so
            // giving it one can collide with the person's real exit. Said in the sentence
            // opening a second one gets, rather than as a database error.
            try {
                $case->save();
            } catch (UniqueConstraintViolationException $duplicate) {
                if (! str_contains($duplicate->getMessage(), 'cases_one_open_exit_per_person')) {
                    throw $duplicate;
                }

                throw ProcessRefused::theyAlreadyHaveAnOpenCase((string) $leaver?->name);
            }

            $this->record($case, 'case_amended', $by, [
                'counted_from' => ['was' => $before['counted_from'], 'now' => $from->toDateString()],
                'statutory_due_at' => ['was' => $before['statutory_due_at'], 'now' => $statutoryDueAt->toDateString()],
                'gratuity_due_at' => ['was' => $before['gratuity_due_at'], 'now' => $gratuityDueAt?->toDateString()],
                'employment_ended_by' => ['was' => $before['employment_ended_by'], 'now' => $endedBy],
                'reason' => $reason,
                'counted_against' => $office->name,
                'open_steps' => $this->stepsWhoseDeadlineJustMoved($case),
            ]);

            if ($dateMoves) {
                $this->tellEverybodyOnIt($case, $this->reader->for($case), $by, TemplateCatalogue::LastDayMovedSubject, TemplateCatalogue::LastDayMovedWorkingOnIt, [
                    'case.last_working_day_was' => $wasFrom,
                    'case.last_working_day' => $from->format('j F Y'),
                    'case.deadline' => $statutoryDueAt->format('j F Y'),
                    'case.changed_by' => $by->name,
                    'case.reason' => $reason,
                ]);
            }

            return true;
        });
    }

    /**
     * Both deadlines this case would carry if its clocks counted from another date and
     * ending, worked out exactly as moving it would, so the date dialog can show them
     * before anybody saves.
     *
     * @return array{CarbonImmutable, ?CarbonImmutable} the settlement deadline, and the
     *                                                  gratuity one where it is owed
     */
    public function deadlinesIfItCountedFrom(ProcessCase $case, string $statutoryFrom, ?string $employmentEndedBy): array
    {
        $record = $case->subjectEmploymentRecord;

        return $this->deadlinesCountedFrom(
            CarbonImmutable::parse($statutoryFrom)->startOfDay(),
            $this->calendarTheClocksCountAgainst($record, $case->template),
            $record,
            $employmentEndedBy,
        );
    }

    /**
     * Stop a case that nobody approved and nobody rejected — a withdrawn resignation is
     * the one that needs it. Steps already acted on stay exactly as they are and stay
     * readable; untouched steps never had a row. Nothing is deleted, and a cancelled case
     * can never be closed.
     *
     * **Whoever looks after exits for that part of the company may withdraw one**, and the
     * person it is about may withdraw their own resignation until anybody has acted on it
     * (Ankit, 18 November 2026) — the one exception to nobody acting on a case about
     * themselves. Pocket HRMS and Keka draw the line in the same place: before the manager
     * answers.
     *
     * **Refused once the last working day has passed**, on the company's own calendar. By
     * then the person has left, and bringing them back is a rehire.
     *
     * Everybody who was holding an open step, the leaver and whoever raised it are told it
     * has stopped — see {@see self::tellEverybodyOnIt()}.
     */
    public function cancel(ProcessCase $case, User $by, string $reason): void
    {
        $theirOwn = $case->subject_user_id !== null && (int) $case->subject_user_id === (int) $by->getKey();

        if (! $theirOwn && $by->cannot('changeExit', $case)) {
            throw ProcessRefused::thatExitIsNotYoursToChange();
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ProcessRefused::needsAReason('Cancelling a case');
        }

        if (mb_strlen($reason) > self::LongestReason) {
            throw ProcessRefused::thatReasonIsTooLong(self::LongestReason);
        }

        DB::transaction(function () use ($case, $by, $reason, $theirOwn): void {
            $this->holdTheCaseStill($case);

            if ($case->state !== ProcessCase::Open) {
                throw ProcessRefused::thisCaseHasAlreadyEnded($case->state);
            }

            if ($this->theLastWorkingDayHasPassed($case)) {
                throw ProcessRefused::theLastWorkingDayHasPassed((string) $case->subject?->name, $case->statutory_from->format('j F Y'));
            }

            // Asked under the lock, so Rakesh approving Deepak's resignation in the same
            // second as Deepak withdraws it cannot leave both standing.
            if ($theirOwn && ! $this->theLeaverMayStillWithdraw($case)) {
                throw ProcessRefused::onlyHrCanWithdrawItNow();
            }

            // Read before it stops: a withdrawn case has nothing waiting on anybody.
            $case->unsetRelation('liveSteps');
            $waiting = $this->reader->for($case);

            $case->cancelled_at = now();
            $case->cancellation_reason = $reason;
            $case->cancelled_by = $by->getKey();
            $case->save();

            $this->record($case, 'case_cancelled', $by, ['reason' => $reason]);

            $this->tellEverybodyOnIt($case, $waiting, $by, TemplateCatalogue::CaseWithdrawnSubject, TemplateCatalogue::CaseWithdrawnWorkingOnIt, [
                'case.changed_by' => $by->name,
                'case.reason' => $reason,
            ]);
        });
    }

    /**
     * Whether the person an exit is about may still withdraw it themselves: it is their
     * resignation, nobody has answered a step of it, moved its date or handed their work
     * on, and their last working day has not passed. Asked by the raise screen to decide
     * between a Withdraw button and "speak to HR", and by {@see self::cancel()} under the
     * case's lock.
     *
     * A resignation rather than an exit they raised, because a resignation HR typed in from
     * Deepak's letter is still his to take back, and a dismissal is never his to withdraw.
     */
    public function theLeaverMayStillWithdraw(ProcessCase $case): bool
    {
        return $case->state === ProcessCase::Open
            && $case->employment_ended_by === 'resignation'
            && ! $this->theLastWorkingDayHasPassed($case)
            && ! CaseEvent::query()
                ->where('case_id', $case->getKey())
                ->whereIn('type', ['step_acted', 'case_amended', Succession::HandoverSettledEvent])
                ->exists();
    }

    /**
     * One job for each exit whose leaver's sign-in is owed switching off: their last working
     * day has ended on the company's clock (module 07 step 5). The every-minute pass runs
     * each in its own write, so one that fails cannot hold up the rest of the company.
     *
     * **Whether the exit has finished or not.** An exit waiting on a handover or a held
     * clearance holds the case, never the person.
     *
     * **Once for each last working day.** The line it writes in the exit's history carries
     * the day it was for, and an exit with that line for its current day is passed over. So
     * HR turning the account back on is never undone, and a day moved later switches it off
     * again once the new day ends. No column, because the history already has to say it.
     *
     * @return list<Closure(): bool>
     */
    public function signInsOwedSwitchingOff(): array
    {
        return $this->exitsThatMayOweASwitchOff()
            ->with('tenant')
            ->get()
            ->filter(fn (ProcessCase $exit): bool => $this->theLastWorkingDayHasPassed($exit))
            ->map(fn (ProcessCase $exit): Closure => fn (): bool => $this->switchOffSignIn($exit))
            ->values()
            ->all();
    }

    /**
     * Every exit whose leaver may still be owed a switch-off, whatever the clock says.
     *
     * Never a withdrawn one, nor one somebody at the company turned down: Priya turning
     * down Deepak's resignation because he is staying must not lock him out on the day he
     * once meant to leave. One turned down from the leaver's own link still counts, or
     * Rakesh refusing to confirm his own handover would keep him signed in after he has
     * gone.
     *
     * @return Builder<ProcessCase>
     */
    private function exitsThatMayOweASwitchOff(): Builder
    {
        return ProcessCase::query()
            ->whereNotNull('statutory_from')
            ->whereNotNull('subject_user_id')
            ->whereNull('cancelled_at')
            ->whereDoesntHave('events', fn (Builder $turnedDown) => $turnedDown
                ->where('type', 'case_closed')
                ->where('payload->because', 'rejected')
                ->whereNotNull('actor_id'))
            ->whereDoesntHave('events', fn (Builder $done) => $done
                ->where('type', self::SignInSwitchedOffEvent)
                ->whereRaw("(payload->>'last_working_day')::date = cases.statutory_from"));
    }

    /**
     * The leaver can no longer sign in, and whatever they picked up while covering goes back
     * to whoever they covered for — see {@see Succession::giveBackWhatTheyPickedUpCovering()}.
     * Switching off is the one column every way of signing in already asks, so somebody
     * signed in at midnight is out on their next click.
     *
     * The whole question is asked again under the case's lock, so whatever happened to the
     * exit in the moment since the pass found it wins: HR moving the day later, a colleague
     * turning it down, or HR withdrawing it a second before midnight.
     * Nobody is told. The leaver knows their last day, and HR reads it on the exit's page.
     */
    private function switchOffSignIn(ProcessCase $exit): bool
    {
        return DB::transaction(function () use ($exit): bool {
            $this->holdTheCaseStill($exit);

            if (! $this->theLastWorkingDayHasPassed($exit)
                || ! $this->exitsThatMayOweASwitchOff()->whereKey($exit->getKey())->exists()) {
                return false;
            }

            $leaver = $exit->subject;
            $handedBack = Succession::giveBackWhatTheyPickedUpCovering($leaver, $exit);

            $leaver->active = false;
            $leaver->save();

            $this->record($exit, self::SignInSwitchedOffEvent, null, [
                'last_working_day' => $exit->statutory_from->toDateString(),
                'handed_back' => $handedBack,
            ]);

            return true;
        });
    }

    /** Whether the last working day is behind us on the company's own calendar. */
    private function theLastWorkingDayHasPassed(ProcessCase $case): bool
    {
        return $case->statutory_from !== null
            && CarbonImmutable::now($case->tenant->timezone)->toDateString() > $case->statutory_from->toDateString();
    }

    /**
     * Tell everybody a withdrawal or a moved date reaches: the person the case is about, in
     * their own words at their personal address and with no link, and everybody holding an
     * open step and whoever raised the case in the other words. Not whoever pressed it, who
     * is looking at the screen.
     *
     * @param  Collection<int, AvailableStep>  $waiting  the steps open when it happened
     * @param  array<string, string>  $values
     */
    private function tellEverybodyOnIt(ProcessCase $case, Collection $waiting, User $by, string $toThem, string $toEverybodyElse, array $values): void
    {
        $subject = $case->subject;

        if (! $subject?->is($by)) {
            $this->tell($case, $toThem, $subject, $values);
        }

        $chasing = new Chasing;

        $waiting->flatMap(fn (AvailableStep $step) => $chasing->whoCanBeChasedBy($step, $by))
            ->push($case->initiatedBy)
            ->filter(fn (?User $person) => $person instanceof User && ! $person->is($by) && ! $person->is($subject))
            ->unique(fn (User $person) => (int) $person->getKey())
            ->each(fn (User $person) => $this->tell($case, $toEverybodyElse, $person, $values));
    }

    /**
     * The step at this position, if it is genuinely this step's turn.
     *
     * Everything that writes comes through here, so a step in a group that has not been
     * reached, a step already closed, and a step on a case that has ended are all one
     * refusal rather than three checks somebody could forget one of.
     */
    private function availableStepOrRefuse(ProcessCase $case, int $sequence): AvailableStep
    {
        $this->holdTheCaseStill($case);

        if ($case->state !== ProcessCase::Open) {
            throw ProcessRefused::thisCaseHasAlreadyEnded($case->state);
        }

        // Read fresh, for the reason publishing reads its steps fresh: a screen that
        // listed the case a moment ago is not evidence of whose turn it is now.
        $case->unsetRelation('liveSteps');

        return $this->reader->for($case)->first(
            fn (AvailableStep $available) => $available->step->sequence === $sequence
        ) ?? throw ProcessRefused::itIsNotThatStepsTurn($sequence);
    }

    /**
     * Take the case's own row for the rest of this action, and read the case again
     * behind it.
     *
     * IT and Finance approving the last two clearances in the same second is the failure
     * this removes. Each asks whether anything is still outstanding while the other's
     * approval is written but not yet committed, so each sees the other's clearance still
     * open, and neither closes the case. Every step is approved, nothing is anybody's
     * turn, no later action ever comes to notice, and the exit sits in the queue for ever
     * with its statutory deadline running. Queueing the two behind one row is what lets
     * the second one see the first one's work.
     *
     * It is also what makes cancelling safe. A withdrawn resignation cancelled a moment
     * ago is read as cancelled here, rather than off whatever the screen was holding when
     * the manager pressed approve.
     */
    private function holdTheCaseStill(ProcessCase $case): void
    {
        ProcessCase::query()->whereKey($case->getKey())->lockForUpdate()->first();

        $case->refresh();
    }

    /**
     * The gate: whoever is acting has to be one of the people this step belongs to.
     *
     * Worked out here rather than trusted from the request, and worked out again at the
     * moment of acting rather than when the screen was drawn. A queue page listed at nine
     * o'clock is not evidence of anything at half past — the role may have moved, the
     * person may have left, the case's own earlier steps may have changed who its later
     * ones belong to. This is the same reader {@see self::availableStepOrRefuse()} uses
     * for whose *turn* it is, for the same reason.
     *
     * Every door into a step comes through here, so a step can never be actionable
     * without being gated: picking one up and acting on one both pass this first. The one
     * deliberate exception is HR overriding a hold, which exists for the holder being
     * unavailable and is recorded as an override in both names.
     *
     * A step past its own target is asked with that fact, so whoever it escalates to may
     * act on it — and everybody who could act before still can. The set the row records is
     * the widened one, because the widened one is who was being asked at that moment.
     *
     * A step whose set is empty refuses everybody, which is the point rather than a gap:
     * an exit clearance nobody holds must not be answerable by whoever happens to be
     * logged in. It stays open, warned on the case, and waits for a person.
     *
     * It hands the set back rather than throwing it away, because the row about to be
     * written wants it: see {@see self::newAttempt()}. Resolving is a query per level, and
     * the question the row records and the question the gate asks are the same question
     * asked at the same instant — asking it twice invites two different answers.
     *
     * @return Collection<int, User>
     */
    private function refuseUnlessTheStepIsTheirs(AvailableStep $available, User $by): Collection
    {
        if ($this->assignees->isForSomebodyWithNoAccount($available->step)) {
            throw ProcessRefused::thatStepBelongsToSomebodyWithNoAccount($available->step->name);
        }

        $theirs = $this->assignees->resolve($available->case, $available->step, $available->escalationOwed);

        $isOneOfThem = $theirs->contains(
            fn (User $person) => (int) $person->getKey() === (int) $by->getKey()
        );

        if (! $isOneOfThem) {
            throw ProcessRefused::thatStepIsNotYours($available->step->name);
        }

        return $theirs;
    }

    /**
     * Whom this person is standing in for, if the only reason they are here is a cover.
     *
     * Read off the set the gate already worked out rather than asked again, which is the
     * same reason the row's record of who could have acted is written from that set: the
     * question "may Priya act" and the question "on whose behalf" have to be answered by
     * one look at the world, or the record can disagree with the permission that produced
     * it.
     *
     * Null for everybody acting in their own right, and {@see array_filter()} at both call
     * sites drops the key entirely — an approval that was nobody's cover should read as an
     * ordinary approval, not as one with an empty cover beside it.
     *
     * @param  Collection<int, User>  $candidates
     * @return array{id: int, name: string}|null
     */
    private function whoTheyAreCoveringFor(Collection $candidates, User $person): ?array
    {
        $asResolved = $candidates->first(
            fn (User $candidate) => (int) $candidate->getKey() === (int) $person->getKey()
        );

        $away = $asResolved?->relationLoaded('coveringFor') === true
            ? $asResolved->getRelation('coveringFor')
            : null;

        return $away instanceof User
            ? ['id' => (int) $away->getKey(), 'name' => $away->name]
            : null;
    }

    /**
     * The three acts on a case that sit outside any step, closed to the person the case is
     * about.
     *
     * Resolution already refuses them every clearance on their own exit, and these reach
     * the same place by a different door: cancelling the case makes it go away, moving the
     * date its clocks count from moves the settlement deadline and can hand out a gratuity
     * that was not owed, and overriding a hold overrules the colleague who raised it. All
     * three are the signature this product cannot afford to record, arriving without a
     * step to be gated on.
     *
     * Only this half. *Which* other employees may cancel a case, move that date or override
     * a hold is a permission over the department the exit pinned, asked by each of the three
     * since module 07 steps 2 and 3. Cancelling is the one exception to this rule: the leaver
     * may withdraw their own resignation until anybody has acted on it, so
     * {@see self::cancel()} does not come here. HR overriding a hold is deliberately not a
     * candidate for the step it overrides, so the step gate is the wrong question to ask there.
     */
    private function refuseIfTheCaseIsAboutThem(ProcessCase $case, User $by, string $act): void
    {
        if ($case->subject_user_id !== null && (int) $case->subject_user_id === (int) $by->getKey()) {
            throw ProcessRefused::theCaseIsAboutThem($act);
        }
    }

    /**
     * The row this action is written on.
     *
     * Three shapes. Nobody has touched the step, so a row appears now. Somebody has it
     * and has not acted, so they act on their own row and nobody else may. Or the step is
     * coming round again after the case was sent back from it — then the old attempt is
     * marked replaced first and the new row goes in behind it, which is the only order the
     * one-live-attempt rule allows. A step being held is not replaced: a hold and its
     * ending are one attempt.
     */
    /**
     * @param  Collection<int, User>  $candidates
     */
    private function attemptToWriteOn(AvailableStep $available, User $by, Collection $candidates): CaseStep
    {
        $attempt = $available->attempt;

        if ($attempt === null) {
            return $this->newAttempt($available, $by, $candidates);
        }

        // An attempt that closed without passing: the step a send-back reopened, or the
        // step the case was sent back from coming round again. The old one is marked
        // replaced first and the new row goes in behind it, which is the only order the
        // one-live-attempt rule allows.
        if ($attempt->outcome !== null && $attempt->outcome !== 'held') {
            $attempt->superseded_at = now();
            $attempt->save();

            return $this->newAttempt($available, $by, $candidates);
        }

        // Somebody has this step — picked up, or picked up and held — and it stays
        // theirs, so a colleague cannot quietly act in their name. HR overriding a hold
        // whose holder is unavailable goes through {@see self::resolveHold()} instead,
        // which keeps the holder on the row and names HR in the history.
        if ((int) $attempt->assignee_id !== (int) $by->getKey()) {
            throw ProcessRefused::somebodyElseHasThatStep($available->step->name);
        }

        return $attempt;
    }

    /**
     * Two people reading the same queue a moment apart both find no row and both insert
     * one. The database refuses the second, and it is refused here in the same words as
     * the ordinary case rather than as a failed query — the loser is somebody clicking a
     * button, and they are owed a sentence about who has the step, not an error page.
     */
    /**
     * @param  Collection<int, User>  $candidates
     */
    private function newAttempt(AvailableStep $available, User $by, Collection $candidates): CaseStep
    {
        try {
            return CaseStep::create([
                'case_id' => $available->case->getKey(),
                'sequence' => $available->step->sequence,
                'assignee_id' => $by->getKey(),
                'candidates_at_claim' => $candidates
                    ->map(fn (User $person) => array_filter([
                        'id' => (int) $person->getKey(),
                        'name' => $person->name,
                        'covering_for' => $this->whoTheyAreCoveringFor($candidates, $person),
                    ], fn (mixed $value) => $value !== null))
                    ->values()
                    ->all(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ProcessRefused::somebodyElseHasThatStep($available->step->name);
        }
    }

    /**
     * Which earlier steps this one may be sent back to, so a screen can offer them.
     *
     * The same two rules the refusal below enforces, asked as a question instead of as a
     * door: a step in an earlier group, and one this case actually wants. It lives here
     * rather than on the screen because a screen working it out for itself is a screen
     * that comes to offer a step the engine then refuses.
     *
     * Almost nothing on the market lets an approver choose at all — Workday sends the
     * event back to whoever raised it, and Jira, Greenhouse and Camunda have no send-back an
     * approver can reach. So a screen showing this list should only ask when there is
     * more than one answer in it.
     *
     * @return Collection<int, ProcessStep>
     */
    public function whereItCanGoBackTo(AvailableStep $from): Collection
    {
        return $from->case->template->steps
            ->filter(fn (ProcessStep $step): bool => $step->group_no < $from->step->group_no
                && $this->reader->wants($from->case, $step))
            ->values();
    }

    /**
     * Where a send-back may go: one named step in an earlier group that this case
     * actually needs.
     *
     * An earlier group and not a sibling, because steps in one group run at the same time
     * and neither can be the reason the other was wrong. A step this case skipped is
     * refused too — reopening it would reopen nothing, since its conditions still say the
     * case does not want it.
     */
    private function sendBackTargetOrRefuse(AvailableStep $from, ?int $sendBackTo): ProcessStep
    {
        if ($sendBackTo === null) {
            throw ProcessRefused::aSendBackNeedsAStepToGoTo($from->step->name);
        }

        $target = $from->case->template->steps->firstWhere('sequence', $sendBackTo)
            ?? throw ProcessRefused::thisProcessHasNoSuchStep($sendBackTo);

        if ($target->group_no >= $from->step->group_no) {
            throw ProcessRefused::aSendBackOnlyGoesBackwards($from->step->name, $target->name);
        }

        // A step this case skipped is refused rather than accepted and quietly ignored:
        // reopening it would reopen nothing, since its conditions still say the case does
        // not want it, and the history would carry a line saying a step was sent back to
        // that never ran.
        if (! $this->reader->wants($from->case, $target)) {
            throw ProcessRefused::thisCaseSkippedThatStep($from->step->name, $target->name);
        }

        return $target;
    }

    /**
     * Reopen the one step a send-back names, and nothing else.
     *
     * Every other closed step in the case stays exactly as it is, in any group. Finance,
     * as the last of seven parallel clearances, sending the case back to the manager two
     * groups earlier does not throw away the six clearances that had just finished
     * correctly — work nobody had questioned, discarded on a guess that it might now be
     * stale. If HR judges one of them stale, HR sends that one back too, deliberately and
     * with a reason. The engine does not decide on the client's behalf which correct work
     * is now worthless.
     */
    private function reopen(ProcessCase $case, ProcessStep $target, User $by): void
    {
        CaseStep::query()
            ->where('case_id', $case->getKey())
            ->where('sequence', $target->sequence)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);

        $this->record($case, 'step_reopened', $by, [
            'step' => $target->name,
            'sequence' => $target->sequence,
        ]);
    }

    /**
     * Close the case once nothing is left for anybody to do.
     *
     * There is nothing to decide here: with no step available and the case still running,
     * every step this case wanted has closed with an outcome that passes. The deliberate
     * act a close is meant to be sits in the process itself — module 07's exit ends with a
     * Close step behind its own permission and a confirmation — rather than in a second
     * button beside it that could disagree with the steps.
     */
    private function closeIfNothingIsOutstanding(ProcessCase $case, ?User $by): void
    {
        $case->unsetRelation('liveSteps');

        if ($this->reader->for($case)->isNotEmpty()) {
            return;
        }

        // An exit does not finish while the leaver still has work the handover would move
        // (module 07 step 4). Here, because every answer that can finish a case comes
        // through here — the queue, the link, both ways out of a hold — so a request sent
        // by hand meets the same refusal as the button. Whichever answer would finish it
        // is refused and nothing of it is kept: at Meridian that is always the leaver's
        // letters. It holds the case, never the person; the last working day and both
        // deadlines are untouched.
        $stillToMove = Succession::whatStillHasToMove($case);

        if ($stillToMove !== null) {
            throw ProcessRefused::theirWorkHasNotMovedOn($stillToMove);
        }

        // Nor while a figure a clearance filed has no line (module 08), which would pay the
        // leaver without it. Each answer above writes its lines before it gets here, so this
        // catches a figure filed some other way. A disputed line is a line and does not stop it.
        $unwritten = SettlementLine::firstNotWrittenOn($case);

        if ($unwritten !== null) {
            throw ProcessRefused::aFigureNeverReachedTheSettlement(...$unwritten);
        }

        $this->close($case, $by, self::Completed);
    }

    private function close(ProcessCase $case, ?User $by, string $because, ?string $atStep = null): void
    {
        $case->closed_at = now();
        $case->save();

        $this->record($case, 'case_closed', $by, array_filter([
            'because' => $because,
            'step' => $atStep,
        ]));

        // Only a case that actually finished. A case turned down reads nothing like one
        // that completed, and whoever raised it is told that with the step and the reason
        // given, which only the answer that turned it down knows.
        if ($because === self::Completed) {
            $this->tell($case, TemplateCatalogue::CaseClosedSubject, $case->subject);

            // Somebody who raised their own case already has the message written to them.
            if (! $case->initiatedBy?->is($case->subject)) {
                $this->tell($case, TemplateCatalogue::CaseClosedInitiator, $case->initiatedBy);
            }
        }
    }

    /**
     * Every condition in every step of a version, as one flat list.
     *
     * @return list<array<mixed>>
     */
    private function conditionsIn(ProcessTemplate $template): array
    {
        return $template->load('steps')->steps
            ->flatMap(fn (ProcessStep $step) => $step->open_conditions ?? [])
            ->flatMap(fn (mixed $set) => (array) $set)
            ->filter(fn (mixed $condition) => is_array($condition))
            ->values()
            ->all();
    }

    /**
     * The client's value for every switch this process's conditions name, as it stands
     * now.
     *
     * Frozen so that a client raising the salary threshold on Tuesday cannot close off
     * the director's approval on a case opened on Monday — the case would then close
     * approved with nobody having approved it, and nothing on the record saying why. The
     * next case opened follows the new figure, which is what module 05 already promises a
     * client in its own words.
     *
     * @param  list<array<mixed>>  $conditions
     * @return array<string, mixed>
     */
    private function settingsAsked(array $conditions): array
    {
        $settings = app(Settings::class);

        return collect($conditions)
            ->pluck('setting')
            ->filter(fn (mixed $key) => is_string($key))
            ->unique()
            ->mapWithKeys(fn (string $key) => [$key => $settings->get($key)])
            ->all();
    }

    /**
     * The answer to every question this process asks about the person, as it stands now.
     *
     * @param  list<array<mixed>>  $conditions
     * @return array<string, mixed>
     */
    private function subjectFactsAsked(array $conditions, ?User $subject, ?EmploymentRecord $record): array
    {
        if ($subject === null) {
            return [];
        }

        return collect($conditions)
            ->filter(fn (array $condition) => ($condition['source'] ?? null) === 'subject')
            ->pluck('field')
            ->filter(fn (mixed $field) => is_string($field) && array_key_exists($field, ProcessCase::SubjectFacts))
            ->unique()
            ->mapWithKeys(fn (string $field) => [$field => $this->subjectFact($field, $subject, $record)])
            ->all();
    }

    /**
     * `equipment_issued` means equipment that is out rather than equipment that was ever
     * handed over, which is the reading that makes the clearance conditioned on it
     * disappear when IT marks the laptop returned — the failure freezing these answers
     * exists to prevent.
     */
    private function subjectFact(string $field, User $subject, ?EmploymentRecord $record): mixed
    {
        return match ($field) {
            'org_unit_id' => $record?->org_unit_id,
            'designation_id' => $record?->designation_id,
            'office_id' => $record?->office_id,
            'manages_anyone' => EmploymentRecord::query()
                ->where('reports_to_id', $subject->getKey())
                ->whereNull('effective_to')
                ->exists(),
            'equipment_issued' => EmployeeAsset::query()
                ->where('user_id', $subject->getKey())
                ->whereNull('returned_at')
                ->exists(),
        };
    }

    /**
     * The one calendar every clock on this case counts against: the office the person
     * worked in, read once here and never asked again.
     *
     * The subject's office and not the office of whoever ends up holding a step. A step
     * counted against its holder's calendar would be due one date while nobody had
     * claimed it and another the moment somebody did — Deepak in Gurgaon losing a day by
     * picking up Rakesh's Shimla clearance over a Shimla-only holiday, and gaining one
     * when the holiday is Gurgaon's. That is a deadline moving under a running case.
     *
     * Loaded with its holidays because {@see Office::closedDates()} reads through the
     * relation rather than querying, so an office loaded without them costs a query for
     * every date asked about.
     */
    private function calendarTheClocksCountAgainst(?EmploymentRecord $record, ProcessTemplate $template): Office
    {
        if ($record === null) {
            throw ProcessRefused::thisProcessHasNoLegalClock($template->name, $template->subject_kind);
        }

        $record->loadMissing('office.holidays');

        return $record->office
            ?? throw ProcessRefused::theirJobRowNamesNoOffice((string) $record->user?->name);
    }

    /**
     * Both legal deadlines, worked out from the one date they count from.
     *
     * One method rather than a copy in each place, because opening a case and amending
     * its date are the same sum and must never drift apart. The amendment exists so that
     * the date on somebody's screen is the one the statute gives; a second copy of the
     * arithmetic is exactly how that would quietly stop being true.
     *
     * @return array{CarbonImmutable, ?CarbonImmutable} the settlement deadline, and the
     *                                                  gratuity one where it is owed
     */
    private function deadlinesCountedFrom(CarbonImmutable $from, Office $office, EmploymentRecord $record, ?string $employmentEndedBy): array
    {
        return [
            $office->addWorkingDays($from, self::WorkingDaysToSettle),
            $this->gratuityDeadline($from, $record, $employmentEndedBy),
        ];
    }

    /**
     * Thirty ordinary days from the same date, where gratuity is owed at all.
     *
     * Null where it is not owed, and null is the answer the settlement statement reads to
     * decide whether to show a gratuity line, so there is nothing else to store. The
     * office calendar is deliberately absent: thirty days here means thirty days, so a
     * deadline landing on a Sunday the office also closes for stays on that Sunday.
     *
     * Owed, in this order: whatever the service where the employment ended by disablement
     * or a fixed-term contract running out; from one year for anybody else on a fixed-term
     * contract; from five years for everybody else. An exit opened without saying how the
     * employment ended gets the years with no waiver, which is the rule every exit had
     * before the raise screen asked.
     *
     * Continuous service is measured to the date the person is leaving, from the joining
     * date module 01 carries forward onto every one of their job rows — so a promotion or
     * a transfer along the way does not restart it.
     */
    private function gratuityDeadline(CarbonImmutable $from, EmploymentRecord $record, ?string $employmentEndedBy): ?CarbonImmutable
    {
        $joined = $record->joining_date;

        $years = $record->employment_type === self::FixedTermEmployment
            ? self::YearsOfServiceForGratuityOnAFixedTerm
            : self::YearsOfServiceForGratuity;

        $owed = in_array($employmentEndedBy, self::EndingsOwedGratuityWhateverTheService, true)
            || ($joined !== null && CarbonImmutable::parse($joined)->startOfDay()->addYears($years)->lessThanOrEqualTo($from));

        return $owed ? $from->addDays(self::CalendarDaysToPayGratuity) : null;
    }

    /**
     * Every step whose turn it is at the moment the date moved, with whoever is holding
     * it.
     *
     * These are the people the amendment has to reach. Their own step target has not
     * changed, but the legal deadline running underneath the whole case has, and a
     * clearance holder who is not told is one planning against a date that is gone.
     *
     * Written into the record rather than sent from here, because module 06 owns every
     * send and its log is the only thing that stops one going out twice. Frozen at this
     * moment rather than worked out again when that pass runs: somebody who finishes a
     * minute later was still holding an open step when the date moved, and the record of
     * who was affected should not depend on how quickly the pass came round.
     *
     * A step nobody has claimed names nobody, exactly as an escalation does. That is not
     * nobody to tell — the step's own rule says which group the work was meant for, and
     * module 03 is what turns that into people.
     *
     * @return list<array{sequence: int, step: string, held_by: int|null}>
     */
    private function stepsWhoseDeadlineJustMoved(ProcessCase $case): array
    {
        $case->unsetRelation('liveSteps');

        return $this->reader->for($case)
            ->map(fn (AvailableStep $available) => [
                'sequence' => (int) $available->step->sequence,
                'step' => $available->step->name,
                'held_by' => $available->isPickedUp()
                    ? (int) $available->attempt->assignee_id
                    : null,
            ])
            ->values()
            ->all();
    }

    /** The job row that is true for somebody today — the one with no end date. */
    private function liveJobRowOf(User $subject): ?EmploymentRecord
    {
        return EmploymentRecord::query()
            ->where('user_id', $subject->getKey())
            ->whereNull('effective_to')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(ProcessCase $case, string $type, ?User $actor, array $payload = []): void
    {
        CaseEvent::create([
            'case_id' => $case->getKey(),
            'actor_id' => $actor?->getKey(),
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}
