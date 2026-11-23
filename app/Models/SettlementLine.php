<?php

namespace App\Models;

use App\Process\StepForm;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One recovery from a leaver, or one amount payable to them, as a clearance filed it.
 *
 * Written when the clearance passes, in the same write as its outcome, and never changed or
 * removed after: the database refuses both. A wrong figure is corrected by sending the
 * clearance back, which files a new attempt with lines of its own and leaves this one on the
 * attempt it replaced.
 *
 * It keeps a copy of the question's words, the reason and the figure rather than reading them
 * off the attempt, because the attempt's row can be written to and this one cannot.
 */
#[Fillable(['case_id', 'case_step_id', 'field_key', 'entered_by', 'direction', 'label', 'reason', 'amount_paise'])]
class SettlementLine extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const Recovery = 'recovery';

    public const Payable = 'payable';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Write the lines an attempt owes, if it has just passed.
     *
     * Every answer that can pass a clearance calls this: approving from the queue, approving
     * through a link, and both ways out of a hold. A hold, a rejection and a send-back write
     * nothing, since none of them is the clearance's figure being filed.
     *
     * In the name of whoever typed the figure. That is the step's holder, except on an
     * override, where the holder may never have seen it: Chandni holds finance at 40,000 and
     * leaves, her held work goes to Rakesh, and Priya overrides. The figure is Chandni's, and
     * the history says so in the line that put it on hold.
     */
    public static function fileFor(CaseStep $attempt, ProcessStep $step): void
    {
        if (! in_array($attempt->outcome, CaseStep::PassingOutcomes, true)) {
            return;
        }

        $enteredBy = $attempt->outcome === 'force_closed'
            ? CaseEvent::query()
                ->where('case_id', $attempt->case_id)
                ->where('type', 'step_acted')
                ->where('payload->sequence', (string) $attempt->sequence)
                ->where('payload->outcome', 'held')
                ->latest('id')
                ->value('actor_id')
            : $attempt->assignee_id;

        foreach (self::owedBy($attempt, $step) as $line) {
            self::create([...$line, 'entered_by' => $enteredBy]);
        }
    }

    /**
     * The first figure filed on a passed clearance of this case that has no line, as the step's
     * name and the question's words, or null when every one has its line.
     *
     * Only attempts nothing has replaced: a replaced attempt's lines are history, not a figure
     * still owed one. A disputed line counts as written, since a contested amount must not stop
     * a payment the law requires.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function firstNotWrittenOn(ProcessCase $case): ?array
    {
        $passed = $case->liveSteps->whereIn('outcome', CaseStep::PassingOutcomes);

        $written = self::query()->whereIn('case_step_id', $passed->modelKeys())->get()
            ->map(fn (self $line): string => $line->case_step_id.'/'.$line->field_key)
            ->all();

        foreach ($passed as $attempt) {
            $step = $case->template->steps->firstWhere('sequence', $attempt->sequence);

            foreach (self::owedBy($attempt, $step) as $line) {
                if (! in_array($attempt->getKey().'/'.$line['field_key'], $written, true)) {
                    return [$step->name, $line['label']];
                }
            }
        }

        return null;
    }

    /**
     * The lines an attempt's answers come to: one for each question marked as money owed
     * that it was asked and answered above zero.
     *
     * Paise are rounded, never cast. A hundred times 0.29 is a hair under 29 in floating
     * point, and a cast throws the hair away with the paisa. A money answer has at most two
     * decimal places, so rounding is exact.
     *
     * @return list<array{case_id: int, case_step_id: int, field_key: string, direction: string, label: string, reason: ?string, amount_paise: int}>
     */
    private static function owedBy(CaseStep $attempt, ProcessStep $step): array
    {
        $answers = (array) $attempt->payload;
        $asked = (new StepForm)->asking($step, $answers)->keyBy('key');
        $lines = [];

        foreach ($asked as $field) {
            $answer = $answers[$field->key] ?? null;

            if ($field->settles_as === null || ! is_numeric($answer)) {
                continue;
            }

            $paise = (int) round((float) $answer * 100);

            if ($paise <= 0) {
                continue;
            }

            $lines[] = [
                'case_id' => $attempt->case_id,
                'case_step_id' => $attempt->getKey(),
                'field_key' => $field->key,
                'direction' => $field->settles_as,
                'label' => $field->label,
                'reason' => self::reasonFor($field, $asked, $answers),
                'amount_paise' => $paise,
            ];
        }

        return $lines;
    }

    /**
     * What the form's own "what is it for" question was answered, in the client's words: a
     * choice reads as its label ("Salary advance"), and typed text as typed.
     *
     * @param  Collection<string, FormField>  $asked
     * @param  array<string, mixed>  $answers
     */
    private static function reasonFor(FormField $field, Collection $asked, array $answers): ?string
    {
        $question = $asked->get((string) $field->reason_from);
        $answer = $question === null ? null : ($answers[$question->key] ?? null);

        if ($question?->type === FormField::Select) {
            $answer = collect($question->options)->firstWhere('value', $answer)['label'] ?? $answer;
        }

        return is_scalar($answer) && trim((string) $answer) !== '' ? (string) $answer : null;
    }
}
