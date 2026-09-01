<?php

namespace App\Filament\Pages;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\User;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use App\Process\PublishCheck;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Every case this client has run, and what actually happened at each of its steps.
 *
 * The queue screen answers "what is waiting on me", and that is the only question the
 * product could answer until now. It cannot show the failure the publishing check exists
 * to prevent, because that failure is an absence: a step that never opened is missing
 * from a list that only ever holds steps that did. So an exit could reach the end with an
 * approval never given and every screen in the product would look exactly as it does when
 * the approval was given properly.
 *
 * This page is where that is visible. A step nobody ever touched has no row anywhere, so
 * a finished case with a gap in it reads as a gap — the approval that never happened is
 * named, in the position it should have been in, on the case that closed without it.
 *
 * **The red mark has to mean something, so it is spent carefully.** A rejected exit, a
 * withdrawn one and a step that only applies to some exits all leave steps with no row
 * behind them, and not one of them is a failure. Marking those red would spend the mark
 * on ordinary Tuesdays and leave nothing to say with when the real thing happens.
 *
 * **It is also where somebody reads their own request back.** A person who raises one and
 * holds nothing else sees the cases they started and no others, on this same page, because
 * everything they need — every step, who answered it, when, and why — is already drawn
 * here and a second screen would be the same page with one filter changed.
 *
 * Read-only. Deciding anything is the queue screen's job, and module 12's editor is where
 * a process is changed.
 */
class CaseHistory extends Page
{
    protected string $view = 'filament.pages.case-history';

    protected static ?string $navigationLabel = 'Cases';

    protected static ?string $title = 'Cases';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    /**
     * How each outcome reads on the case, in the words of what was actually done rather
     * than the word stored against it. The two hold resolutions are here because a case
     * that ended in an argument has to say so a year later.
     */
    private const Said = [
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'held' => 'Put on hold',
        'sent_back' => 'Sent back',
        'closed_disputed' => 'Closed with the disagreement on the record',
        'force_closed' => 'Closed over the hold',
    ];

    /**
     * Whether each version already read would be refused if it were made live today,
     * remembered for the life of the request. A client's cases run on a handful of
     * versions between them, so this is asked once each however long the list is.
     *
     * @var array<int, bool>
     */
    private array $versionIsFlawed = [];

    /**
     * Whoever holds the action of seeing people anywhere in the company, and anybody who
     * has started a case of their own. The rows are each checked again in turn — the
     * question this one answers is only whether there is any point opening the page.
     *
     * Anjali holds nothing and raises hiring requests, so without the second half of this
     * she can have a request turned down with a reason written on it and no screen in the
     * product that says so.
     */
    public static function canAccess(): bool
    {
        $person = auth()->user();

        if (! $person instanceof User) {
            return false;
        }

        return app(PermissionResolver::class)->allows($person, Permission::ViewPerson)
            || ProcessCase::query()->where('initiated_by', $person->getKey())->exists();
    }

    /**
     * The cases this person may see, newest first.
     *
     * A case is about a person, so seeing one is the same action as seeing them, checked
     * against the department they were in when the case opened — which is the department
     * the case pinned, not the one they are in now. Rakesh clearing HR for Shimla does not
     * get to read a Pune exit, and the same rule the rest of the product uses is what says
     * so rather than a new rule invented here.
     *
     * The pinned row cannot go missing underneath it: a job row a case reads through
     * refuses to be withdrawn, and correcting somebody's history writes a new row instead.
     * So a case with no department at all is one that never had one — about a candidate or
     * about a vacancy — and that falls back to holding the action anywhere, which is the
     * same answer a person with no job row already gets on their own record.
     *
     * **And a case somebody started is theirs to read, whatever else they may do here.**
     * Workday is the shape: a worker's own list holds every process they submitted or took
     * part in, and opening one shows the same history an approver reads. Jira Service
     * Management and ServiceNow build a second screen for this instead, because their
     * approver's view carries private notes and service clocks a customer may not see —
     * neither of which exists on this page, and Anjali works for the same company.
     *
     * @return Collection<int, ProcessCase>
     */
    public function cases(): Collection
    {
        $resolver = app(PermissionResolver::class);
        $person = auth()->user();

        if (! $person instanceof User) {
            return collect();
        }

        // The client's own departments, in one read, because the check wants the unit
        // itself rather than its id. A company has a handful of them.
        $units = OrgUnit::query()->get()->keyBy('id');

        return ProcessCase::query()
            ->with(['subject', 'template.steps', 'liveSteps.assignee', 'subjectEmploymentRecord', 'events.actor'])
            ->orderByDesc('opened_at')
            ->get()
            ->filter(fn (ProcessCase $case): bool => self::theyStartedIt($case, $person)
                || $resolver->allows(
                    $person,
                    Permission::ViewPerson,
                    $units[$case->subjectEmploymentRecord?->org_unit_id] ?? null,
                ))
            ->values();
    }

