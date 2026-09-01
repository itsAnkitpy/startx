<?php

namespace App\Filament\Pages;

use App\Exceptions\ProcessRefused;
use App\Filament\Pages\Concerns\DrawsAStepsForm;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\FormField;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use UnitEnum;

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
    use DrawsAStepsForm;

    protected string $view = 'filament.pages.my-queue';

    protected static string|UnitEnum|null $navigationGroup = 'Your work';

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
     * Why somebody is holding a step, sending it back, or rejecting it, as
     * `[case id][step position] => reason`.
     *
     * A hold and a send-back are refused without one, by the engine and again here so the
     * refusal lands under the box. A rejection keeps the same box and does not demand it —
     * ServiceNow makes its rejection comment compulsory, but that is a client's setting
     * there and would be our rule here.
     *
     * @var array<int, array<int, string>>
     */
    public array $reasons = [];

    /**
     * Which step a send-back goes back to, as `[case id][step position] => step position`.
     *
     * Filled in the moment somebody presses *Send it back*, with the nearest step it may
     * go to, so the ordinary case is a person typing a reason and pressing the button. The
     * list is only drawn when there is genuinely more than one answer.
     *
     * @var array<int, array<int, int>>
     */
    public array $sendBackTo = [];

    /**
     * The one card asking for a reason right now, as `case id:step position:outcome`.
     *
     * Null while nothing is being asked. Only one at a time: the page shows several steps
     * at once and a reason typed into the wrong card is worse than no reason at all.
     */
    public ?string $asking = null;

    /**
     * What each button says. The stored word is not what anybody presses — "Sent back" on
     * a button reads as something that already happened.
     */
    private const Buttons = [
        'approved' => 'Approve',
        'rejected' => 'Reject',
        'held' => 'Put it on hold',
        'sent_back' => 'Send it back',
    ];

    /** What the reason box asks for, in the words of the outcome it belongs to. */
    private const Asks = [
        'rejected' => 'Why is this being rejected?',
        'held' => 'Why is this being held?',
        'sent_back' => 'What has to be corrected?',
    ];

    /**
     * The answers a step already carries, put back in the boxes.
     *
     * Two steps arrive with answers on them and both are new here. A held step keeps what
     * was typed before it was held, and a step a send-back reopened keeps what the person
     * put in it the first time — which is the whole point of sending it back rather than
     * rejecting it: Anjali corrects the salary on her request instead of raising it again
     * from nothing.
     *
     * Read from the newest row for each step, so a reopened step finds the row the
     * send-back replaced. Documents are left out: what is stored for one is a note of
     * where the file went, and handing that back to a file box would have the step refused
     * for holding something that is not an upload.
     */
    public function mount(): void
    {
        $queue = $this->queue();

        if ($queue->isEmpty()) {
            return;
        }

        $rows = CaseStep::query()
            ->whereIn('case_id', $queue->map(fn (AvailableStep $waiting) => $waiting->case->getKey())->unique())
            ->orderByDesc('id')
            ->get(['id', 'case_id', 'sequence', 'payload']);

        foreach ($queue as $waiting) {
            $given = (array) $rows->first(fn (CaseStep $row): bool => (int) $row->case_id === (int) $waiting->case->getKey()
                && (int) $row->sequence === (int) $waiting->step->sequence)?->payload;

            if ($given === []) {
                continue;
            }

            $this->answers[$waiting->case->getKey()][$waiting->step->sequence] = array_diff_key(
                $given,
                array_flip((new StepForm)->fields($waiting->step)
                    ->where('type', FormField::File)
                    ->pluck('key')
                    ->all()),
            );
        }
    }

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
        $queue = (new AvailableSteps)->waitingOn($this->person());

        // The panel above each form reads the person as the case froze them, so the
        // person, their department and their manager are read once for the whole list
        // rather than once per card. The pinned job row and its office are already
        // loaded by the list itself, so this is two reads however many cards there are.
        (new EloquentCollection($queue->pluck('case')->all()))->loadMissing([
            'subject',
            'subjectEmploymentRecord.orgUnit',
            'subjectEmploymentRecord.reportsTo',
        ]);

        return $queue;
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
     * What somebody has already said about a step in this list — why it came back, or why
     * it is on hold.
     *
     * Both are a reason an approver was made to type, and without this neither reaches the
     * person the words are for. Anjali reopens her request and what is wrong with it is on
     * the case's history and beside no form; Chandni's own hold reads as an ordinary
     * clearance she has not got round to. One is a message to somebody else and one is a
     * note to yourself, and both belong on the card.
     *
     * Read from the line the engine writes rather than worked out again, so a card and the
     * case's history cannot say different things. One query for the whole list, and the
     * last thing said about a step is the one that stands.
     *
     * @param  Collection<int, AvailableStep>  $queue
     * @return array<string, string>
     */
    public function whatWasSaidAbout(Collection $queue): array
    {
        $said = [];

        $events = CaseEvent::query()
            ->whereIn('case_id', $queue->map(fn (AvailableStep $waiting) => $waiting->case->getKey())->unique())
            ->where('type', 'step_acted')
            ->with('actor')
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            $payload = (array) $event->payload;
            $who = $event->actor?->name ?? 'Somebody';
            $why = trim((string) ($payload['reason'] ?? ''));
            $back = $payload['sent_back_to'] ?? null;

            if ($back !== null) {
                $said[$event->case_id.':'.$back] = "{$who} sent this back: {$why}";

                continue;
            }

            if (($payload['outcome'] ?? null) === 'held') {
                $said[$event->case_id.':'.($payload['sequence'] ?? '')] = "{$who} put this on hold: {$why}";
            }
        }

        return $said;
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
     * Record a decision on a step, with whatever its form asked for.
     *
     * The answers are checked here against the client's own definitions, which is what
     * puts "Imprest card returned is required" under the right box. That is not the only
     * check and is not the important one — the engine applies the same rules whether the
     * answers came from this page, from a link sent to somebody with no account, or from
     * a console command, and refuses them in one sentence rather than beside a box.
     *
     * **The two have to agree about required questions, which is why the outcome is
     * handed to both.** A rejection and a send-back are not the step's answers being
     * filed, so neither demands a full form; a screen that demanded one anyway would
     * refuse what the engine allows, and Chandni could not reject the clearance whose
     * empty box is her reason for rejecting it.
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
        $waiting = $this->waitingAt($caseId, $sequence);

        if ($waiting !== null) {
            $this->checkedAgainstTheForm(
                $waiting->step,
                "answers.{$caseId}.{$sequence}",
                $outcome === 'approved',
            );
        }

        // The engine refuses these two without a reason as well, and its refusal is the
        // one that counts. This is here so the words land under the box somebody has to
        // type them into rather than as a sentence across the top of the page.
        if (in_array($outcome, ['held', 'sent_back'], true)) {
            $this->validate(
                ["reasons.{$caseId}.{$sequence}" => ['required', 'string', 'max:'.StepForm::DefaultParagraphLength]],
                [],
                ["reasons.{$caseId}.{$sequence}" => self::Asks[$outcome]],
            );
        }

        $recorded = $this->run(
            fn (CaseEngine $engine, $found) => $engine->decide(
                $found,
                $sequence,
                $outcome,
                $this->person(),
                $this->answers[$caseId][$sequence] ?? [],
                $this->reasons[$caseId][$sequence] ?? null,
                $this->sendBackTo[$caseId][$sequence] ?? null,
            ),
            $caseId,
            'Recorded.',
        );

        // Only once it is actually recorded. A refusal is a sentence asking the person to
        // do something differently — a colleague took the step first, or the outcome is
        // not one this step offers — and clearing the boxes underneath it would make them
        // type the whole clearance again to read the same refusal.
        if ($recorded) {
            unset($this->answers[$caseId][$sequence], $this->reasons[$caseId][$sequence], $this->sendBackTo[$caseId][$sequence]);
            $this->asking = null;
        }
    }

    /**
     * Ask for a reason before an outcome that needs one, instead of acting on the press.
     *
     * A hold and a send-back are refused without a reason, and a rejection is worth
     * recording one for, so all three open the box rather than deciding the step. This is
     * the shape Jira Service Management uses — a screen between the button and the
     * decision — and it is what keeps an approval a single press, which is the outcome
     * most people are pressing most of the time.
     */
    public function askFor(int $caseId, int $sequence, string $outcome): void
    {
        $this->asking = "{$caseId}:{$sequence}:{$outcome}";

        if ($outcome !== 'sent_back') {
            return;
        }

        // Filled with the step the case started at, so the ordinary send-back is a reason
        // and a button. That is where Workday's own Send Back goes — to whoever raised it
        // — and on a request being corrected it is nearly always the answer. Where there
        // is genuinely a choice the card draws the list, opened on this.
        $waiting = $this->waitingAt($caseId, $sequence);
        $goesBackTo = $waiting === null ? null : $this->whereItCanGoBackTo($waiting)->first();

        if ($goesBackTo !== null) {
            $this->sendBackTo[$caseId][$sequence] = (int) $goesBackTo->sequence;
        }
    }

    /** Put the reason box away without deciding anything. */
    public function stopAsking(): void
    {
        $this->asking = null;
    }

    /**
     * The earlier steps this one may be sent back to, asked of the engine rather than
     * worked out here — a screen that decided it for itself would come to offer a step the
     * engine then refuses.
     *
     * @return Collection<int, ProcessStep>
     */
    public function whereItCanGoBackTo(AvailableStep $waiting): Collection
    {
        return (new CaseEngine)->whereItCanGoBackTo($waiting);
    }

    /** What a button for one outcome says. */
    public function buttonFor(string $outcome): string
    {
        return self::Buttons[$outcome] ?? ucfirst(str_replace('_', ' ', $outcome));
    }

    /** What the reason box asks, for the outcome being asked about. */
    public function asksFor(string $outcome): string
    {
        return self::Asks[$outcome] ?? 'Why?';
    }

    /** One step of the list this page draws, by the case and position a button names. */
    private function waitingAt(int $caseId, int $sequence): ?AvailableStep
    {
        return $this->queue()->first(fn (AvailableStep $step): bool => (int) $step->case->getKey() === $caseId
            && (int) $step->step->sequence === $sequence);
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
