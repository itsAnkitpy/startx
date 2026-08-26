<?php

namespace App\Filament\Pages;

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\Designation;
use App\Models\FormField;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use App\Process\CaseDocuments;
use App\Process\CaseEngine;
use App\Process\StepForm;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * What is waiting on the person signed in, and the two buttons that act on it.
 *
 * The first screen in the product, and it exists to make the engine visible. Everything
 * the last three modules built is either on this page or provably behind it: which steps
 * are this person's turn, the ones nobody has picked up yet having no row anywhere, a
 * step somebody else took disappearing from the list, cover while somebody is away
 * putting another person's work here, and a refusal arriving as a sentence rather than as
 * an error page.
 *
 * Nothing is stored to build it. The list is worked out on every load from the open cases
 * and the rules on their steps, which is the whole claim of module 03 — so if this page
 * is right, resolving on read is right.
 */
class MyQueue extends Page
{
    protected string $view = 'filament.pages.my-queue';

    protected static ?string $navigationLabel = 'My queue';

    protected static ?string $title = 'My queue';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = -1;

    /**
     * What has been typed into the forms on this page, as
     * `[case id][step position][question] => answer`.
     *
     * Held on the component rather than posted, because the page shows several steps at
     * once and each carries its own form. Nothing here is trusted: it is checked against
     * the client's own question definitions on the server before it reaches the engine,
     * and the engine refuses an answer to a question the step does not ask no matter
     * which of the three ways in it arrived by.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    public array $answers = [];

    /**
     * The steps waiting on whoever is signed in, worked out fresh on every render.
     *
     * Deliberately not cached on the component. Picking a step up or deciding one changes
     * what the rest of the list should say — a decided step lets the next group through —
     * and a list held in a property would show the answer from before the button was
     * pressed.
     *
     * @return Collection<int, AvailableStep>
     */
    public function queue(): Collection
    {
        return (new AvailableSteps)->waitingOn($this->person());
    }

    /**
     * Which of these steps nobody actually holds, so the card can say so.
     *
     * A step that reached this list because the client named a stand-in looks exactly
     * like a step that is genuinely this person's job, and the difference matters: the
     * finance clearance really is Chandni's, while a Pune clearance only reached her
     * because nobody holds HR head there. Approving the second believing it was the first
     * is the whole failure the warning exists to prevent.
     *
     * Read from the line the resolver already writes onto the case rather than worked out
     * again here — that line is the one a tribunal reads, so the screen and the record
     * cannot disagree about which steps were held by nobody.
     *
     * One query for the whole list.
     *
     * @param  Collection<int, AvailableStep>  $queue
     * @return list<string>
     */
    public function heldByNobody(Collection $queue): array
    {
        return CaseEvent::query()
            ->whereIn('case_id', $queue->map(fn (AvailableStep $waiting) => $waiting->case->getKey())->unique())
            ->where('type', AssigneeResolver::NobodyHoldsItEvent)
            ->get(['case_id', 'payload'])
            ->map(fn (CaseEvent $warning) => $warning->case_id.':'.(((array) $warning->payload)['sequence'] ?? ''))
            ->all();
    }

    /**
     * Which of these only reached this person because the step ran past its deadline.
     *
     * The difference is the whole rule underneath it: an overdue step widens to whoever it
     * escalates to and takes nobody away, so Chandni seeing Anjali's clearance does not
     * mean Rakesh has stopped seeing it, and it does not mean it stopped being his. A card
     * that said nothing would read as "this is now your job", which is exactly the
     * laundering the rule exists to prevent.
     *
     * Worked out by asking the same question twice — once as though the deadline had not
     * passed — and only for steps that are actually late, which is a handful at most.
     *
     * @param  Collection<int, AvailableStep>  $queue
     * @return list<string>
     */
    public function cameByEscalation(Collection $queue): array
    {
        $resolver = new AssigneeResolver;
        $person = (int) $this->person()->getKey();

        return $queue
            ->filter(fn (AvailableStep $waiting) => $waiting->escalationOwed)
            ->reject(fn (AvailableStep $waiting) => $resolver->resolve($waiting->case, $waiting->step)
                ->contains(fn (User $candidate) => (int) $candidate->getKey() === $person))
            ->map(fn (AvailableStep $waiting) => $waiting->case->getKey().':'.$waiting->step->sequence)
            ->values()
            ->all();
    }

    /**
     * Pick a step up, which is what stops two people in a shared queue both working on
     * it. The engine refuses if it is not theirs; the refusal is shown as written.
     */
    public function pickUp(int $caseId, int $sequence): void
    {
        $this->run(
            fn (CaseEngine $engine, $case) => $engine->claim($case, $sequence, $this->person()),
            $caseId,
            'Picked up.',
        );
    }

    /**
     * What one step is asking, in the order the client put the questions in.
     *
     * Read against what has been typed into this card so far, because a question can be
     * hidden by an earlier answer on the same form — finance is not asked what it is
     * recovering until it says the imprest card did not come back. The server decides it,
     * once, and the same decision drops the question from the rules and from what is
     * stored, so a question off the screen can never still be demanded.
     *
     * @return Collection<int, FormField>
     */
    public function questionsOn(AvailableStep $waiting): Collection
    {
        return (new StepForm)->asking($waiting->step, $this->typedInto($waiting));
    }

    /**
     * What has been typed into one card's form, which is nothing until somebody types.
     *
     * @return array<string, mixed>
     */
    private function typedInto(AvailableStep $waiting): array
    {
        return $this->answers[$waiting->case->getKey()][$waiting->step->sequence] ?? [];
    }

