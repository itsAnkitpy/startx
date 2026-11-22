<?php

namespace App\Notifications;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Exceptions\ProcessRefused;
use App\Models\EmploymentRecord;
use App\Models\NotificationDelivery;
use App\Models\Office;
use App\Models\ProcessCase;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use App\Process\StepLink;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * Telling somebody a step is waiting on them: the moment it becomes theirs, by the one
 * scheduled pass as it runs late, or by a person pressing the chase button on a case.
 *
 * **Everything here starts from the reader of waiting steps, never from the step rows.**
 * The clearance a department has not opened at all is the one that most needs chasing and
 * the one with no row, so a pass written against the rows would remind the six departments
 * already working and say nothing to the seventh.
 *
 * **Nothing is stored to make a chase happen once.** What has already gone out is the record
 * of what was sent, and a chase is owed when nothing has gone to that person about that step
 * since the moment it fell due. That one rule is also what collapses several missed chases
 * into one: a pass that stopped for four hours finds two stages past, sends about the later,
 * and the earlier is answered by it — Airflow's scheduler does the same with missed runs when
 * catching up is switched off, running only the latest.
 */
final class Chasing
{
    public function __construct(
        private readonly AssigneeResolver $resolver = new AssigneeResolver,
        private readonly Sender $sender = new Sender,
    ) {}

    /**
     * Every message the scheduled pass owes right now, each ready to be sent on its own.
     *
     * Handed back rather than sent, so the caller can send each in its own short write: a
     * mail server taking its time then holds up one message rather than every other one,
     * and a send that fails cannot take the record of the others with it.
     *
     * @return list<Closure(): mixed>
     */
    public function owed(): array
    {
        $open = ProcessCase::query()
            ->whereNull('closed_at')
            ->whereNull('cancelled_at')
            ->with('liveSteps.assignee')
            ->get();

        if ($open->isEmpty()) {
            return [];
        }

        $waiting = (new AvailableSteps)->forAll($open);

        $history = NotificationDelivery::query()
            ->whereIn('case_id', $open->modelKeys())
            ->whereIn('template_key', [
                TemplateCatalogue::StepReminderHolder,
                TemplateCatalogue::StepEscalationManager,
                TemplateCatalogue::CaseBreachWarning,
            ])
            ->get(['case_id', 'step_sequence', 'template_key', 'recipient_user_id', 'created_at']);

        $lastSent = $history
            ->groupBy(fn (NotificationDelivery $row) => $this->about($row->case_id, $row->step_sequence, $row->template_key, $row->recipient_user_id))
            ->map(fn (Collection $rows) => $rows->max('created_at'));

        return [
            ...$this->stepChases($waiting, $lastSent),
            ...$this->breachWarnings($open, $waiting, $history),
        ];
    }

