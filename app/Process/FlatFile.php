<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;

/**
 * A process written out as one row per step, and read back in.
 *
 * Both halves live in one class on purpose. The claim this module is sold on is that a
 * client's process, exported and imported again, comes back as the same process — and
 * the only way that stays true a year from now is for the sentence that writes a cell
 * and the sentence that reads it to sit beside each other.
 *
 * The shorthand in `assignee` and `open_when` is a transcription of allow-lists that
 * already exist, not a language. There is no nesting, no brackets and nothing to
 * escape: anything outside the allow-list is a rejected row rather than an expression
 * somebody has to evaluate.
 *
 * Nothing here validates a process. An import lands as a draft and {@see PublishCheck}
 * is what checks it, exactly as it checks one built by hand — a second copy of those
 * rules is how the two quietly stop agreeing. What is refused here is only a row that
 * cannot be turned into a step at all.
 */
final class FlatFile
{
    /**
     * The columns, in the order the export writes them. Only the first four are
     * required; the rest may be left out of a hand-typed file entirely.
     *
     * Two of them are read and stored against nothing, each waiting on the module that
     * owns what it points at: `form_key` on module 04's form definitions, and `notify`
     * on module 06 settling whether an entry is a bare template key or a key with a
     * recipient beside it. They are accepted rather than refused so a client's file does
     * not have to be edited when those modules arrive.
     */
    public const Columns = [
        'sequence', 'group', 'step_name', 'assignee', 'form_key', 'outcomes',
        'sla_hours', 'nudge_at', 'escalate_to', 'open_when', 'participant', 'notify',
    ];

    private const Required = ['sequence', 'group', 'step_name', 'assignee'];

    /** What a resolver in shorthand takes after the colon, or null where it takes nothing. */
    private const Resolvers = [
        'reporting_manager' => null,
        'initiators_manager' => null,
        'role_in_scope' => 'role',
        'role_global' => 'role',
        'specific_user' => 'email',

        // Takes nothing after it in this step, deliberately. It names a step actioned by
        // somebody with no account, and where their address comes from arrives with the
        // signed link that reads it — inventing a name for that cell now would mean the
        // link either honours a guess or breaks a client's file.
        'external' => null,
    ];

    /** Longest first, so `>=` is not read as `>` followed by rubbish. */
    private const Operators = ['>=', '<=', '!=', 'not_in', 'is_set', 'in', '=', '>', '<'];

    private const ListOperators = ['in', 'not_in'];

    /**
     * Turn a file into step attributes, or refuse the whole file.
     *
     * Every bad row is named together with its line number rather than stopping at the
     * first, and then nothing at all is written — the two halves of one decision,
     * recorded in full in this module's plan. A file is one process rather than a list
     * of independent records, so a process missing the row that failed is not a shorter
     * process, it is one that reaches the end with a department never having been asked.
     *
     * @return list<array<string, mixed>>
     */
    public function read(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw ProcessRefused::cannotImport($path, ['The file cannot be read.']);
        }

        try {
            return $this->rowsFrom($handle, $path);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Write a version's steps out in the shape {@see read} takes back.
     *
     * Every column is written even where a hand-typed file would leave it blank, so
     * what comes back does not depend on which defaults happened to apply.
     */
    public function write(ProcessTemplate $template): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::Columns, escape: '');

