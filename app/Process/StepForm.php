<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\FormField;
use App\Models\ProcessStep;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The questions a step asks, and the rules those questions are checked against.
 *
 * One place turns a client's rows into Laravel validation rules, and it is the only place
 * allowed to. The rules are applied **on the server, always** — what the browser does is
 * a convenience and never the check, because the browser is the half of this an employee
 * controls.
 *
 * **Nothing a client types ever reaches Laravel's rule parser as text.** A rule string is
 * a small language: `exists:users`, `regex:...`, and a client-editable row carrying one
 * would let whoever edits the finance clearance form decide which rules run on it. So a
 * question's limits are named numbers — `min`, `max`, `max_length` — read by the `match`
 * below and turned into rules here. The question's own key is held to a plain identifier
 * by the database, which is what keeps it out of Laravel's dot notation as well.
 */
class StepForm
{
    /**
     * How long a one-line answer may be before it is refused, and how long a paragraph
     * may be. Both overridable per question with `max_length`, both here so that a client
     * who sets nothing still cannot post a novel into a jsonb column.
     */
    public const DefaultTextLength = 255;

    public const DefaultParagraphLength = 5000;

    /**
     * What this step asks, in the order the client put the questions in.
     *
     * A step with no form asks nothing, which is the default and is right for a manager
     * sign-off that is only a decision.
     *
     * @return Collection<int, FormField>
     */
    public function fields(ProcessStep $step): Collection
    {
        if ($step->form_definition_id === null) {
            return collect();
        }

        return FormField::query()
            ->where('form_definition_id', $step->form_definition_id)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The Laravel rules for one step's answers, keyed by question.
     *
     * @return array<string, mixed>
     */
    public function rules(ProcessStep $step): array
    {
        $rules = [];

        foreach ($this->fields($step) as $field) {
            $rules[$field->key] = [...$this->presence($field), ...$this->forType($field)];

            if ($field->type === FormField::Multiselect) {
                // Each chosen value, not the list. Written separately because Laravel
                // checks the members of an array under their own key.
                $rules[$field->key.'.*'] = [Rule::in($field->choices())];
            }
        }

        return $rules;
    }

    /**
     * The client's own words for each question, so a refusal reads "Imprest card
     * returned is required" rather than naming the column it is stored under.
     *
     * @return array<string, string>
     */
    public function labels(ProcessStep $step): array
    {
        return $this->fields($step)
            ->mapWithKeys(fn (FormField $field): array => [$field->key => $field->label])
            ->all();
    }

    /**
     * Refuse an answer to a question this step does not ask, and keep only what was
     * actually answered.
     *
     * This is not tidiness. Every live step's answers are read together when the engine
     * works out which steps a case still wants, so without this the person holding IT's
     * clearance can post `settlement_cleared` and switch off the finance clearance behind
     * it — a step that never happens and an exit that closes as though it did.
     *
     * It sits on the engine rather than on a screen because there are three ways an
     * answer arrives: the queue screen, a link sent to somebody with no account, and a
     * console command. A guard on one of them is a guard on none.
     *
     * An empty box is dropped rather than stored: see the comment on the filter below.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function onlyWhatThisStepAsks(ProcessStep $step, array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        $asked = $this->fields($step)->pluck('key')->all();

        $unasked = array_values(array_diff(array_keys($payload), $asked));

        if ($unasked !== []) {
            throw ProcessRefused::thatStepDoesNotAskThat($step->name, $unasked);
        }

        // A box left empty is not an answer, and filing it as one is not harmless. A
        // later step that opens when the recovery amount was answered at all reads an
        // empty box as an answer, so the director's approval appears on an exit where
        // nobody entered a figure — and a threshold compared against an empty box is
        // quietly false on every case, which is the same failure the other way round.
        // Not answered is recorded by the answer not being there.
        return array_filter(
            $payload,
            fn (mixed $answer): bool => $answer !== null && $answer !== '' && $answer !== [],
        );
    }

    /**
     * Whether an answer has to be there at all.
     *
     * A required yes/no question uses `present` rather than `required`, because "no" is a
     * real answer and Laravel counts a false as nothing at all. Asking whether the
     * mailbox was switched off and getting an explicit no is the whole point of asking.
     *
     * @return list<string>
     */
    private function presence(FormField $field): array
    {
        if (! $field->required) {
            return ['nullable'];
        }

        return $field->type === FormField::Boolean ? ['present'] : ['required'];
    }

    /**
     * One arm per question type, and nothing clever behind it. Twelve `match` arms are
     * the whole of what a type-class hierarchy would have been.
     *
     * The three pickers lean on `exists` against the client's own tables, and the tenant
     * wall does the rest: row-level security is on those tables, so a picker cannot be
     * made to point at another company's row by editing the number in the browser. That
     * is the check, not an afterthought — it is why these are pickers and not free text.
     *
     * @return list<mixed>
     */
    private function forType(FormField $field): array
    {
        $limits = (array) $field->validation;

        return match ($field->type) {
            FormField::Text => ['string', 'max:'.$this->length($limits, self::DefaultTextLength)],
            FormField::Textarea => ['string', 'max:'.$this->length($limits, self::DefaultParagraphLength)],
            FormField::Number => ['numeric', ...$this->range($limits)],
            // Never negative. A recovery of minus two thousand rupees is a payable, and
            // the settlement statement has a separate line for that.
            FormField::Money => ['numeric', 'min:0', ...$this->range($limits)],
            // The date the browser's own date box sends, and nothing looser. `date` on
            // its own accepts "tomorrow", which is a date today and a different one next
            // week — and these are read off a closed case years later.
            FormField::Date => ['date_format:Y-m-d'],
            FormField::Select => ['string', Rule::in($field->choices())],
            FormField::Multiselect => ['array'],
            FormField::Boolean => ['boolean'],
            FormField::UserPicker => ['integer', Rule::exists('users', 'id')],
            FormField::OrgUnitPicker => ['integer', Rule::exists('org_units', 'id')],
            FormField::DesignationPicker => ['integer', Rule::exists('designations', 'id')],
            // ponytail: the upload path is its own step and its own security review, and
            // publishing refuses a form carrying one until then, so this is unreachable
            // on a live form. Delete this arm's guard when uploads land.
            FormField::File => ['prohibited'],
        };
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function length(array $limits, int $default): int
    {
        $asked = $limits['max_length'] ?? null;

        return is_numeric($asked) && (int) $asked > 0 ? (int) $asked : $default;
    }

    /**
     * A client's own floor and ceiling on a number, as numbers. Anything that is not a
     * number is ignored rather than passed through.
     *
     * @param  array<string, mixed>  $limits
     * @return list<string>
     */
    private function range(array $limits): array
    {
        $rules = [];

        if (is_numeric($limits['min'] ?? null)) {
            $rules[] = 'min:'.(float) $limits['min'];
        }

        if (is_numeric($limits['max'] ?? null)) {
            $rules[] = 'max:'.(float) $limits['max'];
        }

        return $rules;
    }
}
