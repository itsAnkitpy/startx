<?php

namespace App\Process;

use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Succession;
use App\Models\User;

/**
 * What actually happened on a case, in the words somebody reading it a year later needs.
 *
 * The queue screen answers "what is waiting on me", and that was the only question the
 * product could answer until this existed. It cannot show the failure the publishing
 * check exists to prevent, because that failure is an absence: a step that never opened
 * is missing from a list that only ever holds steps that did. So an exit could reach the
 * end with an approval never given and every screen would look exactly as it does when
 * the approval was given properly.
 *
 * This is where that is visible. The steps are read from the frozen version the case
 * opened on rather than from what was done, so a step nobody ever touched still has a
 * line — named, in the position it should have been in, on the case that closed without
 * it.
 *
 * **The red mark has to mean something, so it is spent carefully.** A rejected exit, a
 * withdrawn one and a step that only applies to some exits all leave steps with no row
 * behind them, and not one of them is a failure. Marking those red would spend the mark
 * on ordinary Tuesdays and leave nothing to say with when the real thing happens.
 *
 * Read-only, and asked by both halves of the Cases screen — the list, which needs a
 * heading and a state per row, and the case's own page, which needs the whole story. It
 * sits here beside the engine rather than on either of them because a screen working any
 * of this out for itself is a screen that comes to disagree with the queue about the same
 * case.
 */
class CaseHistory
{
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
     * How much of an answer fits in a heading before it stops being one. A client's own
     * form decides what identifies a request, and a client is free to open theirs with a
     * paragraph — half a paragraph is not a heading, so a long answer is passed over
     * rather than cut short.
     */
    private const HeadingRoom = 40;

    /**
     * Whether each version already read would be refused if it were made live today,
     * remembered for the life of the request. A client's cases run on a handful of
     * versions between them, so this is asked once each however long the list is.
     *
     * @var array<int, bool>
     */
    private array $versionIsFlawed = [];

    /**
     * The steps that are somebody's turn right now, as case number to step numbers.
     *
     * @var array<int, list<int>>
     */
    private array $openNow = [];

    /**
     * The handover settled inside each case already read, because the case's own page asks
     * for it five times in one draw: once to decide whether the section is there at all,
     * and once for each figure in it.
     *
     * @var array<int, array{to: string, on: string, moved: array{approvals: int, roles: int, reporting_lines: int}}|null>
     */
    private array $handovers = [];

