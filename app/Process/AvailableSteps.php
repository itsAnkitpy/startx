<?php

namespace App\Process;

use App\Models\CaseStep;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as CaseCollection;
use Illuminate\Support\Collection;

/**
 * Whose turn it is, worked out rather than looked up.
 *
 * Nothing is written when a step becomes somebody's turn. A step is available when
 * every step in every earlier group has either closed with a passing outcome or was
 * skipped because its conditions were not met — so there is no row for two people to
 * create at once, and no case that stalls silently because a row nobody wrote was never
 * waited on.
 *
 * **Everything that goes looking for work comes through here**: the queue screen, the
 * reminders, and the breach report. A department that never opens its clearance at all
 * is the exact case a reminder exists for, and it is the one case with no row to find —
 * so a reminder written against `case_steps` instead would leave the only step nobody
 * started as the only step nobody chases, and the first anyone would hear of it is a
 * statutory breach. Coming through one reader also means a step can never be chaseable
 * and ungateable, or the reverse.
 *
 * The price of deriving is that reading is where the cost sits, and it is processor time
 * rather than database time — Airflow derives task readiness the same way and its
 * measured wall hits exactly there. So {@see self::forAll()} reads its cases, their
 * frozen step definitions and their live rows in a fixed number of queries however many
 * cases there are, and the walk below stays plain array work with nothing in it that can
 * reach for the database.
 */
final class AvailableSteps
{
    /**
     * @return Collection<int, AvailableStep>
     */
    public function for(ProcessCase $case): Collection
    {
        return $this->forAll(new CaseCollection([$case]));
    }

    /**
     * @param  CaseCollection<int, ProcessCase>  $cases
     * @return Collection<int, AvailableStep>
     */
    public function forAll(CaseCollection $cases): Collection
    {
        // Three queries for any number of cases: the frozen versions, their steps, and
        // the live rows. Loaded together rather than per case, which is the whole reason
        // one person's list can be answered while they watch the page load.
        $cases->loadMissing(['template.steps', 'liveSteps']);

        return $cases->flatMap(fn (ProcessCase $case) => $this->forOneCase($case));
    }

    /**
     * Whether this case wants a step at all, whatever has happened to it since.
     *
     * A closed step and a skipped step read the same from the outside — neither is
     * anybody's turn — so a send-back needs this to tell them apart before it names one.
     */
    public function wants(ProcessCase $case, ProcessStep $step): bool
    {
        $case->loadMissing('liveSteps');

        return $this->conditionsAreMet($step, $case, $this->answersOn($case->liveSteps));
    }

    /**
     * @return list<AvailableStep>
     */
    private function forOneCase(ProcessCase $case): array
    {
        if ($case->state !== ProcessCase::Open) {
            return [];
        }

        $live = $case->liveSteps->keyBy('sequence');
        $answers = $this->answersOn($live);

        $available = [];

        // The moment the group being looked at became everybody's turn. It starts as the
        // case's own opening time and moves forward to whenever each group finished.
        $since = CarbonImmutable::parse($case->opened_at);

        foreach ($case->template->steps->groupBy('group_no')->sortKeys() as $group) {
            $groupClosedAt = $since;
            $groupPasses = true;

            foreach ($group as $step) {
                // A step whose conditions are not met is skipped. It passes the gate and
                // contributes no waiting time, because nobody ever waited on it.
                if (! $this->conditionsAreMet($step, $case, $answers)) {
                    continue;
                }

                $attempt = $live->get($step->sequence);

                if ($attempt !== null && in_array($attempt->outcome, CaseStep::PassingOutcomes, true)) {
                    $closedAt = CarbonImmutable::parse($attempt->acted_at);
                    $groupClosedAt = $closedAt->greaterThan($groupClosedAt) ? $closedAt : $groupClosedAt;

                    continue;
                }

                $groupPasses = false;
                $available[] = new AvailableStep($case, $step, $since, $attempt);
            }

            // Everything after an unfinished group is blocked by it, including a group
            // whose own steps are all skipped.
            if (! $groupPasses) {
                break;
            }

            $since = $groupClosedAt;
        }

        return $available;
    }

    /**
     * Everything the case's own forms have collected so far, later answers winning.
     *
     * These are the one thing that is meant to decide a branch while a case runs,
     * because they arrive during it. Everything else a condition can ask about was
     * frozen when the case opened.
     *
     * @param  Collection<int, CaseStep>  $live
     * @return array<string, mixed>
     */
    private function answersOn(Collection $live): array
    {
        return $live->sortBy('sequence')->pluck('payload')->collapse()->all();
    }

    /**
     * Whether this step is wanted on this case at all.
     *
     * A step carries a list of condition sets and is wanted when any one set is fully
     * true. No sets means the step is always wanted, which is the ordinary case.
     *
     * @param  array<string, mixed>  $answers
     */
    private function conditionsAreMet(ProcessStep $step, ProcessCase $case, array $answers): bool
    {
        $sets = $step->open_conditions ?? [];

        if ($sets === []) {
            return true;
        }

        foreach ($sets as $set) {
            if ($this->setHolds((array) $set, $case, $answers)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $set
     * @param  array<string, mixed>  $answers
     */
    private function setHolds(array $set, ProcessCase $case, array $answers): bool
    {
        foreach ($set as $condition) {
            if (! $this->conditionHolds((array) $condition, $case, $answers)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One `{source, field, operator, value}` comparison, and nothing beyond it. No
     * brackets, no nesting, no expressions, no eval.
     *
     * **Nothing here reads live data.** A `subject` question is answered from the
     * snapshot taken when the case opened, and a client setting from the copy frozen
     * beside it. Rakesh managed three people when he resigned, so his exit needs a
     * handover step; on day two the exit's own succession moves those three onto
     * Chandni, and asking again would delete the handover step with nothing on the
     * record saying it was ever expected.
     *
     * An unanswered field makes every comparison false, so the step it guards is
     * skipped. That is only safe because publishing has already refused the two ways a
     * condition can be permanently unanswerable — a field no form collects, and a field
     * collected in the step's own group or later.
     *
     * The comparisons are deliberately loose. A designation id arrives from the client's
     * flat file as text and from a picker as a number, and both mean the same
     * designation; PHP 8's loose comparison no longer treats text as zero, which is what
     * made the old objection to it fair.
     *
     * @param  array<mixed>  $condition
     * @param  array<string, mixed>  $answers
     */
    private function conditionHolds(array $condition, ProcessCase $case, array $answers): bool
    {
        $field = $condition['field'] ?? null;

        $asked = ($condition['source'] ?? null) === 'subject'
            ? (array) ($case->subject_facts_snapshot ?? [])
            : $answers;

        $left = is_string($field) ? ($asked[$field] ?? null) : null;
        $operator = $condition['operator'] ?? null;

        if ($operator === 'is_set') {
            return $left !== null;
        }

        $right = array_key_exists('setting', $condition)
            ? ((array) ($case->settings_snapshot ?? []))[$condition['setting']] ?? null
            : $condition['value'] ?? null;

        if ($left === null || $right === null) {
            return false;
        }

        return match ($operator) {
            '=' => $left == $right,
            '!=' => $left != $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            'in' => in_array($left, (array) $right),
            'not_in' => ! in_array($left, (array) $right),
            default => false,
        };
    }
}
