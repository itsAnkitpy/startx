<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\EmployeeAsset;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\User;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
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

    /**
     * Section 7(3) of the Payment of Gratuity Act, 1972: gratuity is payable "within
     * thirty days from the date it becomes payable". Thirty ordinary days, not thirty
     * working days, so the office calendar must not touch this one.
     */
    private const CalendarDaysToPayGratuity = 30;

    /**
     * Gratuity is owed on leaving after five years of continuous service. The Act also
     * waives the five years where the person left by death or disablement, and that is
     * deliberately not here.
     *
     * ponytail: nothing in the product records why somebody left — `employment_status` is
     * a free string with no agreed words in it yet. Add the waiver when module 07 records
     * the reason for an exit, which is the module that would set those words.
     */
    private const YearsOfServiceForGratuity = 5;

    private AvailableSteps $reader;

    private AssigneeResolver $assignees;

    public function __construct()
    {
        $this->reader = new AvailableSteps;
        $this->assignees = new AssigneeResolver;
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
     * is ever recomputed because a calendar changed underneath a running case.
     */
    public function open(
        ProcessTemplate $template,
        ?User $subject = null,
        ?User $by = null,
        ?string $statutoryFrom = null,
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
                ?? throw ProcessRefused::thePersonHasNoCurrentJobRow($subject->getKey(), $template->name);
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
            );
        }

        return DB::transaction(function () use ($template, $subject, $record, $by, $statutoryFrom, $conditions, $office, $statutoryDueAt, $gratuityDueAt): ProcessCase {
            $case = ProcessCase::create([
                'template_id' => $template->getKey(),
                'subject_user_id' => $subject?->getKey(),
                'subject_employment_record_id' => $record?->getKey(),
                'initiated_by' => $by?->getKey(),
                'opened_at' => now(),
                'statutory_from' => $statutoryFrom,
                'statutory_due_at' => $statutoryDueAt,
                'gratuity_due_at' => $gratuityDueAt,
                'settings_snapshot' => $this->settingsAsked($conditions),
                'subject_facts_snapshot' => $this->subjectFactsAsked($conditions, $subject, $record),
            ]);

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

            // A process whose every step this case skips has nothing for anybody to do,
            // and left open it would sit in the queue for ever, breach its statutory
            // deadline and have no step to chase. Publishing already refuses a process
            // with no steps for that exact reason; this is the same case arriving through
            // the conditions instead. An exit for somebody who manages nobody and holds
            // no equipment is a real one.
            $this->closeIfNothingIsOutstanding($case, $by);

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

            if ($outcome === 'held' && trim((string) $reason) === '') {
                throw ProcessRefused::needsAReason("Holding [{$step->name}]");
            }

            $target = $outcome === 'sent_back'
                ? $this->sendBackTargetOrRefuse($available, $sendBackTo)
                : null;

            $attempt = $this->attemptToWriteOn($available, $by, $candidates);

            $attempt->outcome = $outcome;
            $attempt->acted_at = now();
            $attempt->payload = array_merge((array) $attempt->payload, $payload);
            $attempt->save();

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

            return $attempt;
        });
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
            $attempt->payload = array_merge((array) $attempt->payload, $payload);
            $attempt->save();

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
     */
    public function resolveHold(ProcessCase $case, int $sequence, string $outcome, User $by, string $reason): CaseStep
    {
        $this->refuseIfTheCaseIsAboutThem($case, $by, 'Ending a hold');

        if (! in_array($outcome, CaseStep::HoldResolutions, true)) {
            throw ProcessRefused::notAWayOutOfAHold($outcome);
        }

        if (trim($reason) === '') {
            throw ProcessRefused::needsAReason("Recording [{$outcome}] on a held step");
        }

        return DB::transaction(function () use ($case, $sequence, $outcome, $by, $reason): CaseStep {
            $available = $this->availableStepOrRefuse($case, $sequence);
            $attempt = $available->attempt;

            if ($attempt === null || $attempt->outcome !== 'held') {
                throw ProcessRefused::thatStepIsNotOnHold($available->step->name, $outcome);
            }

            // Turning the argument into a disputed settlement line is the holding
            // department's own decision, so only the person holding it may record it — the
            // same rule an ordinary action on a claimed step follows. `force_closed` is
            // deliberately not gated here: it exists precisely for the holder being
            // unavailable, it keeps them on the row and names whoever overrode it, and the
            // authority to override belongs to whoever can reach that button. Module 07
            // builds that screen and owns that check.
            // ponytail: force_closed reachable by any account holder until module 07 gates it.
            if ($outcome === 'closed_disputed' && (int) $attempt->assignee_id !== (int) $by->getKey()) {
                throw ProcessRefused::somebodyElseHasThatStep($available->step->name);
            }

            // The reason and whoever gave it go to the history and nowhere else. A step's
            // payload is what its form collected, and a case reads its own answers back
            // out of it to decide which steps to open — so putting our own bookkeeping in
            // there makes it answerable by a condition.
            $attempt->outcome = $outcome;
            $attempt->acted_at = now();
            $attempt->save();

            $this->record($case, 'step_acted', $by, [
                'step' => $available->step->name,
                'sequence' => $available->step->sequence,
                'outcome' => $outcome,
                'reason' => $reason,
                'held_by' => $attempt->assignee_id,
            ]);

            $this->closeIfNothingIsOutstanding($case, $by);

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
     */
    public function amendTheDateTheClocksCountFrom(
        ProcessCase $case,
        string $statutoryFrom,
        User $by,
        string $reason,
    ): void {
        $this->refuseIfTheCaseIsAboutThem($case, $by, 'Moving the date a case counts its deadlines from');

        $reason = trim($reason);

        if ($reason === '') {
            throw ProcessRefused::needsAReason('Moving the date a case counts its deadlines from');
        }

        DB::transaction(function () use ($case, $statutoryFrom, $by, $reason): void {
            $this->holdTheCaseStill($case);

            if ($case->state !== ProcessCase::Open) {
                throw ProcessRefused::thisCaseHasAlreadyEnded($case->state);
            }

            $from = CarbonImmutable::parse($statutoryFrom)->startOfDay();

            // The same date again moves nothing, so it says nothing — the same choice
            // picking up a step that is already yours makes. A line in the one record
            // this product asks a tribunal to read should mean something happened.
            if ($case->statutory_from?->isSameDay($from)) {
                return;
            }

            $record = $case->subjectEmploymentRecord;
            $office = $this->calendarTheClocksCountAgainst($record, $case->template);

            $before = [
                'counted_from' => $case->statutory_from?->toDateString(),
                'statutory_due_at' => $case->statutory_due_at?->toDateString(),
                'gratuity_due_at' => $case->gratuity_due_at?->toDateString(),
            ];

            [$statutoryDueAt, $gratuityDueAt] = $this->deadlinesCountedFrom($from, $office, $record);

            $case->statutory_from = $from;
            $case->statutory_due_at = $statutoryDueAt;
            $case->gratuity_due_at = $gratuityDueAt;
            $case->save();

            $this->record($case, 'case_amended', $by, [
                'counted_from' => ['was' => $before['counted_from'], 'now' => $from->toDateString()],
                'statutory_due_at' => ['was' => $before['statutory_due_at'], 'now' => $statutoryDueAt->toDateString()],
                'gratuity_due_at' => ['was' => $before['gratuity_due_at'], 'now' => $gratuityDueAt?->toDateString()],
                'reason' => $reason,
                'counted_against' => $office->name,
                'open_steps' => $this->stepsWhoseDeadlineJustMoved($case),
            ]);
        });
    }

    /**
     * Stop a case that nobody approved and nobody rejected — a withdrawn resignation is
     * the one that needs it. Steps already acted on stay exactly as they are and stay
     * readable; untouched steps never had a row. Nothing is deleted, and a cancelled case
     * can never be closed.
     */
    public function cancel(ProcessCase $case, User $by, string $reason): void
    {
        $this->refuseIfTheCaseIsAboutThem($case, $by, 'Cancelling a case');

        $reason = trim($reason);

        if ($reason === '') {
            throw ProcessRefused::needsAReason('Cancelling a case');
        }

        if (mb_strlen($reason) > self::LongestReason) {
            throw ProcessRefused::thatReasonIsTooLong(self::LongestReason);
        }

        DB::transaction(function () use ($case, $by, $reason): void {
            $this->holdTheCaseStill($case);

            if ($case->state !== ProcessCase::Open) {
                throw ProcessRefused::thisCaseHasAlreadyEnded($case->state);
            }

            $case->cancelled_at = now();
            $case->cancellation_reason = $reason;
            $case->cancelled_by = $by->getKey();
            $case->save();

            $this->record($case, 'case_cancelled', $by, ['reason' => $reason]);
        });
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
     * Only this half. *Which* other employees may cancel a case, move that date or
     * override a hold is a permission with no answer in the plan yet and module 07's
     * screens to own — HR overriding a hold is deliberately not a candidate for the step
     * it overrides, so the step gate is the wrong question to ask here.
     *
     * ponytail: any other account holder still reaches all three until module 07 gates them.
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

        $this->close($case, $by, 'completed');
    }

    private function close(ProcessCase $case, ?User $by, string $because, ?string $atStep = null): void
    {
        $case->closed_at = now();
        $case->save();

        $this->record($case, 'case_closed', $by, array_filter([
            'because' => $because,
            'step' => $atStep,
        ]));
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
            ?? throw ProcessRefused::theirJobRowNamesNoOffice($record->user_id, $template->name);
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
    private function deadlinesCountedFrom(CarbonImmutable $from, Office $office, EmploymentRecord $record): array
    {
        return [
            $office->addWorkingDays($from, self::WorkingDaysToSettle),
            $this->gratuityDeadline($from, $record),
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
     * Continuous service is measured to the date the person is leaving, from the joining
     * date module 01 carries forward onto every one of their job rows — so a promotion or
     * a transfer along the way does not restart it.
     */
    private function gratuityDeadline(CarbonImmutable $from, EmploymentRecord $record): ?CarbonImmutable
    {
        $joined = $record->joining_date;

        if ($joined === null) {
            return null;
        }

        $owed = CarbonImmutable::parse($joined)
            ->startOfDay()
            ->addYears(self::YearsOfServiceForGratuity)
            ->lessThanOrEqualTo($from);

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
                'held_by' => $available->attempt?->assignee_id === null
                    ? null
                    : (int) $available->attempt->assignee_id,
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
