<?php

namespace App\Filament\Pages;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Exceptions\ProcessRefused;
use App\Filament\Pages\Concerns\DrawsAStepsForm;
use App\Filament\Resources\Cases\CaseResource;
use App\Models\FormField;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\CaseEngine;
use App\Process\StepForm;
use BackedEnum;
use Closure;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * The screen a request is started from, and the first thing in this product a person can
 * create.
 *
 * **It asks the process what to put on the page.** The eight questions on Meridian's
 * hiring request are not written here and could not be — the client owns their form, and
 * a screen with their questions typed into it would be wrong the first time they edited
 * one. What is drawn is the first step's own form, through the same partial the queue
 * screen draws a clearance with, so a question type added once serves every screen that
 * asks anything.
 *
 * **Raising is not a new act.** It is opening the case and then answering its first step,
 * which is exactly what the demo builder does in two lines, and it goes through the
 * engine both times. So the client's own rules are applied, the threshold is frozen onto
 * the case as it opens, and the person is refused if the step is not theirs — none of it
 * written again here.
 *
 * **The two are one piece of work.** A case opened and then refused its first answers
 * would leave an empty request in the client's list that nobody raised and nobody can
 * finish, and a second press would leave another. So the two writes share a transaction
 * and a refused request leaves nothing behind.
 *
 * **An exit is raised differently, because nobody may answer a step on their own case.**
 * Deepak resigning could never get past a first step of his own exit, so raising one
 * answers no step: it opens the case with the four things the law and the leaver need —
 * who, their last working day, how their employment is ending, and the personal address
 * everything owed to them goes to once their sign-in has ended. The first step is then
 * somebody else's, usually their manager's. Vertex's exit has always opened this way.
 */
class RaiseARequest extends Page
{
    use DrawsAStepsForm;

    protected string $view = 'filament.pages.raise-a-request';

    protected static string|UnitEnum|null $navigationGroup = 'Your work';

    protected static ?string $navigationLabel = 'Raise a request';

    protected static ?string $title = 'Raise a request';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?int $navigationSort = 0;

    /** Which process is being raised, by the id on the list below. */
    public ?int $processId = null;

    /**
     * What has been typed into the form, as `question => answer`.
     *
     * One form on the page, so there is nothing to key it by. Nothing here is trusted:
     * it is checked against the client's own question definitions on the server, and then
     * again by the engine, which refuses an answer to a question the step does not ask
     * however it arrived.
     *
     * @var array<string, mixed>
     */
    public array $answers = [];

    /** Who is leaving, on a process about an employee, by account id. */
    public ?int $leaverId = null;

    /** Their last working day, as the date box sends it. Both legal clocks count from it. */
    public ?string $lastWorkingDay = null;

    /**
     * How their employment is ending, one of {@see ProcessCase::WaysEmploymentEnds}. Asked
     * only of somebody raising another person's exit: your own is a resignation.
     */
    public ?string $employmentEndedBy = null;

    /** Where everything owed to them goes once their sign-in has ended, to confirm or correct. */
    public ?string $personalEmail = null;

    /** @var array<int, string>|null */
    private ?array $leavers = null;

    /**
     * What has been read once already while this page draws.
     *
     * The page asks the same question four times over as it draws — the list itself, what
     * has been chosen, what that asks, and again when the button is pressed — and each ask
     * is a walk through every live process and whoever holds its first step. Worked out
     * once and kept for as long as this object lives, which is one press.
     *
     * The navigation asks it separately, because whether the link is drawn at all is
     * decided before any page exists. That ask stops at the first process the person can
     * start, so it is a fraction of this one.
     *
     * @var Collection<int, ProcessTemplate>|null
     */
    private ?Collection $raisable = null;

    /**
     * Whoever can raise something can reach this screen, and nobody else can.
     *
     * There is no separate action on anybody's role for raising, and that is the settled
     * decision rather than an omission: a process's first step already says whose job it
     * is, so a thirteenth action beside it would be a second answer to the same question
     * and the two would drift. ServiceNow and Jira Service Management both put it the
     * same way round — the right to raise belongs to the thing being raised. An exit is the
     * one exception, because its first step is never the raiser's: see
     * {@see whoseExitTheyMayRaise()}.
     */
    public static function canAccess(): bool
    {
        $person = auth()->user();

        // Whether there is one, not which ones there are. This is asked on every screen in
        // the signed-in area, because the menu is drawn on every screen, and each process
        // it looks at costs a question to the database — so it stops at the first process
        // this person can start instead of finishing the list nobody is going to read.
        return $person instanceof User
            && static::liveProcesses()->first(static::whoeverCanStartIt($person)) !== null;
    }

    /**
     * The live processes this person may start.
     *
     * A process about nobody, or about somebody who has not joined yet, is theirs to start
     * when its first step names them. A process about an employee is an exit, and is theirs
     * to start when there is anybody whose exit they may raise — see
     * {@see whoseExitTheyMayRaise()}.
     *
     * ponytail: every process about an employee is treated as an exit, because no other kind
     * exists. A transfer or a promotion run as a process would need a way of saying it has no
     * last working day.
     *
     * @return Collection<int, ProcessTemplate>
     */
    public static function whatTheyCanRaise(User $person): Collection
    {
        return static::liveProcesses()->filter(static::whoeverCanStartIt($person))->values();
    }

