<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Settings\Settings;
use Illuminate\Support\Collection;

/**
 * Everything wrong with a process version, in plain sentences, checked at the moment
 * somebody makes it live. An empty list means it is fit to run.
 *
 * Publishing is the only place these can be caught. Before it, a client is still
 * writing and half-finished is normal; after it, the version is frozen and cases are
 * already running on it. And the failures this catches are the invisible kind — a
 * condition that is quietly false at run time skips a step, and a skipped approval
 * leaves no error, no missing screen and nothing for anyone to notice.
 *
 * Every problem is reported together rather than the first one found, for the reason
 * given on {@see ProcessRefused::cannotPublish}.
 *
 * **One planned refusal is not here yet and is not dropped: a step conditioned on a
 * form answer collected in its own group or later.** It needs to know which step
 * collects which field, and that is a form definition — module 04's table, which does
 * not exist and which this module's own plan says arrives with its own migration. The
 * check lands with module 04, against the same worked example already written down:
 * the director's approval at group 2 conditioned on a pay figure collected at group 4.
 */
final readonly class PublishCheck
{
    /** What a condition may ask about. `subject` facts are frozen onto the case at open. */
    private const Sources = ['payload', 'subject'];

    private const Operators = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'is_set'];

    /** The four that only mean anything against a number. */
    private const OrderingOperators = ['>', '>=', '<', '<='];

    /** The two that only mean anything against a list of values. */
    private const ListOperators = ['in', 'not_in'];

    /**
     * How each declared kind of client setting reads in a refusal. No entry for a whole
     * number, because the only refusal that names a kind is the one for a kind that is
     * not a whole number.
     */
    private const SettingKinds = [
        'boolean' => 'true or false',
        'text' => 'text',
    ];

    public function __construct(private ProcessTemplate $template) {}

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        // Read fresh rather than trusting whatever is already loaded. A screen that
        // lists the steps and then publishes in the same click would otherwise be
        // checked against the list as it stood before its last edit — and a step that
        // slipped through is then frozen into a live version for good. The mirror case
        // bites as well: a relation loaded while the process was still empty makes
        // publishing refuse a process that does have steps.
        $steps = $this->template->load('steps')->steps;

        if ($steps->isEmpty()) {
            return ['This process has no steps, so a case opened on it would have nothing for anybody to do.'];
        }

        return array_merge(
            $this->problemsWithTheOrderTheyRunIn($steps),
            $steps->flatMap(fn (ProcessStep $step) => $this->problemsWithStep($step))->all(),
        );
    }

    /**
     * Whether the list runs in the order it is written in.
     *
     * Two numbers describe a step's place: its position in the list, and the group it
     * shares with whatever runs beside it. The engine runs the groups in order, so a
     * step further down the list carrying an earlier group number runs *before* the one
     * above it — and this is exactly the typo a spreadsheet produces. Anjali writes the
     * manager's approval on row 1 and HR's close on row 2, types the group numbers the
     * other way round, and the exit goes live happily and then asks HR to close it
     * before the manager has seen it.
     *
     * Invisible in the same way everything else here is: nothing errors, no screen is
     * missing, and the only sign is an approval arriving after the thing it was meant to
     * approve.
     *
     * A step keeping the group before it is the ordinary parallel case and is fine. Only
     * going backwards is refused.
     *
     * @param  Collection<int, ProcessStep>  $steps
     * @return list<string>
     */
    private function problemsWithTheOrderTheyRunIn(Collection $steps): array
    {
        $problems = [];
        $previous = null;

        // Read in the order the client wrote them, which is what the relation orders by.
        foreach ($steps as $step) {
            if ($previous !== null && $step->group_no < $previous->group_no) {
                $problems[] = $this->at($step).' is in group '.$step->group_no.', so it runs before '
                    .$this->at($previous).' in group '.$previous->group_no.', which is written above it. '
                    .'Steps run in the order they are listed, and a step shares its group with whatever '
                    .'runs beside it rather than going back to an earlier one.';
            }

            $previous = $step;
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function problemsWithStep(ProcessStep $step): array
    {
        $problems = array_merge(
            $this->problemsWithWhoItBelongsTo($step),
            $this->problemsWithItsChasing($step),
            $this->problemsWithWhereItEscalates($step),
        );

        foreach ($step->open_conditions ?? [] as $set) {
            // A step carries a list of condition sets and opens when any one set is
            // fully true. An empty set is fully true by definition, so the step is not
            // actually conditional at all — reported as its own sentence rather than as
            // a malformed group, which is what it was told until 22 August 2026 and
            // which reads as nonsense to whoever wrote it.
            if ($set === []) {
                $problems[] = $this->at($step).' has an empty group of conditions, which is always '
                    .'true, so this step would open on every case.';

                continue;
            }

            // A set that is not a list of conditions has no meaning to evaluate, and at
            // run time would simply never be satisfied.
            if (! is_array($set) || ! array_is_list($set)) {
                $problems[] = $this->at($step).' has a group of conditions that is not a list of conditions.';

                continue;
            }

            foreach ($set as $condition) {
                array_push($problems, ...$this->problemsWithCondition($step, $condition));
            }
        }

        return $problems;
    }

    /**
     * Everything wrong with who this step belongs to.
     *
     * Invisible in the same way everything else here is. A step naming a way of finding
     * people that this product does not have would resolve to nobody for ever, and a step
     * that disagrees with itself about whether its actor has an account at all is worse:
     * one of the two fields is read when the link is sent and the other when somebody
     * submits, so a candidate's form could be handed to an employee, or an employee's
     * approval sent to an outside address as a link.
     *
     * @return list<string>
     */
    private function problemsWithWhoItBelongsTo(ProcessStep $step): array
    {
        $rule = (array) $step->assignee_rule;
        $kind = $rule['kind'] ?? null;

        if (! in_array($kind, AssigneeResolver::Kinds, true)) {
            return [
                $this->at($step).' belongs to ['.$this->readable($kind).'], which is not one of the ways '
                    .'a step can find its people: '.implode(', ', AssigneeResolver::Kinds).'.',
            ];
        }

        $hasNoAccount = $step->participant_kind === 'external';

        if ($kind === 'external' && ! $hasNoAccount) {
            return [
                $this->at($step).' says its actor has no account in the system, and also says its actor '
                    .'is an employee. It has to be one or the other.',
            ];
        }

        if ($hasNoAccount && $kind !== 'external') {
            return [
                $this->at($step).' says its actor has no account in the system, but then looks for one '
                    .'by ['.$this->readable($kind).']. A step for somebody with no account belongs to '
                    .'[external], which is what sends them a link instead.',
            ];
        }

        return [];
    }

    /**
     * Everything wrong with who a late step widens to.
     *
     * The same invisible kind as everything else here. An escalation naming a way of
     * finding people this product does not have would widen to nobody for ever, and the
     * only sign of it would be a clearance sitting overdue with the one person who was
     * always going to be too busy to do it.
     *
     * An escalation on a step with no target of its own can never fire, because there is
     * no moment at which the step becomes late. And a step cannot escalate to somebody
     * with no account: permission there is the link sent to their address, so widening to
     * `external` would be widening to nobody at all while reading as though somebody had
     * been brought in.
     *
     * @return list<string>
     */
    private function problemsWithWhereItEscalates(ProcessStep $step): array
    {
        $rule = (array) ($step->escalate_to ?? []);

        if ($rule === []) {
            return [];
        }

        $kind = $rule['kind'] ?? null;

        if (! in_array($kind, AssigneeResolver::Kinds, true)) {
            return [
                $this->at($step).' goes to ['.$this->readable($kind).'] when it runs late, which is not '
                    .'one of the ways a step can find its people: '.implode(', ', AssigneeResolver::Kinds).'.',
            ];
        }

        if ($kind === 'external') {
            return [
                $this->at($step).' goes to somebody with no account when it runs late. A late step '
                    .'widens to people who can sign in and act on it; somebody with no account acts '
                    .'only through the link sent to their own address.',
            ];
        }

        // The other direction, and the same reason read backwards. Permission on a step
        // answered by somebody with no account is the link sent to them and nothing else,
        // so naming employees for it to widen to when it runs late would name people the
        // engine then refuses — a chase that reads as configured and does nothing.
        if ($step->participant_kind === 'external' || ($step->assignee_rule['kind'] ?? null) === 'external') {
            return [
                $this->at($step).' is answered by somebody with no account, and says it goes to '
                    .'somebody else when it runs late. Only the person the link was sent to can answer '
                    .'it, however long it takes, so it cannot go to anybody else.',
            ];
        }

        if ($step->sla_hours === null) {
            return [
                $this->at($step).' says who it goes to when it runs late, but has no time limit of its '
                    .'own, so there is no moment at which it is late and it would never go to them.',
            ];
        }

        return [];
    }

    /**
     * Everything wrong with when this step's holder gets chased.
     *
     * Both refusals here are the invisible kind this whole check exists for: a reminder
     * that can never fire looks exactly like a reminder nobody has needed yet, and the
     * first anybody would hear of it is a statutory breach on a step nobody was chased
     * about.
     *
     * @return list<string>
     */
    private function problemsWithItsChasing(ProcessStep $step): array
    {
        $rule = $step->reminder_rule;

        if ($rule === null || $rule === []) {
            return [];
        }

        // Reminders are fractions of the step's own target, so with no target there is
        // nothing for them to be a fraction of and not one of them would ever be sent.
        if ($step->sla_hours === null) {
            return [
                $this->at($step).' is set to chase whoever holds it, but has no time limit of its own, '
                    .'so there is nothing for a reminder to be part of the way through and none would ever be sent.',
            ];
        }

        $fractions = $rule['nudge_at'] ?? null;

        if (! is_array($fractions) || ! array_is_list($fractions) || $fractions === []) {
            return [
                $this->at($step).' does not say how far through its time limit whoever holds it should be chased.',
            ];
        }

        $problems = [];

        foreach ($fractions as $fraction) {
            if (! is_numeric($fraction) || (float) $fraction <= 0 || (float) $fraction >= 1) {
                $problems[] = $this->at($step).' chases whoever holds it ['.$this->readable($fraction)
                    .'] of the way through its time limit, which is not part of the way through it. A nudge '
                    .'has to land after the step opens and before its time runs out, because the chase at '
                    .'the end already goes to the holder\'s manager.';
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function problemsWithCondition(ProcessStep $step, mixed $condition): array
    {
        if (! is_array($condition)) {
            return [$this->at($step).' has a condition that is not written as a condition.'];
        }

        $problems = [];
        $at = $this->at($step);
        $source = $condition['source'] ?? null;
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? null;

        if (! in_array($source, self::Sources, true)) {
            $problems[] = "{$at} has a condition about [".$this->readable($source).'], which is not '
                .'something a condition can ask about — only '.implode(' or ', self::Sources).'.';
        }

        if (! is_string($field) || trim($field) === '') {
            $problems[] = "{$at} has a condition that does not say which field it is about.";
        }

        if (! in_array($operator, self::Operators, true)) {
            $problems[] = "{$at} has a condition using [".$this->readable($operator).'], which is not '
                .'one of: '.implode(' ', self::Operators).'.';
        }

        // Named in the plan on 19 August 2026, when `subject_kind` was added: a hiring
        // request is about a vacant position and about nobody, so a condition on the
        // person's location has no person to read. At run time the snapshot is empty,
        // the condition is false, and the step it guards silently never happens.
        //
        // A candidate fails the same way and was being let through until 22 August 2026:
        // every one of these questions is read off a dated job row, and somebody who has
        // not joined yet has none. An onboarding branching on the office would publish
        // clean and then quietly skip the desk-setup step on every candidate.
        if ($source === 'subject' && $this->template->subject_kind !== 'employee') {
            $problems[] = $this->template->subject_kind === 'candidate'
                ? "{$at} asks about the person this process is about, and a candidate has not joined yet, "
                    .'so there is nothing on record about their department, designation or office.'
                : "{$at} asks about the person this process is about, and this process is about nobody.";
        }

        // The same objection module 01 makes to a permission with no code behind it, and
        // the same failure as a threshold typed as words: a question nothing can answer
        // is quietly false on every case, so the step it guards silently never happens.
        // A grade is the one a client will reach for first, and nothing in this product
        // holds one.
        if ($source === 'subject' && is_string($field) && ! array_key_exists($field, ProcessCase::SubjectFacts)) {
            $problems[] = "{$at} asks [".$this->readable($field).'] about the person, which is not something '
                .'this system knows about anybody. It knows '.implode(', ', ProcessCase::SubjectFacts).'.';
        }

        array_push($problems, ...$this->problemsWithWhatItComparesAgainst($step, $condition));

        return $problems;
    }

    /**
     * The right-hand side is either a fixed value or the name of a client setting, and
     * a setting's declared kind is checked here rather than at run time — the reason
     * module 01's settings registry keeps a kind at all.
     *
     * @param  array<mixed>  $condition
     * @return list<string>
     */
    private function problemsWithWhatItComparesAgainst(ProcessStep $step, array $condition): array
    {
        $at = $this->at($step);
        $operator = $condition['operator'] ?? null;
        $hasFixedValue = array_key_exists('value', $condition);
        $namesASetting = array_key_exists('setting', $condition);

        // Whether a field was answered at all is the one question with no other side.
        if ($operator === 'is_set') {
            return $hasFixedValue || $namesASetting
                ? ["{$at} has a condition asking only whether a field was answered, and something to compare it against as well."]
                : [];
        }

        if ($hasFixedValue === $namesASetting) {
            return [$hasFixedValue
                ? "{$at} has a condition comparing against both a fixed value and a client setting, and it takes one or the other."
                : "{$at} has a condition with nothing to compare against."];
        }

        if (! $namesASetting) {
            return $this->problemsWithTheValueTyped($at, $operator, $condition['value']);
        }

        $key = $condition['setting'];

        if (! is_string($key) || ! Settings::isDeclared($key)) {
            return ["{$at} compares against the client setting [".$this->readable($key).'], '
                .'which is not a setting this system has.'];
        }

        // A setting holds one value of one declared kind and there is no kind that is a
        // list, so asking whether a field is one of a setting can never be true.
        if (in_array($operator, self::ListOperators, true)) {
            return ["{$at} compares with [{$operator}] against the client setting [{$key}], which holds "
                .'a single value rather than a list of them.'];
        }

        $kind = Settings::declarationOf($key)->type;

        if (in_array($operator, self::OrderingOperators, true) && $kind !== 'integer') {
            return ["{$at} compares with [{$operator}] against the client setting [{$key}], which holds "
                .(self::SettingKinds[$kind] ?? $kind).' rather than a number.'];
        }

        return [];
    }

    /**
     * The same shape check the setting side gets, applied to a value somebody typed
     * straight into the condition.
     *
     * Added 22 August 2026 on reviewing this step, where only the setting side was
     * checked. Anjali typing the salary threshold as "fifteen lakh" published clean and
     * was then quietly false on every case afterwards, so the director's approval never
     * appeared and the case closed approved with nobody having approved it — the exact
     * failure this class exists to catch, arriving through the other door.
     *
     * @return list<string>
     */
    private function problemsWithTheValueTyped(string $at, mixed $operator, mixed $value): array
    {
        if (in_array($operator, self::OrderingOperators, true) && ! is_int($value) && ! is_float($value)) {
            return ["{$at} compares with [{$operator}] against [".$this->readable($value).'], '
                .'which is not a number.'];
        }

        if (in_array($operator, self::ListOperators, true) && ! is_array($value)) {
            return ["{$at} compares with [{$operator}] against [".$this->readable($value).'], '
                .'which is not a list of values.'];
        }

        return [];
    }

    /** Where the problem is, in the words the client gave the step. */
    private function at(ProcessStep $step): string
    {
        return "Step {$step->sequence} \"{$step->name}\"";
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => 'nothing',
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
