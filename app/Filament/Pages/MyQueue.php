<?php

namespace App\Filament\Pages;

use App\Models\CaseEvent;
use App\Models\ProcessCase;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Throwable;

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

    /** Record a decision on a step. */
    public function decide(int $caseId, int $sequence, string $outcome): void
    {
        $this->run(
            fn (CaseEngine $engine, $case) => $engine->decide($case, $sequence, $outcome, $this->person()),
            $caseId,
            'Recorded.',
        );
    }

    /**
     * Every refusal the engine makes is written to be read by the person it refuses, so
     * it is shown as it stands rather than replaced with a screen-level apology. That is
     * also the point of the page: a step that is not yours says so in words.
     */
    private function run(callable $act, int $caseId, string $said): void
    {
        // Scoped to the client company by the wall every query carries, so an id from
        // another company's case simply is not found.
        $case = ProcessCase::query()->whereKey($caseId)->first();

        if ($case === null) {
            Notification::make()->danger()->title('That case is not one of yours.')->send();

            return;
        }

        try {
            $act(new CaseEngine, $case);

            Notification::make()->success()->title($said)->send();
        } catch (Throwable $refused) {
            Notification::make()->danger()->title($refused->getMessage())->send();
        }
    }

    private function person(): User
    {
        return auth()->user();
    }
}
