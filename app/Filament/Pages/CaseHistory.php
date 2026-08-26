<?php

namespace App\Filament\Pages;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\CaseStep;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\User;
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
     * Whoever holds the action of seeing people, anywhere in the company at all. The rows
     * are each checked again in turn against the department the case's own person was in
     * — the question this one answers is only whether there is any point opening the page.
     */
    public static function canAccess(): bool
    {
        $person = auth()->user();

        return $person instanceof User
            && app(PermissionResolver::class)->allows($person, Permission::ViewPerson);
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
            ->with(['subject', 'template.steps', 'liveSteps.assignee', 'subjectEmploymentRecord'])
            ->orderByDesc('opened_at')
            ->get()
            ->filter(fn (ProcessCase $case): bool => $resolver->allows(
                $person,
                Permission::ViewPerson,
                $units[$case->subjectEmploymentRecord?->org_unit_id] ?? null,
            ))
            ->values();
    }

    /**
     * Every step of the version this case opened on, and what happened at it.
     *
     * The steps come from the frozen version rather than from what was done, which is the
     * whole point: a step nobody ever touched has no row anywhere, and listing only the
     * rows would hide exactly the failure this page exists to show.
     *
     * @return list<array{sequence: int, name: string, said: string, tone: string}>
     */
    public function whatHappenedOn(ProcessCase $case): array
    {
        $done = $case->liveSteps->keyBy('sequence');
        $running = $case->state === ProcessCase::Open;

        // The furthest group this case actually got to. Groups rather than step numbers:
        // every step in a group opens at once, so a step whose neighbour has been picked
        // up is waiting its turn rather than missed.
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
            ->map(function (ProcessStep $step) use ($case, $done, $reached, $running, $stopped): array {
                $row = $done[$step->sequence] ?? null;

                if ($row !== null) {
                    return [
                        'sequence' => $step->sequence,
                        'name' => $step->name,
                        'said' => $this->whatWasDone($row),
                        'tone' => $row->acted_at === null ? 'waiting' : 'done',
                    ];
                }

                return [
                    'sequence' => $step->sequence,
                    'name' => $step->name,
                    ...$this->whyThereIsNoRow($case, $step, $reached, $running, $stopped),
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
        int $reached,
        bool $running,
        bool $stopped,
    ): array {
        if ($stopped && $step->group_no >= $reached) {
            return [
                'said' => 'The exit ended before this came round.',
                'tone' => 'stopped',
            ];
        }

        if ($running && $step->group_no > $reached) {
            return [
                'said' => 'Not yet. It opens when the steps in front of it are done.',
                'tone' => 'later',
            ];
        }

        if ($running) {
            return [
                'said' => 'Waiting on somebody to answer it.',
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
                'said' => 'Not needed on this exit. It only opens in some cases, and this was not one.',
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
    private function whatWasDone(CaseStep $row): string
    {
        $who = $row->assignee?->name
            ?? ((array) $row->external_assignee)['name']
            ?? 'somebody';

        if ($row->acted_at === null) {
            return "Picked up by {$who}, not yet answered.";
        }

        $said = self::Said[$row->outcome] ?? $row->outcome;

        return "{$said} by {$who} on ".$row->acted_at->format('j F Y').'.';
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