    /**
     * Whether this is a case the person reading started themselves.
     *
     * Read off the case rather than asked of the permission rules, because it is not a
     * permission: nobody grants it, nobody can take it away, and it is true of the person
     * whether or not their role lets them see anybody in the company.
     */
    private static function theyStartedIt(ProcessCase $case, User $person): bool
    {
        return $case->initiated_by !== null
            && (int) $case->initiated_by === (int) $person->getKey();
    }

    /**
     * The steps that are somebody's turn right now, as case number to step numbers.
     *
     * Asked of the one class that answers it for the queue and the reminders as well,
     * rather than worked out a second time here. A step is open once everything in front
     * of it has closed or was skipped for not applying, and a screen that decided that
     * for itself is a screen that comes to disagree with the queue about the same case.
     *
     * @var array<int, list<int>>
     */
    private array $openNow = [];

    /** @return list<int> */
    private function openRightNow(ProcessCase $case): array
    {
        return $this->openNow[(int) $case->getKey()] ??= (new AvailableSteps)
            ->for($case)
            ->map(fn (AvailableStep $waiting): int => (int) $waiting->step->sequence)
            ->all();
    }

    /**
     * Every step of the version this case opened on, and what happened at it.
     *
     * The steps come from the frozen version rather than from what was done, which is the
     * whole point: a step nobody ever touched has no row anywhere, and listing only the
     * rows would hide exactly the failure this page exists to show.
     *
     * A step can come round more than once — sent back and answered again, or held and
     * then cleared — and the row the engine keeps is one per step, so the line above can
     * only ever say what happened last. What came before it is under `earlier`, read from
     * the case's own trail, which is where every pass survives.
     *
     * @return list<array{sequence: int, name: string, said: string, tone: string, earlier: list<string>}>
     */
    public function whatHappenedOn(ProcessCase $case): array
    {
        $done = $case->liveSteps->keyBy('sequence');
        $running = $case->state === ProcessCase::Open;

        // Which of its steps are somebody's turn right now. A step is not waiting merely
        // because somebody has started it — an approval nobody has opened yet is still
        // waiting — so this is asked of the engine rather than read off the rows, which
        // is what the rows cannot tell us. A case that has ended has none.
        $open = $this->openRightNow($case);

        // The furthest group a case that has ended actually got to. Rows are the right
        // evidence there and the only evidence: nothing is anybody's turn any more, and
        // this is what separates a step the ending cut short from one the process
        // deliberately skipped.
        $reached = (int) $case->template->steps
            ->filter(fn (ProcessStep $step): bool => $done->has($step->sequence))
            ->max('group_no');

        // Whether the case stopped early. A rejection ends the exit at the step that
        // rejected it and a withdrawal ends it wherever it had got to, so nothing from
        // there on was ever due — saying it never happened would blame the process for
        // doing exactly what it was told.
        $stopped = $case->state === ProcessCase::Cancelled
            || $done->contains(fn (CaseStep $row): bool => $row->outcome === 'rejected');

        return $case->template->steps
            ->map(function (ProcessStep $step) use ($case, $done, $open, $reached, $running, $stopped): array {
                $row = $done[$step->sequence] ?? null;

                if ($row !== null) {
                    // A step that sent the case back is the one row that stops describing
                    // where the case is. Once the correction has come forward past it, it
                    // is somebody's turn again — and a line still reading "sent back" says
                    // the request is with the person who raised it when it is not.
                    $backWithThem = $row->outcome === 'sent_back'
                        && in_array((int) $step->sequence, $open, true);

                    return [
                        'sequence' => $step->sequence,
                        'name' => $step->name,
                        'said' => $this->whatWasDone($case, $row)
                            .($backWithThem ? ' Waiting on somebody to answer it again.' : ''),
                        'tone' => $row->acted_at === null || $backWithThem ? 'waiting' : 'done',
                        'earlier' => $this->earlierPassesAt($case, $step, $row->acted_at !== null),
                    ];
                }

                return [
                    'sequence' => $step->sequence,
                    'name' => $step->name,
                    ...$this->whyThereIsNoRow($case, $step, $open, $reached, $running, $stopped),
                    'earlier' => $this->earlierPassesAt($case, $step, false),
                ];
            })
            ->all();
    }