        foreach ($template->steps as $step) {
            // A step opening on either of two condition sets is the one thing that spans
            // rows, so it is written as the sets it has and as one row when it has none.
            $sets = $step->open_conditions ?: [null];

            foreach ($sets as $set) {
                fputcsv($out, $this->cellsFor($step, $set), escape: '');
            }
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @param  resource  $handle
     * @return list<array<string, mixed>>
     */
    private function rowsFrom($handle, string $path): array
    {
        $heading = fgetcsv($handle, escape: '');

        if ($heading === false) {
            throw ProcessRefused::cannotImport($path, ['The file is empty.']);
        }

        // Saving as "CSV UTF-8" in Excel writes three bytes in front of the first
        // character. Left on, they stick to the first column's name, so `sequence` does
        // not match "sequence" and every row looks like it is missing its first column.
        $heading[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $heading[0]);
        $heading = array_map(fn (mixed $name) => strtolower(trim((string) $name)), $heading);

        $problems = $this->problemsWithTheHeading($heading);

        if ($problems !== []) {
            throw ProcessRefused::cannotImport($path, $problems);
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            // Every spreadsheet writes a newline at the end of the file, which arrives
            // as a row of one empty cell rather than as the end of the file.
            if ($cells === [null] || $this->isBlank($cells)) {
                continue;
            }

            if (count($cells) !== count($heading)) {
                $problems[] = "Line {$line} has ".count($cells).' columns where the heading has '
                    .count($heading).'.';

                continue;
            }

            $row = array_combine($heading, array_map(fn (mixed $cell) => trim((string) $cell), $cells));

            try {
                $rows[] = ['line' => $line] + $this->stepFrom($row);
            } catch (ProcessRefused $refusal) {
                $problems[] = "Line {$line}: ".$refusal->getMessage();
            }
        }

        $steps = $this->merged($rows, $problems);

        if ($problems !== []) {
            throw ProcessRefused::cannotImport($path, $problems);
        }

        return $steps;
    }

    /**
     * @param  list<string>  $heading
     * @return list<string>
     */
    private function problemsWithTheHeading(array $heading): array
    {
        $problems = [];

        foreach (array_diff(self::Required, $heading) as $missing) {
            $problems[] = "The heading row has no [{$missing}] column, and every row needs one.";
        }

        // A misspelled heading would otherwise drop a whole column silently — every
        // step's deadline gone because the column says `sla_hour`, and the process
        // publishes clean.
        foreach (array_diff($heading, self::Columns) as $unknown) {
            $problems[] = "The heading row has a column called [{$unknown}], which is not one of: "
                .implode(', ', self::Columns).'.';
        }

        foreach (array_count_values($heading) as $name => $count) {
            if ($count > 1) {
                $problems[] = "The heading row has [{$name}] in it {$count} times.";
            }
        }

        return $problems;
    }

    /**
     * One row as one step, with the row's single condition set kept beside it until
     * merging decides whether this step has one set or two.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function stepFrom(array $row): array
    {
        $at = fn (string $column): string => $row[$column] ?? '';

        return [
            'sequence' => $this->countingNumber($at('sequence'), 'sequence'),
            'group_no' => $this->countingNumber($at('group'), 'group'),
            'name' => $this->words($at('step_name')),
            'participant_kind' => $this->participant($at('participant')),
            'assignee_rule' => $this->assignee($at('assignee')),
            'allowed_outcomes' => $this->outcomes($at('outcomes')),
            'sla_hours' => $at('sla_hours') === '' ? null : $this->countingNumber($at('sla_hours'), 'sla_hours'),
            'reminder_rule' => $this->nudges($at('nudge_at')),

            // Who a late step widens to, written exactly as `assignee` is, because it is
            // the same six ways of finding people and a client should not have to learn a
            // second way of typing them. Left empty, the step widens to nobody.
            'escalate_to' => $at('escalate_to') === '' ? null : $this->assignee($at('escalate_to')),
            'open_conditions' => [],
            'on_open' => [],
            'on_complete' => [],
            'set' => $at('open_when') === '' ? null : $this->conditionSet($at('open_when')),
        ];
    }

    /**
     * Fold the rows that describe one step into one step.
     *
     * A step opening on either of two condition sets is written as two rows sharing a
     * sequence, a group and a name, which is the only situation where a step spans rows.
     * Everything else about the two rows has to agree, because a second row quietly
     * carrying a different deadline would give the step whichever row was read first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $problems
     * @return list<array<string, mixed>>
     */
    private function merged(array $rows, array &$problems): array
    {
        $steps = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $set = $row['set'];
            unset($row['line'], $row['set']);

            $sequence = $row['sequence'];
            $first = $steps[$sequence] ?? null;

            if ($first === null) {
                $steps[$sequence] = ['row' => $row, 'line' => $line, 'sets' => $set === null ? [] : [$set]];

                continue;
            }

            if ($first['row'] !== $row) {
                $problems[] = "Line {$line} is step {$sequence} again, on line {$first['line']}, but the "
                    .'two rows do not otherwise say the same thing. Two rows are one step only when a '
                    .'step opens on either of two sets of conditions, and then everything but '
                    .'[open_when] matches.';

                continue;
            }

            if ($set === null || $first['sets'] === []) {
                $problems[] = "Line {$line} is step {$sequence} again, on line {$first['line']}, and one "
                    .'of the two rows has no [open_when]. A step written twice is a step that opens on '
                    .'either of two sets of conditions, so both rows need one.';

                continue;
            }

            $steps[$sequence]['sets'][] = $set;
        }

        ksort($steps);

        return array_values(array_map(
            fn (array $step): array => ['open_conditions' => $step['sets']] + $step['row'],
            $steps,
        ));
    }

