<?php

namespace App\Process;

use App\Models\CaseStep;
use App\Models\FormField;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The documents attached to a case, and who may open one.
 *
 * One place, because a document is read in more than one: the card of whoever is holding
 * a later step, and the case's own page when that is built. Two readers working it out
 * separately is how one of them comes to show a document the other would have refused.
 *
 * **A document is not stored anywhere of its own.** It is an ordinary answer on an
 * ordinary step, and what is kept is a small record of where the file went. So the
 * questions this class answers are questions about answers, and the tenant wall that
 * covers every other answer covers these without a word here saying so.
 */
final class CaseDocuments
{
    /**
     * Whether this person may open the documents on this case at all.
     *
     * **Per document would be the wrong grain.** Somebody clearing finance on an exit has
     * to be able to read what HR attached — that is what verifying a clearance means, and
     * it is the reason the finance step comes after the HR one. So the question is about
     * the case, and holding any step of it is the answer.
     *
     * Two ways to hold one, and both count. A step waiting on them right now is read from
     * the one place that answers whose turn it is, so this rule and the queue screen
     * cannot come to disagree. A step they have already picked up or acted on has a row
     * with their name on it — the finance clearance stays readable to the person who did
     * it after the exit has closed, which is the whole point of keeping the evidence.
     *
     * Nobody else, until the case's own page arrives and brings a real rule with it. An
     * exit clearance document is about a named person and is not company reading.
     */
    public function mayBeOpenedBy(ProcessCase $case, User $person): bool
    {
        $alreadyActed = CaseStep::query()
            ->where('case_id', $case->getKey())
            ->where('assignee_id', $person->getKey())
            ->exists();

        if ($alreadyActed) {
            return true;
        }

        return (new AvailableSteps)
            ->waitingOn($person)
            ->contains(fn (AvailableStep $waiting): bool => (int) $waiting->case->getKey() === (int) $case->getKey());
    }

    /**
     * Every document already attached to this case, in the order its steps run.
     *
     * The step's name and the client's own words for the question travel with each one,
     * because "anjali-card.jpg" on its own tells whoever is verifying nothing about what
     * it was attached as.
     *
     * @return Collection<int, array{sequence: int, step: string, question: string, label: string, name: string, size: int}>
     */
    public function on(ProcessCase $case): Collection
    {
        $steps = $case->template->steps->keyBy('sequence');
        $questions = $this->questionsFor($steps);

        // The attempt that counts at each step, never one a send-back replaced. Both rows
        // carry a photograph of the same card and only one of them is the answer, so
        // listing both would put two identical links on the screen and leave whoever is
        // verifying to guess which is current.
        return $case->liveSteps
            ->sortBy('sequence')
            ->flatMap(function (CaseStep $answered) use ($steps, $questions): array {
                $step = $steps->get($answered->sequence);

                if ($step === null) {
                    return [];
                }

                $asked = $questions[$step->form_definition_id] ?? [];
                $found = [];

                foreach ((array) $answered->payload as $question => $answer) {
                    if (! $this->isADocument($asked[$question] ?? null, $answer)) {
                        continue;
                    }

                    $found[] = [
                        'sequence' => (int) $answered->sequence,
                        'step' => (string) $step->name,
                        'question' => (string) $question,
                        'label' => (string) $asked[$question]->label,
                        'name' => (string) ($answer['name'] ?? 'Document'),
                        'size' => (int) ($answer['size'] ?? 0),
                    ];
                }

                return $found;
            })
            ->values();
    }

    /**
     * One document, by the step it was attached at and the question it answers.
     *
     * Null covers every way of asking for one that is not there — a step that was never
     * answered, a question that is not a document, a name somebody typed into the
     * address bar. The caller turns all of them into the same not-found, so which it was
     * is not something an address can be used to discover.
     *
     * @return array{disk: string, path: string, name: string, size: int, type: string}|null
     */
    public function find(ProcessCase $case, int $sequence, string $question): ?array
    {
        $step = $case->template->steps->firstWhere('sequence', $sequence);

        if ($step === null) {
            return null;
        }

        $answered = $case->liveSteps->firstWhere('sequence', $sequence);

        $answer = ((array) $answered?->payload)[$question] ?? null;

        $asked = $step->form_definition_id === null
            ? null
            : FormField::query()
                ->where('form_definition_id', $step->form_definition_id)
                ->where('key', $question)
                ->first();

        return $this->isADocument($asked, $answer) ? $answer : null;
    }

    /**
     * Every question on the forms these steps use, as `[form][key] => question`.
     *
     * One query for the whole case rather than one per step. A card on the queue screen
     * asks this for its case, and there are several cards.
     *
     * @param  Collection<int, ProcessStep>  $steps
     * @return array<int, array<string, FormField>>
     */
    private function questionsFor(Collection $steps): array
    {
        $forms = $steps->pluck('form_definition_id')->filter()->unique();

        if ($forms->isEmpty()) {
            return [];
        }

        $questions = [];

        foreach (FormField::query()->whereIn('form_definition_id', $forms)->get() as $field) {
            $questions[$field->form_definition_id][$field->key] = $field;
        }

        return $questions;
    }

    /**
     * Whether this answer is a document that may be opened.
     *
     * **The form decides, not the answer.** Whether something is a document is a fact
     * about the question the client asked, so it is read off the question rather than
     * worked out from the shape of what is stored under it. Read the other way round, an
     * answer somebody wrote by hand into a box asking for words would read back as a
     * document and open like one — which is a person naming any file on our disk as their
     * own clearance evidence.
     *
     * The shape is still checked, because this walks whatever is in the answers column
     * and an old case may have been answered by a version of this product that had no
     * documents at all.
     */
    private function isADocument(?FormField $question, mixed $answer): bool
    {
        return $question?->type === FormField::File
            && is_array($answer)
            && is_string($answer['disk'] ?? null)
            && is_string($answer['path'] ?? null)
            && ($answer['path'] ?? '') !== '';
    }
}