    /**
     * Why a step has no row at all, which is the only place on this page a failure can
     * be read — and the only place it can be cried wrongly.
     *
     * Four different things leave a step with no row and they have to be told apart. It
     * has not come round yet. Somebody else in its own group is holding it up. The exit
     * was rejected or withdrawn before it was ever due. Or its own condition said no —
     * and that last one is the failure, but only sometimes, because a step that opens on
     * some exits and not others is very often exactly what the client meant.
     *
     * @return array{said: string, tone: string}
     */
    private function whyThereIsNoRow(
        ProcessCase $case,
        ProcessStep $step,
        array $open,
        int $reached,
        bool $running,
        bool $stopped,
    ): array {
        if ($stopped && $step->group_no >= $reached) {
            return [
                'said' => 'The case ended before this came round.',
                'tone' => 'stopped',
            ];
        }

        // Somebody's turn this minute, and nobody has opened it yet. Said before anything
        // else, because a step waiting on a person is the one thing on this page somebody
        // can act on — and calling it "not yet" is what made a live approval read as a
        // case that had stalled.
        if ($running && in_array((int) $step->sequence, $open, true)) {
            return [
                'said' => 'Waiting on somebody to answer it.',
                'tone' => 'later',
            ];
        }

        if ($running) {
            return [
                'said' => 'Not yet. It opens when the steps in front of it are done.',
                'tone' => 'later',
            ];
        }

        // Its condition said no, so the engine never wanted it. Whether that is what the
        // client meant is not something one case can answer — the version it ran on is
        // what knows, and the check that runs when a process goes live is what reads it.
        return $this->versionIsFlawed($case->template)
            ? [
                'said' => 'Never happened. Nobody was ever asked, and the case carried on without it.',
                'tone' => 'missed',
            ]
            : [
                'said' => 'Not needed this time. It only opens in some cases, and this was not one.',
                'tone' => 'skipped',
            ];
    }

    /**
     * Whether the version this case runs on would be refused if it were made live today.
     *
     * This is what separates a step the client meant to skip from one nobody could ever
     * have been asked. A skipped step looks identical either way from the case: the
     * condition was not met, so the engine did not want the step. The difference is
     * whether that condition could ever have been met at all, and the check that runs
     * when a process goes live is the one thing that knows — so it is asked, rather than
     * a second copy of its rules being written here to drift away from it.
     *
     * A live version that fails it is a version somebody published before the check
     * existed, or around it, which is the honest history of every client who was running
     * before this landed.
     */
    private function versionIsFlawed(ProcessTemplate $version): bool
    {
        return $this->versionIsFlawed[$version->getKey()]
            ??= (new PublishCheck($version))->problems() !== [];
    }

    /** Who did what, and when, in the words somebody reading the case a year later needs. */
    private function whatWasDone(ProcessCase $case, CaseStep $row): string
    {
        $who = $row->assignee?->name
            ?? ((array) $row->external_assignee)['name']
            ?? 'somebody';

        if ($row->acted_at === null) {
            return "Picked up by {$who}, not yet answered.";
        }

        $said = self::Said[$row->outcome] ?? $row->outcome;

        $acted = $case->events
            ->where('type', 'step_acted')
            ->last(fn (CaseEvent $event): bool => $this->isAbout($event, $row->sequence));

        return "{$said} by {$who} on ".$row->acted_at->format('j F Y').'.'
            .($acted === null ? '' : $this->andWhy($case, (array) $acted->payload));
    }

