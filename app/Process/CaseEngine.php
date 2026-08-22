<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\EmployeeAsset;
use App\Models\EmploymentRecord;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
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

    private AvailableSteps $reader;

    public function __construct()
    {
        $this->reader = new AvailableSteps;
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
     * The two deadlines are deliberately not worked out here; they arrive with the clocks
     * and the chasing. `statutory_from` is accepted and stored because it is the date they
     * both count from.
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

        return DB::transaction(function () use ($template, $subject, $record, $by, $statutoryFrom, $conditions): ProcessCase {
            $case = ProcessCase::create([
                'template_id' => $template->getKey(),
                'subject_user_id' => $subject?->getKey(),
                'subject_employment_record_id' => $record?->getKey(),
                'initiated_by' => $by?->getKey(),
                'opened_at' => now(),
                'statutory_from' => $statutoryFrom,
                'settings_snapshot' => $this->settingsAsked($conditions),
                'subject_facts_snapshot' => $this->subjectFactsAsked($conditions, $subject, $record),
            ]);

            $this->record($case, 'case_opened', $by, [
                'process' => $template->name,
                'version' => $template->version,
            ]);

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
            $attempt = $this->attemptToWriteOn($available, $by);

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

            if (! in_array($outcome, (array) $step->allowed_outcomes, true)) {
                throw ProcessRefused::outcomeNotOffered($step->name, $outcome, (array) $step->allowed_outcomes);
            }

            if ($outcome === 'held' && trim((string) $reason) === '') {
                throw ProcessRefused::needsAReason("Holding [{$step->name}]");
            }

            $target = $outcome === 'sent_back'
                ? $this->sendBackTargetOrRefuse($available, $sendBackTo)
                : null;

            $attempt = $this->attemptToWriteOn($available, $by);

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
     * Stop a case that nobody approved and nobody rejected — a withdrawn resignation is
     * the one that needs it. Steps already acted on stay exactly as they are and stay
     * readable; untouched steps never had a row. Nothing is deleted, and a cancelled case
     * can never be closed.
     */
    public function cancel(ProcessCase $case, User $by, string $reason): void
    {
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
     * The row this action is written on.
     *
     * Three shapes. Nobody has touched the step, so a row appears now. Somebody has it
     * and has not acted, so they act on their own row and nobody else may. Or the step is
     * coming round again after the case was sent back from it — then the old attempt is
     * marked replaced first and the new row goes in behind it, which is the only order the
     * one-live-attempt rule allows. A step being held is not replaced: a hold and its
     * ending are one attempt.
     */
    private function attemptToWriteOn(AvailableStep $available, User $by): CaseStep
    {
        $attempt = $available->attempt;

        if ($attempt === null) {
            return $this->newAttempt($available, $by);
        }

        // An attempt that closed without passing: the step a send-back reopened, or the
        // step the case was sent back from coming round again. The old one is marked
        // replaced first and the new row goes in behind it, which is the only order the
        // one-live-attempt rule allows.
        if ($attempt->outcome !== null && $attempt->outcome !== 'held') {
            $attempt->superseded_at = now();
            $attempt->save();

            return $this->newAttempt($available, $by);
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
    private function newAttempt(AvailableStep $available, User $by): CaseStep
    {
        try {
            return CaseStep::create([
                'case_id' => $available->case->getKey(),
                'sequence' => $available->step->sequence,
                'assignee_id' => $by->getKey(),
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