    /**
     * Everybody whose exit this person may raise, as `id => name`, themselves first.
     *
     * Their own, as long as they have a job here today. Anybody else's only where they may
     * change that person's details, because the form lets them correct the leaver's personal
     * email and anything looser would be a way round that permission. Rakesh, HR head over
     * Shimla, may raise Deepak's and not Rohit's in Pune; Chandni, over the whole company,
     * may raise anybody's. The leaver's own manager raising it, as Keka allows, waits until a
     * client asks.
     *
     * Somebody whose exit is already open is still on the list. The engine refuses the second
     * one in a sentence naming them, which teaches the rule where a missing name would not.
     *
     * @return array<int, string>
     */
    public static function whoseExitTheyMayRaise(User $person): array
    {
        $theirOwn = $person->currentEmployment()->exists()
            ? [(int) $person->getKey() => 'You ('.$person->name.')']
            : [];

        $reachable = app(PermissionResolver::class)->reachableUnitIds($person, Permission::UpdatePerson);

        if ($reachable === []) {
            return $theirOwn;
        }

        $others = User::query()
            ->where('active', true)
            ->whereKeyNot($person->getKey())
            ->whereHas('currentEmployment', fn (Builder $row) => $reachable === null ? $row : $row->whereIn('org_unit_id', $reachable))
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (User $other): array => [(int) $other->getKey() => $other->name])
            ->all();