    /**
     * Every pass at a step the line above does not already describe, oldest first.
     *
     * Read from the trail rather than from the replaced rows, and that is the whole
     * design: a replaced row cannot be paired back to its own line in the trail by any
     * honest rule, because a step held and then cleared is one row with two lines against
     * it. Every line already carries who, when, the outcome, the reason and where a
     * send-back went, so nothing new is stored and nothing extra is read — the trail is
     * already loaded for this page.
     *
     * Jira Service Management is the shape: a step that comes round again is a new round,
     * the panel says where it is now, and the earlier rounds stay readable underneath.
     * Salesforce draws the trail flat instead and sells a component to make it legible
     * again, which is what that costs.
     *
     * @return list<string>
     */
    private function earlierPassesAt(ProcessCase $case, ProcessStep $step, bool $theLineAboveTookTheLast): array
    {
        $passes = $case->events
            ->whereIn('type', ['step_acted', 'step_reopened'])
            ->filter(fn (CaseEvent $event): bool => $this->isAbout($event, $step->sequence))
            ->values();

        if ($theLineAboveTookTheLast) {
            $passes = $passes->slice(0, -1);
        }

        return $passes
            ->map(fn (CaseEvent $event): string => $this->whatTheTrailSays($case, $event))
            ->all();
    }

    /** Whether a line in the trail is about one step of the process. */
    private function isAbout(CaseEvent $event, int $sequence): bool
    {
        return (int) (((array) $event->payload)['sequence'] ?? 0) === $sequence;
    }

    /**
     * One earlier pass, in the same words the line above the step uses.
     *
     * A step being sent back to is written into the trail as its own line, so it is said
     * here too — without it a step approved twice reads as approved twice for no reason,
     * and the explanation sits several steps further down the page.
     */
    private function whatTheTrailSays(ProcessCase $case, CaseEvent $event): string
    {
        $said = (array) $event->payload;

        $who = $event->actor?->name
            ?? ((array) ($said['answered_by'] ?? []))['name']
            ?? 'somebody';

        $what = $event->type === 'step_reopened'
            ? 'Sent back to here'
            : (self::Said[$said['outcome'] ?? ''] ?? 'Answered');

        return "{$what} by {$who} on ".$event->created_at->format('j F Y').'.'.$this->andWhy($case, $said);
    }

    /**
     * Why somebody held a step or sent the case back, and where they sent it.
     *
     * Without this the page says "Put on hold" and stops, which reads as a case that
     * stalled for no reason — and the reason is the whole message. ServiceNow shows its
     * own hold reason on the ticket beside the state for the same reason, and a request
     * that came back with no words on it tells the person who has to correct it nothing.
     *
     * Read from the line the engine already writes rather than from a second column, so
     * what the screen says and what the history holds cannot come apart.
     *
     * @param  array<string, mixed>  $said  One line of the trail, as the engine wrote it.
     */
    private function andWhy(ProcessCase $case, array $said): string
    {
        $back = $said['sent_back_to'] ?? null;
        $step = $back === null ? null : $case->template->steps->firstWhere('sequence', $back)?->name;

        return ($step === null ? '' : " Back to {$step}.")
            .(trim((string) ($said['reason'] ?? '')) === '' ? '' : ' Why: '.$said['reason']);
    }

    /** What the case itself is, in one word. */
    public function stateOf(ProcessCase $case): string
    {
        return match ($case->state) {
            ProcessCase::Closed => 'Finished',
            ProcessCase::Cancelled => 'Cancelled',
            default => 'Running',
        };
    }

    /** Whose case it is, or that it is about nobody — a hiring request is about a vacancy. */
    public function whoseCase(ProcessCase $case): string
    {
        $subject = $case->subject;

        return $subject instanceof User
            ? $subject->name."'s ".$case->template->name
            : $case->template->name;
    }
}
