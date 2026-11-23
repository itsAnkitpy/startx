<?php

namespace App\Filament\Pages\Concerns;

use App\Models\FormField;
use App\Models\ProcessStep;
use App\Process\StepForm;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * What a screen needs in order to draw a step's own form and check what was typed into
 * it.
 *
 * Two screens do this now — the queue, where a step is decided, and the screen a request
 * is raised from, where a case's first step is answered before the case exists — and a
 * third arrives with the send-back box. The three pieces here were all written on the
 * queue screen first; they are here rather than copied because a second copy of "which
 * questions is this step asking, and what is wrong with the answers" is exactly how a
 * screen ends up demanding something the engine does not, or letting through something
 * it does.
 *
 * None of this is the check. The engine applies the client's own rules to every answer
 * however it arrived, and refuses in one sentence. What this adds is the refusal landing
 * **under the box it is about** rather than at the top of the page, which only a screen
 * can do because only a screen knows what the boxes are bound to.
 *
 * `$under` is where the answers live on the component — `answers` on a page with one
 * form, `answers.7.2` on a page showing several at once — and everything here works from
 * it, so neither page has to know the other's shape.
 */
trait DrawsAStepsForm
{
    /**
     * The rows a picker offers, as `id => name`.
     *
     * Read by the question partial for every list a client's form can draw.
     *
     * @return array<int, string>
     */
    public function optionsFor(FormField $field): array
    {
        return (new StepForm)->optionsFor($field);
    }

    /**
     * What somebody has attached to one question, by the name they gave it.
     *
     * Only ever the name. The file has gone nowhere of ours yet and does not until the
     * step is decided; the one address that exists for it meanwhile is Livewire's own,
     * which hands a file over to be saved rather than shown. The screen names what is
     * attached either way, which is what tells a chosen file apart from none once the
     * page redraws.
     */
    public function attachedTo(string $path): ?string
    {
        $chosen = data_get($this, $path);

        return $chosen instanceof UploadedFile ? $chosen->getClientOriginalName() : null;
    }

    /**
     * What was typed, dropped to what was actually answered, and refused under the right
     * box if the client's own rules are not met.
     *
     * **What is checked is what was typed with the empty boxes dropped**, because the
     * engine drops them too and what is left has to be the same on both sides. A yes/no
     * left on "Not answered" arrives as an empty string, which Laravel treats as an answer
     * and the engine does not — so without this the screen lets it through and the engine
     * refuses it, and the person gets a sentence at the top of the page about a box with no
     * mark against it.
     *
     * **A copy is checked, never the page**, so a refusal leaves the card exactly as it was
     * typed. Checked on the page, the dropped boxes stayed dropped after a refusal, and the
     * next press no longer said one had been emptied: Chandni empties the 40,000 she held,
     * a payable of 12.345 is refused, she corrects it, and the 40,000 was filed and claimed
     * from Deepak while her box read empty.
     *
     * **A step that asks nothing is not checked at all**, such as a manager sign-off or
     * either hiring approval, so a refusal still showing on another card stays beside its
     * box.
     *
     * `$finishingTheStep` is false for a rejection, a hold and a send-back, and it decides
     * only whether a required question is demanded — the engine draws the same line for
     * the same reason, and the two have to agree or the screen refuses what the engine
     * allows.
     *
     * Nothing is handed back. What was kept is already on the page, which is where the
     * caller reads it from and where the engine is handed it from — a second copy
     * returned here is a second thing that can drift from the boxes a person is looking
     * at.
     */
    protected function checkedAgainstTheForm(ProcessStep $step, string $under, bool $finishingTheStep): void
    {
        $forms = new StepForm;
        $typed = (array) data_get($this, $under, []);
        $given = $forms->answered($typed);

        // Rewritten under the property path the inputs are bound to, so a refusal lands
        // beside the box it is about instead of at the top of the page.
        $rules = collect($forms->rules($step, $given, $finishingTheStep))
            ->mapWithKeys(fn (mixed $rules, string $key): array => [$under.'.'.$key => $rules])
            ->all();

        if ($rules !== []) {
            $checked = [];
            data_set($checked, $under, $given);

            Validator::make(
                $checked,
                $rules,
                [],
                collect($forms->labels($step))
                    ->mapWithKeys(fn (string $label, string $key): array => [$under.'.'.$key => $label])
                    ->all(),
            )->validate();

            // What Livewire's own check does once it passes, so a refusal from the press
            // before does not stay under a box that is now right.
            $this->resetErrorBag();
        }

        // Checked, the boxes somebody emptied go back as empty rather than not at all, so the
        // engine takes away what was in them: Chandni empties the 40,000 she held and
        // approves, and nothing is owed. Never a document, because a card never shows one it
        // already holds, so its empty box is not one taken away.
        $emptied = $forms->fields($step)
            ->where('type', '!=', FormField::File)
            ->pluck('key')
            ->intersect(array_keys(array_diff_key($typed, $given)));

        data_set($this, $under, $given + array_fill_keys($emptied->all(), null));
    }
}