    /**
     * `role_in_scope:hr` and the five beside it — module 03's ways of finding a step's
     * people, written the way somebody types them into a spreadsheet.
     *
     * @return array<string, string>
     */
    private function assignee(string $shorthand): array
    {
        [$kind, $argument] = array_pad(explode(':', $shorthand, 2), 2, null);

        if (! array_key_exists($kind, self::Resolvers)) {
            throw ProcessRefused::thatRowIsWrong(
                "[{$shorthand}] is not somebody a step can belong to. A step belongs to "
                .$this->theResolversInWords().'.'
            );
        }

        $takes = self::Resolvers[$kind];

        if ($takes === null) {
            if ($argument !== null) {
                throw ProcessRefused::thatRowIsWrong("[{$kind}] takes nothing after it, and this row has [{$argument}] after it.");
            }

            return ['kind' => $kind];
        }

        if ($argument === null || trim($argument) === '') {
            throw ProcessRefused::thatRowIsWrong("[{$kind}] has to say which {$takes} it means, written [{$kind}:something].");
        }

        return ['kind' => $kind, $takes => trim($argument)];
    }

    /** The six of them as somebody would type them, for a refusal to show. */
    private function theResolversInWords(): string
    {
        $written = [];

        foreach (self::Resolvers as $kind => $takes) {
            $written[] = $takes === null ? $kind : "{$kind}:<{$takes}>";
        }

        return implode(', ', $written);
    }