    /**
     * What somebody has attached to one question on one card, by the name they gave it.
     *
     * Only ever the name. The file has gone nowhere of ours yet and does not until the
     * step is decided, and the one address that exists for it meanwhile is Livewire's
     * own, which always hands the file over to be saved rather than shown — it is built
     * for previewing an image inside a page, not for opening a clearance scan. Looking at
     * a document before approving it therefore needs an address of our own, and that is a
     * decision rather than a detail: it is a second way to reach a file nothing has
     * checked against a case yet.
     *
     * The card names what is attached either way, which is what tells a chosen file apart
     * from none once the page redraws.
     */
    public function attachedTo(int $caseId, int $sequence, string $key): ?string
    {
        $chosen = $this->answers[$caseId][$sequence][$key] ?? null;

        return $chosen instanceof UploadedFile ? $chosen->getClientOriginalName() : null;
    }

    /**
     * The documents already attached to this case by steps that are done.
     *
     * This is what makes a clearance verifiable rather than taken on trust: finance
     * clearing an exit can open the photograph HR attached to the ID card question,
     * instead of approving on the word that it came back. Whoever holds any step of a
     * case may open its documents — the rule lives with the documents themselves, so this
     * screen and the address that serves them cannot come to disagree.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function documentsOn(AvailableStep $waiting): Collection
    {
        return (new CaseDocuments)->on($waiting->case);
    }

    /**
     * The rows a picker offers, as `id => name`.
     *
     * Only the three picker types have any, and each reads the client's own table. The
     * tenant wall does the scoping, so this is the client's own people, their own
     * departments and their own designations without a word here saying so.
     *
     * @return array<int, string>
     */
    public function optionsFor(FormField $field): array
    {
        return match ($field->type) {
            FormField::UserPicker => User::query()->orderBy('name')->pluck('name', 'id')->all(),
            FormField::OrgUnitPicker => OrgUnit::query()->orderBy('name')->pluck('name', 'id')->all(),
            FormField::DesignationPicker => Designation::query()->where('active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            default => [],
        };
    }

    /**
     * Record a decision on a step, with whatever its form asked for.
     *
     * The answers are checked here against the client's own definitions, which is what
     * puts "Imprest card returned is required" under the right box. That is not the only
     * check and is not the important one — the engine refuses an answer to a question the
     * step does not ask, and it refuses it whether it came from this page, from a link
     * sent to somebody with no account, or from a console command.
     */
    public function decide(int $caseId, int $sequence, string $outcome): void
    {
        // Whether this step is actually waiting on this person is settled before a word
        // of their answers is looked at, for the reason the engine gives where it checks
        // the same thing: somebody with no business at this step has no business being
        // told what it asks either. Read from the same list the page is drawn from, so
        // the screen and the check cannot disagree.
        //
        // Anything else falls straight through to the engine, whose refusal is written to
        // be read by the person it refuses — a colleague already holding the step, a case
        // in another company, a step that is not part of this process.
        $waiting = $this->queue()->first(fn (AvailableStep $step): bool => (int) $step->case->getKey() === $caseId
            && (int) $step->step->sequence === $sequence);

        if ($waiting !== null) {
            $forms = new StepForm;
            $under = "answers.{$caseId}.{$sequence}.";

            // Rewritten under the property path the inputs are bound to, so a refusal
            // lands beside the box it is about instead of at the top of the page.
            $this->validate(
                collect($forms->rules($waiting->step, $this->typedInto($waiting)))->mapWithKeys(fn (mixed $rules, string $key): array => [$under.$key => $rules])->all(),
                [],
                collect($forms->labels($waiting->step))->mapWithKeys(fn (string $label, string $key): array => [$under.$key => $label])->all(),
            );
        }

        $recorded = $this->run(
            fn (CaseEngine $engine, $found) => $engine->decide(
                $found,
                $sequence,
                $outcome,
                $this->person(),
                $this->answers[$caseId][$sequence] ?? [],
            ),
            $caseId,
            'Recorded.',
        );

        // Only once it is actually recorded. A refusal is a sentence asking the person to
        // do something differently — a colleague took the step first, or the outcome is
        // not one this step offers — and clearing the boxes underneath it would make them
        // type the whole clearance again to read the same refusal.
        if ($recorded) {
            unset($this->answers[$caseId][$sequence]);
        }
    }

    /**
     * Every refusal the engine makes is written to be read by the person it refuses, so
     * it is shown as it stands rather than replaced with a screen-level apology. That is
     * also the point of the page: a step that is not yours says so in words.
     *
     * Returns whether the act went through, so that a caller holding what somebody typed
     * knows whether it is safe to clear.
     */
    private function run(callable $act, int $caseId, string $said): bool
    {
        // Scoped to the client company by the wall every query carries, so an id from
        // another company's case simply is not found.
        $case = ProcessCase::query()->whereKey($caseId)->first();

        if ($case === null) {
            Notification::make()->danger()->title('That case is not one of yours.')->send();

            return false;
        }

        try {
            $act(new CaseEngine, $case);

            Notification::make()->success()->title($said)->send();

            return true;
        } catch (ProcessRefused $refused) {
            // Only the engine's own refusals, which are written for the person being
            // refused. Anything else is a fault rather than an answer, and putting its
            // message on the screen would show an employee the inside of a database error
            // — two colleagues claiming one step at the same instant already arrives here
            // as a refusal in words, so nothing a person can cause is lost by narrowing
            // this.
            Notification::make()->danger()->title($refused->getMessage())->send();

            return false;
        }
    }

    private function person(): User
    {
        return auth()->user();
    }
}
