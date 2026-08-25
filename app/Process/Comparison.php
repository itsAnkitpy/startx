<?php

namespace App\Process;

/**
 * One `{operator}` comparison, and the whole list of operators a client may write.
 *
 * Extracted 25 August 2026, when a question's own condition became the third thing to
 * need it. The list was already written twice — once as the arms of a `match` that runs a
 * step's opening conditions, once as the allow-list publishing checks them against — and
 * a third copy is how `is_set` ends up meaning one thing on a step and another on a
 * question. There is one list and one meaning, here.
 *
 * No brackets, no nesting, no expressions, no eval. Each side is resolved by whoever
 * calls this — a step reads a case's answers or its frozen snapshot of the person, a
 * question reads the answers on its own form — and only the comparison itself is shared.
 */
final class Comparison
{
    public const Operators = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'is_set'];

    /** The four that only mean anything against a number. */
    public const Ordering = ['>', '>=', '<', '<='];

    /** The two that only mean anything against a list of values. */
    public const AgainstAList = ['in', 'not_in'];

    /**
     * Whether the comparison is true.
     *
     * A missing left-hand side makes every comparison false except `is_set`, which is
     * the question "was this answered at all". So a step guarded by an unanswered figure
     * is skipped, and a question guarded by one is not asked — and both of those are
     * silent, which is why publishing refuses a condition that can never be answered.
     *
     * The comparisons are deliberately loose. A designation id arrives from a client's
     * flat file as text and from a picker as a number, and both mean the same
     * designation; PHP 8's loose comparison no longer treats text as zero, which is what
     * made the old objection to it fair.
     */
    public static function holds(mixed $left, mixed $operator, mixed $right): bool
    {
        if ($operator === 'is_set') {
            return $left !== null;
        }

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