    /**
     * One set of conditions, joined by ` and `. Every one of them parses to exactly
     * {source, field, operator, value} or the same with a client setting on the right,
     * and there is nothing else it can be.
     *
     * @return list<array<string, mixed>>
     */
    private function conditionSet(string $written): array
    {
        return array_values(array_map(
            fn (string $one): array => $this->condition(trim($one)),
            explode(' and ', $written),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function condition(string $written): array
    {
        $operators = implode('|', array_map(fn (string $o): string => preg_quote($o, '/'), self::Operators));

        if (preg_match('/^([a-z_]+)\.([\w.]+)\s+('.$operators.')(?:\s+(.*))?$/i', $written, $found) !== 1) {
            throw ProcessRefused::thatRowIsWrong(
                "[{$written}] is not a condition. One is written like [payload.annual_ctc > 1500000], "
                .'using one of: '.implode(' ', self::Operators).'.'
            );
        }

        [, $source, $field, $operator] = $found;
        $right = isset($found[4]) ? trim($found[4]) : '';

        // Every word the grammar owns is read however it was typed. A spreadsheet
        // capitalises the first word of a cell on its own, and a client copying a
        // yes/no answer writes what Excel shows them, which is FALSE. Only the field
        // name keeps its case, because that is the client's own key rather than ours.
        $operator = strtolower($operator);
        $condition = ['source' => strtolower($source), 'field' => $field, 'operator' => $operator];

        // Whether a field was answered at all is the one question with no other side.
        if ($operator === 'is_set') {
            if ($right !== '') {
                throw ProcessRefused::thatRowIsWrong("[{$written}] asks only whether a field was answered, and has something to compare it against as well.");
            }

            return $condition;
        }

        if ($right === '') {
            throw ProcessRefused::thatRowIsWrong("[{$written}] has nothing to compare against.");
        }

        if (stripos($right, 'setting.') === 0) {
            return $condition + ['setting' => substr($right, strlen('setting.'))];
        }

        return $condition + ['value' => in_array($operator, self::ListOperators, true)
            ? array_values(array_map($this->typed(...), array_map('trim', explode(',', $right))))
            : $this->typed($right)];
    }

    /**
     * A number typed into a spreadsheet is a number, not the text of one — the
     * difference between a threshold a condition can compare against and one that is
     * quietly false on every case. The same for true and false: as text they compare
     * loosely against a real answer and land the wrong way round.
     */
    private function typed(string $written): mixed
    {
        $word = strtolower($written);

        return match (true) {
            $word === 'true' => true,
            $word === 'false' => false,
            preg_match('/^-?\d+$/', $written) === 1 => (int) $written,
            is_numeric($written) => (float) $written,
            default => $written,
        };
    }

    /**
     * @return list<string>
     */
    private function outcomes(string $written): array
    {
        if ($written === '') {
            return ['approved', 'rejected'];
        }

        $outcomes = array_values(array_map('trim', explode(',', $written)));
        $unknown = array_diff($outcomes, ProcessStep::ChoosableOutcomes);

        if ($unknown !== []) {
            throw ProcessRefused::thatRowIsWrong(
                '['.implode(', ', $unknown).'] is not something anyone chooses at a step. A step offers: '
                .implode(', ', ProcessStep::ChoosableOutcomes).'.'
            );
        }

        return $outcomes;
    }

    /**
     * @return array{nudge_at: list<float>}|null
     */
    private function nudges(string $written): ?array
    {
        if ($written === '') {
            return null;
        }

        $fractions = array_map('trim', explode(',', $written));

        foreach ($fractions as $fraction) {
            if (! is_numeric($fraction)) {
                throw ProcessRefused::thatRowIsWrong("[{$fraction}] is not a fraction of the step's time limit.");
            }
        }

        return ['nudge_at' => array_values(array_map(floatval(...), $fractions))];
    }

    private function participant(string $written): string
    {
        if ($written === '') {
            return 'internal';
        }

        if (! in_array($written, ProcessStep::ParticipantKinds, true)) {
            throw ProcessRefused::thatRowIsWrong(
                "[{$written}] is not who takes a step. It is ".implode(' or ', ProcessStep::ParticipantKinds).'.'
            );
        }

        return $written;
    }

    private function countingNumber(string $written, string $column): int
    {
        if (preg_match('/^\d+$/', $written) !== 1 || (int) $written < 1) {
            throw ProcessRefused::thatRowIsWrong(
                "[{$column}] is [{$written}], and it has to be a whole number counting from one."
            );
        }

        return (int) $written;
    }

    private function words(string $written): string
    {
        if ($written === '') {
            throw ProcessRefused::thatRowIsWrong('[step_name] cannot be blank — it is the client\'s own words for the step.');
        }

        // A step name holds 255 characters. Checked here rather than left to the insert,
        // because the insert fails partway down the file with a database error instead of
        // naming the line, which is the one thing this reader promises.
        if (mb_strlen($written) > 255) {
            throw ProcessRefused::thatRowIsWrong(
                '[step_name] is '.mb_strlen($written).' characters long, and a step name holds 255.'
            );
        }

        return $written;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $set
     * @return list<string>
     */
    private function cellsFor(ProcessStep $step, ?array $set): array
    {
        $written = [
            'sequence' => (string) $step->sequence,
            'group' => (string) $step->group_no,
            'step_name' => $step->name,
            'assignee' => $this->writtenAssignee($step->assignee_rule ?? []),
            'outcomes' => implode(',', $step->allowed_outcomes ?? []),
            'sla_hours' => $step->sla_hours === null ? '' : (string) $step->sla_hours,
            'nudge_at' => implode(',', array_map($this->writtenValue(...), $step->reminder_rule['nudge_at'] ?? [])),
            'escalate_to' => $step->escalate_to === null ? '' : $this->writtenAssignee($step->escalate_to),
            'open_when' => $set === null ? '' : $this->writtenConditionSet($set),
            'participant' => $step->participant_kind,
        ];

        return array_map(fn (string $column): string => $written[$column] ?? '', self::Columns);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function writtenAssignee(array $rule): string
    {
        $kind = (string) ($rule['kind'] ?? '');
        $takes = self::Resolvers[$kind] ?? null;

        return $takes === null ? $kind : $kind.':'.$rule[$takes];
    }

    /**
     * @param  array<int, array<string, mixed>>  $set
     */
    private function writtenConditionSet(array $set): string
    {
        return implode(' and ', array_map($this->writtenCondition(...), $set));
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function writtenCondition(array $condition): string
    {
        $written = $condition['source'].'.'.$condition['field'].' '.$condition['operator'];

        if ($condition['operator'] === 'is_set') {
            return $written;
        }

        if (array_key_exists('setting', $condition)) {
            return $written.' setting.'.$condition['setting'];
        }

        $value = $condition['value'];

        return $written.' '.(is_array($value)
            ? implode(',', array_map($this->writtenValue(...), $value))
            : $this->writtenValue($value));
    }

    private function writtenValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