        return $theirOwn + $others;
    }

    /**
     * Every live process a person could conceivably start, before anybody is asked whether
     * they may start it.
     *
     * @return Collection<int, ProcessTemplate>
     */
    private static function liveProcesses(): Collection
    {
        return ProcessTemplate::query()
            ->where('status', ProcessTemplate::Published)
            ->with('steps')
            ->orderBy('name')
            ->get();
    }

    /**
     * The test one process is put to for one person, shared by both questions above so
     * they cannot answer differently — and closing over a single resolver, which is what
     * lets it recognise the same role twice instead of asking again.
     *
     * An exit asks the cheap half of {@see whoseExitTheyMayRaise()} — a job here, or the
     * permission anywhere — because the menu asks this on every screen and only needs to know
     * whether there is anybody, not who.
     *
     * @return Closure(ProcessTemplate): bool
     */
    private static function whoeverCanStartIt(User $person): Closure
    {
        $assignees = new AssigneeResolver;
        $mayRaiseAnExit = null;

        return function (ProcessTemplate $process) use ($assignees, $person, &$mayRaiseAnExit): bool {
            if ($process->subject_kind === 'employee') {
                return $mayRaiseAnExit ??= $person->currentEmployment()->exists()
                    || app(PermissionResolver::class)->allows($person, Permission::UpdatePerson);
            }

            $first = $process->steps->first();

            if ($first === null) {
                return false;
            }

            // A first step that cannot be approved cannot be raised. Raising is answering
            // that step and approving it, so a step offering only a rejection or a hold
            // would take the whole form and then refuse every press — better never offered
            // than offered and impossible.
            if (! in_array('approved', (array) $first->allowed_outcomes, true)) {
                return false;
            }

            return $assignees->whoCanRaiseIt($first, (string) $process->key)
                ->contains(fn (User $candidate) => $candidate->is($person));
        };
    }

    /**
     * @return Collection<int, ProcessTemplate>
     */
    public function processes(): Collection
    {
        return $this->raisable ??= static::whatTheyCanRaise($this->person());
    }

    /**
     * Changing what is being raised empties the form.
     *
     * The answers belonged to the questions that were on the screen a moment ago, and
     * another process does not ask them — left where they were, they would be sent with
     * the new request and the engine would refuse the lot, naming questions the person
     * can no longer see.
     */
    public function updatedProcessId(): void
    {
        $this->answers = [];
        $this->lastWorkingDay = null;

        // Most exits raised are somebody's own resignation, so that is where the form starts.
        $own = (int) $this->person()->getKey();

        $this->leaverId = $this->isAnExit() && array_key_exists($own, $this->leavers()) ? $own : null;
        $this->updatedLeaverId();
    }

    /**
     * A different leaver brings their own address on file into the box, to be confirmed or
     * corrected, and clears how the last one's employment was ending.
     */
    public function updatedLeaverId(): void
    {
        $this->employmentEndedBy = null;
        $this->personalEmail = $this->leaverId !== null && array_key_exists($this->leaverId, $this->leavers())
            ? User::query()->whereKey($this->leaverId)->value('personal_email')
            : null;
    }

    /** @return array<int, string> */
    public function leavers(): array
    {
        return $this->leavers ??= static::whoseExitTheyMayRaise($this->person());
    }

    public function isAnExit(): bool
    {
        return $this->chosen()?->subject_kind === 'employee';
    }

    /** Raising your own exit is a resignation, so the screen does not ask how it is ending. */
    public function isTheirOwn(): bool
    {
        return $this->leaverId !== null && $this->leaverId === (int) $this->person()->getKey();
    }

    /**
     * The process being raised, read back off the list rather than trusted from the
     * browser — which is what makes the number in the box a choice rather than a way in.
     */
    public function chosen(): ?ProcessTemplate
    {
        return $this->processId === null
            ? null
            : $this->processes()->first(fn (ProcessTemplate $process) => (int) $process->getKey() === $this->processId);
    }

    /**
     * What the chosen process asks, in the client's own order, read against what has been
     * typed so far — because a question can be hidden by an answer above it.
     *
     * @return Collection<int, FormField>
     */
    public function questions(): Collection
    {
        $process = $this->chosen();

        // An exit's first step is somebody else's to answer, so its form is not drawn here.
        return $process === null || $this->isAnExit()
            ? new Collection
            : (new StepForm)->asking($this->firstStepOf($process), $this->answers);
    }

    /**
     * Raise it: open the case and answer its first step, as one piece of work.
     */
    public function raise(): void
    {
        $process = $this->chosen();

        if ($process === null) {
            Notification::make()->danger()->title('Choose what you want to raise.')->send();

            return;
        }

        if ($this->isAnExit()) {
            $this->raiseAnExit($process);

            return;
        }

        $first = $this->firstStepOf($process);

        $this->checkedAgainstTheForm($first, 'answers', true);

        $person = $this->person();

        try {
            DB::transaction(function () use ($process, $first, $person): void {
                $engine = new CaseEngine;

                $engine->decide(
                    $engine->open($process, by: $person),
                    (int) $first->sequence,
                    'approved',
                    $person,
                    $this->answers,
                );
            });
        } catch (ProcessRefused $refused) {
            // The engine's refusals are written to be read by the person being refused, so
            // they are shown as they stand. Anything else is a fault rather than an answer.
            Notification::make()->danger()->title($refused->getMessage())->send();

            return;
        }

        // Only the answers. The process stays chosen, because somebody raising one hiring
        // request usually has a second one to raise and should not have to find it again.
        $this->answers = [];

        Notification::make()->success()->title($process->name.' raised.')->send();
    }

    /**
     * Open an exit, with the leaver's address confirmed in the same write.
     *
     * The address is saved with the case rather than before it, so an exit the engine
     * refuses — a second one for somebody whose exit is open — leaves their address as it
     * was. HR raising somebody else's is taken to the exit's own page, because the two
     * deadlines it now carries are what they need to see next. Deepak raising his own stays
     * here, because that page is not his to read, and is told where everything about it
     * will now be sent.
     */
    private function raiseAnExit(ProcessTemplate $process): void
    {
        $this->personalEmail = trim((string) $this->personalEmail);
        $theirOwn = $this->isTheirOwn();

        $this->validate([
            'leaverId' => ['required', Rule::in(array_keys($this->leavers()))],
            'lastWorkingDay' => ['required', 'date_format:Y-m-d'],
            'employmentEndedBy' => $theirOwn ? ['nullable'] : ['required', Rule::in(array_keys(ProcessCase::WaysEmploymentEnds))],
            'personalEmail' => ['required', 'email', 'max:255'],
        ], [
            'leaverId.required' => 'Choose who is leaving.',
            'leaverId.in' => 'You cannot raise an exit for that person.',
            'lastWorkingDay.required' => 'Give their last working day.',
            'employmentEndedBy.required' => 'Choose how their employment is ending.',
            'personalEmail.required' => 'Give a personal email.',
        ], [
            'leaverId' => 'who is leaving',
            'lastWorkingDay' => 'last working day',
            'employmentEndedBy' => 'how their employment is ending',
            'personalEmail' => 'personal email',
        ]);

        $leaver = User::query()->findOrFail($this->leaverId);

        try {
            $case = DB::transaction(function () use ($process, $leaver, $theirOwn): ProcessCase {
                $leaver->update(['personal_email' => $this->personalEmail]);

                return (new CaseEngine)->open(
                    $process,
                    $leaver,
                    $this->person(),
                    $this->lastWorkingDay,
                    $theirOwn ? 'resignation' : $this->employmentEndedBy,
                );
            });
        } catch (ProcessRefused $refused) {
            Notification::make()->danger()->title($refused->getMessage())->send();

            return;
        }

        if ($theirOwn) {
            Notification::make()->success()->title('Your exit is raised.')
                ->body('Everything about it from now on is sent to '.$this->personalEmail.'.')->send();

            $this->reset('processId', 'leaverId', 'lastWorkingDay', 'employmentEndedBy', 'personalEmail');

            return;
        }

        Notification::make()->success()->title('Exit raised for '.$leaver->name.'.')->send();

        $this->redirect(CaseResource::getUrl('view', ['record' => $case]));
    }

    private function firstStepOf(ProcessTemplate $process): ProcessStep
    {
        // Already loaded beside the process it belongs to, and the list is built from it,
        // so reading it back out of the database would be the same row a second time.
        return $process->steps->first();
    }

    private function person(): User
    {
        return auth()->user();
    }
}
