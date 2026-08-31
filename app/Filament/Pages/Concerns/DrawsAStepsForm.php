<?php

namespace App\Filament\Pages\Concerns;

use App\Models\FormField;
use App\Models\ProcessStep;
use App\Process\StepForm;
use Illuminate\Http\UploadedFile;

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
     * **The empty boxes are dropped from the page first, before anything is checked**,
     * because the engine drops them too and what is left has to be the same on both sides.
     * A yes/no left on "Not answered" arrives as an empty string, which Laravel treats as
     * an answer and the engine does not — so without this the screen lets it through and
     * the engine refuses it, and the person gets a sentence at the top of the page about a
     * box with no mark against it.
     *
     * **A step that asks nothing has nothing to check, and asking Livewire to check
     * nothing is an error rather than a pass:** handed an empty list it goes looking for
     * rules written on the page itself, finds none, and throws. A manager sign-off and
     * both hiring approvals are only a decision, so every one of them ended on an error
     * page the moment anybody pressed the button.
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
        $given = $forms->answered((array) data_get($this, $under, []));

        // Put back on the page before a word of it is checked, and that ordering is the
        // whole of it rather than tidiness: what Livewire checks is the page's own state,
        // not what is handed to it, so an empty box still sitting there is a box with
        // something in it as far as a required question is concerned.
        data_set($this, $under, $given);

        // Rewritten under the property path the inputs are bound to, so a refusal lands
        // beside the box it is about instead of at the top of the page.
        $rules = collect($forms->rules($step, $given, $finishingTheStep))
            ->mapWithKeys(fn (mixed $rules, string $key): array => [$under.'.'.$key => $rules])
            ->all();

        if ($rules !== []) {
            $this->validate(
                $rules,
                [],
                collect($forms->labels($step))
                    ->mapWithKeys(fn (string $label, string $key): array => [$under.'.'.$key => $label])
                    ->all(),
            );
        }
    }
}
