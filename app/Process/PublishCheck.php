<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Settings\Settings;

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

        return $steps->flatMap(fn (ProcessStep $step) => $this->problemsWithStep($step))->all();
    }

    /**
     * @return list<string>
     */
    private function problemsWithStep(ProcessStep $step): array
    {
        $problems = [];

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
        if ($source === 'subject' && $this->template->subject_kind === 'none') {
            $problems[] = "{$at} asks about the person this process is about, and this process is about nobody.";
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
