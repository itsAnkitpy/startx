<?php

namespace App\Process;

use App\Models\CaseStep;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\User;
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
     * The default staged reminders, as fractions of a step's own target: a nudge halfway
     * through and another three-quarters of the way through, with the escalation above
     * the holder at the end. A step may name its own in `reminder_rule` and almost none
     * will.
     */
    public const NudgeAt = [0.5, 0.75];

    /**
     * @return Collection<int, AvailableStep>
     */
    public function for(ProcessCase $case): Collection
    {
        return $this->forAll(new CaseCollection([$case]));
    }

    /**
     * Every step across the client company that is this person's turn right now.
     *
     * The queue screen's whole answer, and the same one the handover preview counts.
     * Open cases are read, each one is asked which of its steps is waiting, and each of
     * those is put through the rule that works out who it belongs to — a step nobody has
     * touched has no row with anybody's name on it, so there is nothing to look up and
     * the only way to know is to work it out.
     *
     * A step somebody else has already picked up is not this person's turn even where
     * they could have taken it, which is what keeps a shared queue from showing five
     * people the same piece of work after one of them has started it.
     *
     * ponytail: who a waiting step belongs to is worked out one step at a time, so a
     * client with a great many open cases pays a handful of reads per waiting step.
     * Measured on a seeded company rather than guessed, and left alone: storing who each
     * step belongs to is the thing this whole module exists to avoid. Narrow the open
     * cases — by department, or by the processes this person's roles ever appear in —
     * the day a real client's list is slow.
     *
     * @return Collection<int, AvailableStep>
     */
    public function waitingOn(User $person, ?AssigneeResolver $resolver = null): Collection
    {
        $resolver ??= new AssigneeResolver;

        $open = ProcessCase::query()
            ->whereNull('closed_at')
            ->whereNull('cancelled_at')
            ->get();

        return $this->forAll($open)
            ->filter(function (AvailableStep $available) use ($resolver, $person): bool {
                $takenBySomebodyElse = $available->attempt?->assignee_id !== null
                    && (int) $available->attempt->assignee_id !== (int) $person->getKey();

                if ($takenBySomebodyElse) {
                    return false;
                }

                // Past its own target the step widens to whoever it escalates to, and
                // widening is all it does — the people it already belonged to keep it.
                return $resolver->resolve($available->case, $available->step, $available->escalationOwed)
                    ->contains(fn (User $candidate) => (int) $candidate->getKey() === (int) $person->getKey());
            })
            ->values();
    }

    /**
     * @param  CaseCollection<int, ProcessCase>  $cases
     * @return Collection<int, AvailableStep>
     */
    public function forAll(CaseCollection $cases): Collection
    {
        // A fixed number of queries for any number of cases: the frozen versions, their
        // steps, the live rows, and the one calendar each case's clocks count against.
        // Loaded together rather than per case, which is the whole reason one person's
        // list can be answered while they watch the page load — and the holidays are
        // loaded with the office because {@see Office::closedDates()} reads through the
        // relation rather than querying, so an office without them costs a query for
        // every date asked about.
        $cases->loadMissing([
            'template.steps',
            'liveSteps',
            'subjectEmploymentRecord.office.holidays',
        ]);

        return $this->withManagersToEscalateTo(
            $cases->flatMap(fn (ProcessCase $case) => $this->forOneCase($case))
        );
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
        $calendar = $this->calendarOf($case);

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
                $available[] = $this->withItsClock(
                    new AvailableStep($case, $step, $since, $attempt),
                    $calendar
                );
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
     * The one calendar every clock on this case counts against: the office the person the
     * case is about worked in, read through the job row the case is pinned to.
     *
     * The subject's office and not the office of whoever holds a step. A step counted
     * against its holder's calendar is due one date while nobody has claimed it and
     * another the moment somebody does — Deepak in Gurgaon losing a day by picking up
     * Rakesh's Shimla clearance over a Shimla-only holiday, and gaining one when the
     * holiday is Gurgaon's. So a step's target is the same date whoever is holding it,
     * and reassigning it moves nothing.
     *
     * Null on a case about nobody or about a candidate, neither of whom has a job row and
     * therefore an office. Those cases get no step targets at all — see the note in module
     * 02's plan; a hiring process wanting one is module 05's question to answer, and
     * inventing a fallback calendar here would be answering it for them.
     */
    private function calendarOf(ProcessCase $case): ?Office
    {
        $record = $case->subjectEmploymentRecord;

        return $record instanceof EmploymentRecord ? $record->office : null;
    }

    /**
     * The same step with its own service clock worked out: when it is due, how many
     * staged reminders have fallen due, and whether the chase is owed above the holder.
     *
     * A step with no target of its own, or a case with no calendar to count one against,
     * comes back untouched — nothing is due and nothing is ever chased.
     *
     * **Nothing here pauses.** A held step is not a passing outcome, so it is still this
     * case's turn and its clock is still running; so is the clock of a step waiting on a
     * candidate who has stopped replying. Every service-desk product in evidence offers a
     * pause and we refuse one, because a paused step still burns the case's statutory
     * clock underneath and a step showing green on a case running red is a lie told by the
     * dashboard the client bought this product for.
     */
    private function withItsClock(AvailableStep $available, ?Office $calendar): AvailableStep
    {
        $hours = $available->step->sla_hours;

        if ($calendar === null || $hours === null) {
            return $available;
        }

        $since = $available->availableSince;
        $dueAt = $calendar->addWorkingHours($since, $hours);
        $now = CarbonImmutable::now();

        return new AvailableStep(
            case: $available->case,
            step: $available->step,
            availableSince: $since,
            attempt: $available->attempt,
            dueAt: $dueAt,
            nudgesOwed: $this->nudgesOwedBy($available->step, $calendar, $since, $hours, $now, $dueAt),
            escalationOwed: $now->greaterThanOrEqualTo($dueAt),
        );
    }

    /**
     * How many staged reminders have fallen due by now.
     *
     * The shipped shape everywhere in evidence is a nudge at half the target and another
     * at three-quarters, and it replaced a rule shaped as "remind every N hours" — against
     * a two-working-day clock, a reminder that lands after the deadline is worth nothing,
     * so the stages have to be fractions of the target rather than a fixed interval. A step
     * may name its own fractions in `reminder_rule` and almost none will.
     *
     * **A fraction is a fraction of the working time allowed, not of the days between now
     * and the deadline, and the difference is a person chased on their day off.** A step
     * that opens at nine on Friday evening with eight hours to run is due at five on Monday
     * morning; half the ordinary time between those two moments is Sunday lunchtime, and
     * half the *work* is one hour into Monday. Dividing the calendar would have nudged
     * somebody at Sunday lunchtime for a step they had lost three hours of. So each stage
     * is worked out with the same calendar the deadline was, and compared against now.
     *
     * This is what is owed in total, not what is new. What has already gone out is the
     * notification log's answer to give, and it is the only thing that stops a reminder
     * repeating.
     */
    private function nudgesOwedBy(
        ProcessStep $step,
        Office $calendar,
        CarbonImmutable $since,
        int $hours,
        CarbonImmutable $now,
        CarbonImmutable $dueAt,
    ): int {
        $fractions = collect($step->reminder_rule['nudge_at'] ?? self::NudgeAt);

        // Past the target, every stage before it has fallen due — publishing refuses a
        // fraction that is not short of the whole target. Worth its own line because the
        // scheduled pass that chases people reads mostly overdue steps.
        if ($now->greaterThanOrEqualTo($dueAt)) {
            return $fractions->count();
        }

        return $fractions
            ->filter(fn (mixed $fraction) => $now->greaterThanOrEqualTo(
                $calendar->addWorkingHours($since, $hours * (float) $fraction)
            ))
            ->count();
    }

    /**
     * Fill in the manager each overdue step's chase goes above, in one query for the
     * whole list rather than one per step.
     *
     * Resolved here rather than when the step was built because it is asked at the moment
     * of sending — who is above somebody today, not who was above them when the case
     * opened. Usually nothing owes an escalation at all, and then nothing is read.
     *
     * @param  Collection<int, AvailableStep>  $available
     * @return Collection<int, AvailableStep>
     */
    private function withManagersToEscalateTo(Collection $available): Collection
    {
        $holders = $available
            ->filter(fn (AvailableStep $step) => $step->escalationOwed)
            ->map(fn (AvailableStep $step) => $step->attempt?->assignee_id)
            ->filter()
            ->unique();

        if ($holders->isEmpty()) {
            return $available;
        }

        $above = EmploymentRecord::query()
            ->whereIn('user_id', $holders->all())
            ->whereNull('effective_to')
            ->whereNotNull('reports_to_id')
            ->with('reportsTo')
            ->get()
            ->keyBy('user_id');

        return $available->map(function (AvailableStep $step) use ($above) {
            if (! $step->escalationOwed || $step->attempt?->assignee_id === null) {
                return $step;
            }

            $manager = $above->get($step->attempt->assignee_id)?->reportsTo;

            return $manager === null ? $step : new AvailableStep(
                case: $step->case,
                step: $step->step,
                availableSince: $step->availableSince,
                attempt: $step->attempt,
                dueAt: $step->dueAt,
                nudgesOwed: $step->nudgesOwed,
                escalationOwed: true,
                escalateTo: $manager,
            );
        });
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
     * skipped — and a skipped approval reads exactly like a given one.
     *
     * **Publishing does not yet refuse the two ways a condition can be permanently
     * unanswerable** — a field no form collects, and a field collected in the step's own
     * group or later. {@see PublishCheck} says so in its own words: the refusal needs to
     * know which step collects which field, and that is module 04's table. Until it
     * lands, a template can be published whose condition can never be true, and the step
     * behind it silently never happens. Wiring that refusal is module 04's, and this is
     * the sentence that says why it cannot be skipped.
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
