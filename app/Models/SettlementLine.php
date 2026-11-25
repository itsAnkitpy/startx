<?php

namespace App\Models;

use App\Process\StepForm;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

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

    /** Whoever filed the figure. Null when it came through a link from somebody with no account. */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** Paise as rupees, the way every message and the statement write them: "₹40,000.00". */
    public static function inRupees(int $paise): string
    {
        return Number::currency($paise / 100, 'INR', 'en_IN');
    }

    /**
     * The settlement statement on an exit's page: every line filed by an attempt nothing has
     * replaced, in the order of the clearances that filed them, each with whether it is
     * disputed and, for a claim, the email that told the person leaving of it.
     *
     * Nothing here is stored, so whatever totals the page adds up from these can never
     * disagree with them. A line is disputed when its clearance was closed as disputed or
     * overridden, the two ways a hold ends, since in both the department's figure stood
     * unresolved.
     *
     * The email for a claim is the latest one about that question on that clearance, whichever
     * attempt's line it named. A figure filed again unchanged after a send-back sends nothing,
     * so Deepak was told of it by the first email; a changed one by the claim-changed email.
     *
     * The day it was filed is the day its line was written, except on an override, where the
     * line is in the name of whoever put the clearance on hold, as {@see self::fileFor()} reads
     * it, and so carries the day they did: Chandni held 40,000 on the 20th and Rakesh's
     * override on the 28th is not her filing anything.
     *
     * Read from what the case has loaded rather than queried, because the page reads it once
     * for every entry it draws, and its list of emails sent and its history have loaded them
     * already.
     *
     * @return list<array{line: self, attempt: CaseStep, clearance: string, filed_at: CarbonInterface, disputed: bool, told: ?NotificationDelivery}>
     */
    public static function onTheStatementOf(ProcessCase $case): array
    {
        $live = $case->liveSteps->keyBy('id');
        $questionOf = $case->settlementLines->pluck('field_key', 'id');

        $told = $case->messagesSent
            ->whereNotNull('settlement_line_id')
            ->sortBy('id')
            ->keyBy(fn (NotificationDelivery $email): string => $email->step_sequence.'/'.$questionOf[$email->settlement_line_id]);

        return $case->settlementLines
            ->filter(fn (self $line): bool => $live->has($line->case_step_id))
            ->sortBy(fn (self $line): array => [$live[$line->case_step_id]->sequence, $line->getKey()])
            ->map(function (self $line) use ($case, $live, $told): array {
                $attempt = $live[$line->case_step_id];
                $held = $attempt->outcome === 'force_closed'
                    ? $case->events->last(fn (CaseEvent $event): bool => $event->type === 'step_acted'
                        && ($event->payload['sequence'] ?? null) === $attempt->sequence
                        && ($event->payload['outcome'] ?? null) === 'held')
                    : null;

                return [
                    'line' => $line,
                    'attempt' => $attempt,
                    'clearance' => $case->template->steps->firstWhere('sequence', $attempt->sequence)->name,
                    'filed_at' => $held->created_at ?? $line->created_at,
                    'disputed' => in_array($attempt->outcome, CaseStep::HoldResolutions, true),
                    'told' => $line->direction === self::Recovery ? $told->get($attempt->sequence.'/'.$line->field_key) : null,
                ];
            })
            ->values()->all();
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
     *
     * @return list<self> the lines written, so the person leaving can be told of each claim
     */
    public static function fileFor(CaseStep $attempt, ProcessStep $step): array
    {
        if (! in_array($attempt->outcome, CaseStep::PassingOutcomes, true)) {
            return [];
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

        return array_map(
            fn (array $line): self => self::create([...$line, 'entered_by' => $enteredBy]),
            self::owedBy($attempt, $step),
        );
    }

    /**
     * The claims the person leaving was last told of on this clearance: the recoveries on its
     * last passed attempt before this one, by question. Empty the first time it passes.
     *
     * @return Collection<string, self>
     */
    public static function recoveriesBefore(CaseStep $attempt): Collection
    {
        return self::query()
            ->where('case_step_id', CaseStep::query()->select('id')
                ->where('case_id', $attempt->case_id)
                ->where('sequence', $attempt->sequence)
                ->where('id', '<', $attempt->getKey())
                ->whereIn('outcome', CaseStep::PassingOutcomes)
                ->latest('id')
                ->limit(1))
            ->where('direction', self::Recovery)
            ->orderBy('id')->get()->keyBy('field_key');
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