    /**
     * Every step of the version this case opened on, and what happened at it.
     *
     * A step can come round more than once — sent back and answered again, or held and
     * then cleared — and the row the engine keeps is one per step, so the line above can
     * only ever say what happened last. What came before it is under `earlier`, read from
     * the case's own trail, which is where every pass survives.
     *
     * @return list<array{sequence: int, name: string, said: string, tone: string, earlier: list<string>}>
     */
    public function stepByStep(ProcessCase $case): array
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
     * How many of this case's steps nobody was ever asked for.
     *
     * The one mark the list carries, because it is the whole reason the screen exists and
     * a client should not have to open two hundred cases to find the one that finished
     * without an approval.
     *
     * A case still running has none by definition — a step nobody has reached yet has not
     * been missed — and answering that before anything else is what keeps this affordable
     * on a page of rows: only a case that has ended reads its own steps at all, and it
     * reads them from what the list has already loaded.
     */
    public function stepsNobodyWasAskedFor(ProcessCase $case): int
    {
        if ($case->state === ProcessCase::Open) {
            return 0;
        }

        return count(array_filter(
            $this->stepByStep($case),
            fn (array $step): bool => $step['tone'] === 'missed',
        ));
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

    /**
     * Whose case it is, or — where it is about nobody — what it asked for.
     *
     * A hiring request is about a vacancy, so there is no name to head it with and every
     * one of them read "Hiring request". On the screen of somebody who sees the whole
     * company that was eight identical headings with nothing to tell them apart.
     *
     * What it says instead is the first two things the client's own form asks, in the
     * client's own order, because whoever wrote the form put the question that identifies
     * a request at the top of it. Nothing here names a process or a question, which is
     * what keeps it true of a budget request or anything else a client builds about
     * nobody.
     *
     * The case's number is not in here any more: the list puts it in its own column and
     * the case's own page puts it in the title, so repeating it inside the heading printed
     * it twice on the same line.
     */
    public function whatItIsAbout(ProcessCase $case): string
    {
        $subject = $case->subject;

        if ($subject instanceof User) {
            return $subject->name."'s ".$case->template->name;
        }

        $asked = collect((new SubjectPanel)->of($case)['facts'])
            ->filter(fn (string $answer): bool => mb_strlen($answer) <= self::HeadingRoom)
            ->take(2);

        return $asked->isEmpty() ? $case->template->name : $asked->implode(' · ');
    }

    /**
     * The handover settled inside this case, if one was — what moved, to whom, and when.
     *
     * Only ever on an exit, because a handover is settled inside the case about the person
     * leaving. Read off the case's own trail rather than from the handover record, so the
     * page says what was done on the day rather than what is true now.
     *
     * @return array{to: string, on: string, moved: array{approvals: int, roles: int, reporting_lines: int}}|null
     */
    public function handoverSettled(ProcessCase $case): ?array
    {
        $key = (int) $case->getKey();

        if (array_key_exists($key, $this->handovers)) {
            return $this->handovers[$key];
        }

        $settled = $case->events->last(
            fn (CaseEvent $event): bool => $event->type === Succession::HandoverSettledEvent
        );

        if ($settled === null) {
            return $this->handovers[$key] = null;
        }

        $said = (array) $settled->payload;

        return $this->handovers[$key] = [
            'to' => (string) (((array) ($said['to'] ?? []))['name'] ?? 'somebody'),
            'on' => (string) ($said['effective_at'] ?? ''),
            'moved' => [
                'approvals' => (int) (((array) ($said['moved'] ?? []))['approvals'] ?? 0),
                'roles' => (int) (((array) ($said['moved'] ?? []))['roles'] ?? 0),
                'reporting_lines' => (int) (((array) ($said['moved'] ?? []))['reporting_lines'] ?? 0),
            ],
        ];
    }

    /** @return list<int> */
    private function openRightNow(ProcessCase $case): array
    {
        if ($case->state !== ProcessCase::Open) {
            return [];
        }

        return $this->openNow[(int) $case->getKey()] ??= (new AvailableSteps)
            ->for($case)
            ->map(fn (AvailableStep $waiting): int => (int) $waiting->step->sequence)
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
     * @param  list<int>  $open
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
            // A step nobody here answers is held by an address rather than by an account:
            // the leaver confirming their own handover has no login and answers through a
            // link. Saying it was "picked up" put the case on their desk, when in truth
            // nothing has happened since the link was sent.
            return $row->assignee_id === null
                ? "Sent to {$who} to answer through a link. Not answered yet."
                : "Picked up by {$who}, not yet answered.";
        }

        $said = self::Said[$row->outcome] ?? $row->outcome;

        $acted = $case->events
            ->where('type', 'step_acted')
            ->last(fn (CaseEvent $event): bool => $this->isAbout($event, $row->sequence));

        $trail = $acted === null ? [] : (array) $acted->payload;

        return "{$said} by {$who} on ".$row->acted_at->format('j F Y')
            .$this->onWhoseBehalf($trail).'.'
            .($acted === null ? '' : $this->andWhy($case, $trail));
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
     * A step handed to somebody else because its holder left the company is one of these
     * lines too. Without it the row would simply start reading the successor's name, and
     * the difference between a handover and a forged history is that the change of hands
     * is said out loud.
     *
     * @return list<string>
     */
    private function earlierPassesAt(ProcessCase $case, ProcessStep $step, bool $theLineAboveTookTheLast): array
    {
        $passes = $case->events
            ->whereIn('type', ['step_acted', 'step_reopened', Succession::StepTransferredEvent])
            ->filter(fn (CaseEvent $event): bool => $this->isAbout($event, $step->sequence))
            ->values();

        if ($theLineAboveTookTheLast) {
            // Only an answer is what the line above described. A transfer sitting last in
            // the trail is not, so dropping it would lose the one line that says the step
            // changed hands.
            $last = $passes->last();

            if ($last instanceof CaseEvent && $last->type === 'step_acted') {
                $passes = $passes->slice(0, -1);
            }
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

        if ($event->type === Succession::StepTransferredEvent) {
            $from = (string) (((array) ($said['from'] ?? []))['name'] ?? 'somebody');
            $to = (string) (((array) ($said['to'] ?? []))['name'] ?? 'somebody');
            $because = trim((string) ($said['because'] ?? ''));

            return "Moved from {$from} to {$to} on ".$event->created_at->format('j F Y').'.'
                .($because === '' ? '' : " Why: {$because}.");
        }

        $who = $event->actor?->name
            ?? ((array) ($said['answered_by'] ?? []))['name']
            ?? 'somebody';

        $what = $event->type === 'step_reopened'
            ? 'Sent back to here'
            : (self::Said[$said['outcome'] ?? ''] ?? 'Answered');

        return "{$what} by {$who} on ".$event->created_at->format('j F Y')
            .$this->onWhoseBehalf($said).'.'.$this->andWhy($case, $said);
    }

    /**
     * Whom an answer was given on behalf of, where it was given by somebody covering for
     * a colleague who was away.
     *
     * The engine has recorded this against every answer since cover existed and no screen
     * has ever read it, so an approval Priya gave on Rakesh's behalf has read on this page
     * as an approval Priya gave — which is the record a tribunal would be handed. The
     * queue card says it before she answers; this is the same fact read back a year later.
     *
     * @param  array<string, mixed>  $said  One line of the trail, as the engine wrote it.
     */
    private function onWhoseBehalf(array $said): string
    {
        $away = (string) (((array) ($said['covering_for'] ?? []))['name'] ?? '');

        return $away === '' ? '' : ", on {$away}'s behalf while covering for them";
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
}