    /**
     * Chase whoever holds one step of a case today, because a person pressed the button.
     *
     * Sent at once whatever the day, and to nobody already chased about this step today —
     * the client's today, counted from the record of what was sent rather than from a
     * counter that would disagree with it the first time the cache is cleared.
     *
     * A step only a link can answer is chased by sending a fresh link, counted once a day the
     * same way from the links sent for it, so a leaver whose address was missing when the
     * step opened gets one the moment somebody fills it in and presses.
     *
     * ponytail: two presses in the same instant both find nobody chased yet and both send.
     * The button is disabled while its request runs; add a lock here if that ever proves
     * not enough.
     *
     * @return Collection<int, User> whoever the chase actually reached the mail server for
     *
     * @throws ProcessRefused when there is nobody to chase, everybody has been today, a link has nowhere to go, or not one email went out
     */
    public function byHand(ProcessCase $case, int $sequence, User $by): Collection
    {
        $step = (new AvailableSteps)->for($case)
            ->first(fn (AvailableStep $waiting) => (int) $waiting->step->sequence === $sequence);

        $holders = $step === null ? collect() : $this->whoCanBeChasedBy($step, $by);

        if ($step === null || $holders->isEmpty()) {
            throw ProcessRefused::nobodyToChase($step?->step->name ?? 'that step');
        }

        $byLink = $this->resolver->isForSomebodyWithNoAccount($step->step);

        // Laravel writes a time into a query exactly as it reads, so the client's midnight
        // goes in as UTC rather than as a wall-clock time UTC would misread.
        $startOfToday = CarbonImmutable::now($case->tenant->timezone)->startOfDay()->utc();

        // A chase given up on is not a chase, so it does not use up the day's one. One still
        // being tried again does, or the retry would reach them a second time (settled with
        // Ankit on 17 November 2026). Only a press counts: the link that went out when the
        // step opened, or one the leaver asked for, is not a chase.
        $chasedToday = NotificationDelivery::query()
            ->where('case_id', $case->getKey())
            ->where('step_sequence', $sequence)
            ->where('template_key', $byLink ? TemplateCatalogue::LinkToAnswerAStep : TemplateCatalogue::StepNudgeHolder)
            ->whereNotNull('triggered_by')
            ->where('status', '!=', NotificationDelivery::Undelivered)
            ->where('created_at', '>=', $startOfToday)
            ->pluck('recipient_user_id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();

        $notYet = $holders->reject(fn (User $person) => in_array((int) $person->getKey(), $chasedToday, true))->values();

        if ($notYet->isEmpty()) {
            throw ProcessRefused::alreadyChasedToday($step->step->name, $holders->pluck('name')->all());
        }

        $chased = collect();

        foreach ($notYet as $person) {
            $sent = $byLink ? $this->aFreshLink($case, $sequence, $by) : $this->sender->send(
                $case,
                TemplateCatalogue::StepNudgeHolder,
                $person,
                [...$this->aboutTheStep($step, $person, $holders), 'chased_by.name' => $by->name],
                $sequence,
                $by,
            );

            if ($sent?->status === NotificationDelivery::Sent) {
                $chased->push($person);
            }
        }

        if ($chased->isEmpty()) {
            throw ProcessRefused::chaseDidNotGo($step->step->name, $notYet->pluck('name')->all());
        }

        return $chased;
    }

    /**
     * Tell whoever a step has just become waiting on that it is their turn. The engine calls
     * this once everything around the write that made the step available has been saved,
     * and only for a step still waiting then — so nothing has to be looked up to know it
     * has not been said.
     *
     * A step only a link can answer is sent its link instead, which is the same news for
     * somebody with no account, and it goes nowhere else: nobody here can answer it.
     *
     * @param  User|null  $by  whoever's press made it available, who is looking at the screen
     */
    public function itIsYourTurn(AvailableStep $step, ?User $by): void
    {
        if ($this->resolver->isForSomebodyWithNoAccount($step->step)) {
            try {
                (new StepLink)->issue($step->case, $step->step->sequence);
            } catch (ProcessRefused $nowhereToSendIt) {
                // The answer that opened the step has already been saved, so it must not
                // come back to the person who gave it as a refusal. It shows on the case as
                // could not be sent and whoever raised it is told now; the chase button
                // sends a fresh link once the address is filled in.
                (new TryingAgain($this->sender))->couldNotSendTheLink($step, $nowhereToSendIt->getMessage());
            }

            return;
        }

        $holders = $this->holdersToday($step);

        foreach ($holders as $person) {
            if ($by !== null && $person->is($by)) {
                continue;
            }

            $facts = $this->aboutTheStep($step, $person, $holders);

            // A whole sentence or nothing, because a hiring request is about nobody and its
            // steps have no due date: "It is due by ." is what Priya would otherwise read.
            $facts['step.due_by'] = $facts['step.due_at'] === '' ? '' : " It is due by {$facts['step.due_at']}.";

            $this->sender->send($step->case, TemplateCatalogue::StepAvailableHolder, $person, $facts, $step->step->sequence);
        }
    }

    /**
     * Who pressing the button about this step would reach: whoever holds it today, or the
     * person a link step is waiting on, less the person pressing — chasing yourself is not a
     * chase.
     *
     * @return Collection<int, User>
     */
    public function whoCanBeChasedBy(AvailableStep $step, User $by): Collection
    {
        $people = $this->resolver->isForSomebodyWithNoAccount($step->step)
            ? collect([$step->case->subject])->filter()
            : $this->holdersToday($step);

        return $people
            ->reject(fn (User $person) => (int) $person->getKey() === (int) $by->getKey())
            ->values();
    }

    /**
     * Send a fresh link for a step, with the name of whoever pressed the button on its record,
     * and hand back that record. A missing address is refused in the link's own sentence.
     */
    private function aFreshLink(ProcessCase $case, int $sequence, User $by): ?NotificationDelivery
    {
        (new StepLink)->issue($case, $sequence, $by);

        return NotificationDelivery::query()
            ->where('case_id', $case->getKey())
            ->where('step_sequence', $sequence)
            ->where('template_key', TemplateCatalogue::LinkToAnswerAStep)
            ->latest('id')
            ->first();
    }

    /**
     * Whoever is actually holding this step today: the people the queue screen shows it
     * to, less anybody away while somebody covers for them, and without the widening the
     * queue does past the deadline — those people are told by the escalation instead.
     *
     * Somebody who has picked it up holds it alone. Otherwise it is everybody it belongs
     * to, with anybody away replaced by whoever is covering for them: chasing Rakesh on his
     * holiday reaches the one person who cannot act, while Priya, who could clear it in
     * half a minute, would never hear it is late. Each person covering carries whose work it
     * is, so the message can say so in the words their queue card already uses.
     *
     * ponytail: somebody who picked a step up and then went away is still the one chased,
     * because the queue still shows it to them alone. Hand it to their cover here and on
     * the queue together, if a client asks.
     *
     * @return Collection<int, User>
     */
    private function holdersToday(AvailableStep $step): Collection
    {
        $pickedUpBy = $step->isPickedUp() ? $step->attempt->assignee : null;

        if ($pickedUpBy instanceof User) {
            return collect([$pickedUpBy])->filter(fn (User $person) => (bool) $person->active)->values();
        }

        $people = $this->resolver->resolve($step->case, $step->step);

        $away = $people
            ->map(fn (User $person) => $person->relationLoaded('coveringFor') ? $person->getRelation('coveringFor')?->getKey() : null)
            ->filter()
            ->map(fn (mixed $id) => (int) $id)
            ->all();

        return $people->reject(fn (User $person) => in_array((int) $person->getKey(), $away, true))->values();
    }

    /**
     * The reminders and escalations owed on steps with a clock, reminders held for anybody
     * whose office is closed today.
     *
     * At the deadline the escalation is the chase and the reminder is not sent with it:
     * three-quarters and the deadline missed together are one late step, and the message
     * that says so is the one above the holder.
     *
     * ponytail: a step long past its deadline is worked out again every minute after its
     * escalation went, and every chase ever sent on an open case is read on every pass.
     * Fine at a few hundred open cases. When one pass stops fitting in its minute, skip a
     * step already escalated and have the database return only the latest send per person.
     *
     * @param  Collection<int, AvailableStep>  $waiting
     * @param  Collection<string, mixed>  $lastSent
     * @return list<Closure(): mixed>
     */
    private function stepChases(Collection $waiting, Collection $lastSent): array
    {
        $due = $waiting
            ->filter(fn (AvailableStep $step) => $step->dueAt !== null)
            ->map(function (AvailableStep $step): ?array {
                if ($step->escalationOwed) {
                    return [$step, TemplateCatalogue::StepEscalationManager, $step->dueAt, $this->whoItEscalatesTo($step), $this->holdersToday($step)];
                }

                if ($step->lastNudgeDueAt === null) {
                    return null;
                }

                $holders = $this->holdersToday($step);

                return [$step, TemplateCatalogue::StepReminderHolder, $step->lastNudgeDueAt, $holders, $holders];
            })
            ->filter();

        $offices = $this->officesOf($due
            ->flatMap(fn (array $chase) => $chase[3]->map(fn (User $person) => (int) $person->getKey()))
            ->unique()->values()->all());

        $owed = [];

        foreach ($due as [$step, $key, $since, $recipients, $holders]) {
            $case = $step->case;

            foreach ($recipients as $person) {
                $last = $lastSent->get($this->about($case->getKey(), $step->step->sequence, $key, $person->getKey()));

                if ($last !== null && $last->greaterThanOrEqualTo($since)) {
                    continue;
                }

                // A reminder is held to the next working day at their own office, and an
                // office of their own is not something everybody has: a recipient without
                // one is held against the calendar the step's own deadline was counted on.
                // The day is the client's, read on the client's clock. An escalation is
                // never held — it is the last word before the deadline is broken, and
                // waiting for the manager's office to reopen is the breach.
                $calendar = $offices->get((int) $person->getKey()) ?? $case->subjectEmploymentRecord->office;

                if ($key === TemplateCatalogue::StepReminderHolder && ! $calendar->isWorkingDay(CarbonImmutable::now($case->tenant->timezone))) {
                    continue;
                }

                $owed[] = fn () => $this->sender->send(
                    $case,
                    $key,
                    $person,
                    $this->aboutTheStep($step, $person, $holders),
                    $step->step->sequence,
                );
            }
        }

        return $owed;
    }

    /**
     * Who is told a step has run out of time: whoever its own escalation rule names, and
     * the manager of whoever picked it up.
     *
     * Not the people already holding it — they have had their reminders, and the rule
     * widens the step rather than handing it over. Never the person the case is about.
     *
     * @return Collection<int, User>
     */
    private function whoItEscalatesTo(AvailableStep $step): Collection
    {
        $holding = $this->resolver->resolve($step->case, $step->step)
            ->map(fn (User $person) => (int) $person->getKey())
            ->all();

        return $this->resolver->resolve($step->case, $step->step, true)
            ->reject(fn (User $person) => in_array((int) $person->getKey(), $holding, true))
            ->when($step->escalateTo !== null, fn (Collection $people) => $people->push($step->escalateTo))
            ->reject(fn (User $person) => (int) $person->getKey() === (int) $step->case->subject_user_id)
            ->unique(fn (User $person) => (int) $person->getKey())
            ->values();
    }

    /**
     * The one warning about a case that has passed the deadline the law sets, to whoever
     * raised it and to everybody who can change settings, less the person it is about.
     *
     * Sent whatever the day — a breach warning held for Monday morning is the breach — and
     * once per case. The deadline is a date, so it has passed once the client's own today
     * is later than it.
     *
     * @param  Collection<int, ProcessCase>  $open
     * @param  Collection<int, AvailableStep>  $waiting
     * @param  Collection<int, NotificationDelivery>  $history
     * @return list<Closure(): mixed>
     */
    private function breachWarnings(Collection $open, Collection $waiting, Collection $history): array
    {
        $warned = $history
            ->where('template_key', TemplateCatalogue::CaseBreachWarning)
            ->map(fn (NotificationDelivery $row) => (int) $row->case_id)
            ->all();

        $whoChangesSettings = null;
        $owed = [];

        foreach ($open as $case) {
            $passed = $case->statutory_due_at !== null
                && CarbonImmutable::now($case->tenant->timezone)->toDateString() > $case->statutory_due_at->toDateString();

            if (! $passed || in_array((int) $case->getKey(), $warned, true)) {
                continue;
            }

            $whoChangesSettings ??= app(PermissionResolver::class)->whoMayAnywhere(Permission::ManageSettings);

            $values = [
                'case.deadline' => $case->statutory_due_at->format('j F Y'),
                'case.waiting_on' => $waiting
                    ->filter(fn (AvailableStep $step) => $step->case->is($case))
                    ->map(fn (AvailableStep $step) => $step->step->name)
                    ->join(', ', ' and '),
            ];

            // Never the person the case is about, even when they raised it or can change
            // settings: the law's deadline is the employer's to keep, not the leaver's.
            collect([$case->initiatedBy])
                ->filter(fn (?User $person) => $person instanceof User && $person->active)
                ->concat($whoChangesSettings)
                ->reject(fn (User $person) => (int) $person->getKey() === (int) $case->subject_user_id)
                ->unique(fn (User $person) => (int) $person->getKey())
                ->each(function (User $person) use ($case, $values, &$owed): void {
                    $owed[] = fn () => $this->sender->send($case, TemplateCatalogue::CaseBreachWarning, $person, $values);
                });
        }

        return $owed;
    }

    /**
     * The facts a message about one step needs, as the person reading it will read them.
     *
     * @param  Collection<int, User>  $holders
     * @return array<string, string>
     */
    private function aboutTheStep(AvailableStep $step, User $to, Collection $holders): array
    {
        $away = $to->relationLoaded('coveringFor') ? $to->getRelation('coveringFor') : null;

        return [
            'step.name' => $step->step->name,
            'step.due_at' => $step->dueAt?->setTimezone($to->timezone ?? $step->case->tenant->timezone)->format('j F Y, g:i a') ?? '',
            'step.on_behalf_of' => $away instanceof User ? ", on {$away->name}'s behalf while you are covering for them" : '',
            'step.held_by' => $holders->isEmpty() ? 'nobody yet' : $holders->pluck('name')->join(', ', ' and '),
        ];
    }

    /**
     * Each person's own office, for the ones who have one, in one read.
     *
     * @param  list<int>  $people
     * @return Collection<int, Office>
     */
    private function officesOf(array $people): Collection
    {
        if ($people === []) {
            return collect();
        }

        return EmploymentRecord::query()
            ->whereIn('user_id', $people)
            ->whereNull('effective_to')
            ->whereNotNull('office_id')
            ->with('office.holidays')
            ->get()
            ->mapWithKeys(fn (EmploymentRecord $row) => [(int) $row->user_id => $row->office]);
    }

    private function about(int|string $case, int|string|null $sequence, string $key, int|string|null $person): string
    {
        return "{$case}:{$sequence}:{$key}:{$person}";
    }
}
