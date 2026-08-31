<?php

namespace App\Filament\Pages;

use App\Exceptions\ProcessRefused;
use App\Filament\Pages\Concerns\DrawsAStepsForm;
use App\Models\FormField;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\CaseEngine;
use App\Process\StepForm;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 */
class RaiseARequest extends Page
{
    use DrawsAStepsForm;

    protected string $view = 'filament.pages.raise-a-request';

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

    /**
     * What has been read once already while this page draws.
     *
     * The page asks the same question four times over as it draws — the list itself, what
     * has been chosen, what that asks, and again when the button is pressed — and each ask
     * is a walk through every live process and whoever holds its first step. Worked out
     * once and kept for as long as this object lives, which is one press.
     *
     * The navigation asks it separately, because whether the link is drawn at all is
     * decided before any page exists. That ask is not saved here and is the reason the
     * plan carries a note about a client with many live processes.
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
     * same way round — the right to raise belongs to the thing being raised.
     */
    public static function canAccess(): bool
    {
        $person = auth()->user();

        return $person instanceof User && static::whatTheyCanRaise($person)->isNotEmpty();
    }

    /**
     * The live processes this person may start.
     *
     * **Nothing about an employee.** A process about one needs to be told which employee,
     * and an exit needs the leaver's last working day as well because two legal clocks are
     * counted from it — that is a screen of its own and it belongs with the module that
     * owns exits. What is left is everything a person can ask for without naming a
     * colleague: a request about nobody, and a process about somebody who has not joined
     * yet, whose details the form itself collects.
     *
     * @return Collection<int, ProcessTemplate>
     */
    public static function whatTheyCanRaise(User $person): Collection
    {
        $assignees = new AssigneeResolver;

        return ProcessTemplate::query()
            ->where('status', ProcessTemplate::Published)
            ->where('subject_kind', '!=', 'employee')
            ->with('steps')
            ->orderBy('name')
            ->get()
            ->filter(function (ProcessTemplate $process) use ($assignees, $person): bool {
                $first = $process->steps->first();

                if ($first === null) {
                    return false;
                }

                // A first step that cannot be approved cannot be raised. Raising is
                // answering that step and approving it, so a step offering only a
                // rejection or a hold would take the whole form and then refuse every
                // press — better never offered than offered and impossible.
                if (! in_array('approved', (array) $first->allowed_outcomes, true)) {
                    return false;
                }

                return $assignees->whoCanRaiseIt($first, (string) $process->key)
                    ->contains(fn (User $candidate) => $candidate->is($person));
            })
            ->values();
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

        return $process === null
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
