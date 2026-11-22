<?php

namespace App\Process;

use App\Models\CaseStep;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One step of one case whose turn it is right now.
 *
 * `availableSince` is the moment the last step blocking this one closed, and it is
 * worked out rather than stored — a step nobody has touched has no row to carry a
 * timestamp, and that is exactly the step a reminder exists for. For a step in the
 * first group it is when the case opened.
 *
 * `attempt` is the live row, where one exists: somebody has picked the step up, or is
 * holding it, or sent the case back from it. Null is the ordinary case for a step nobody
 * has started.
 *
 * The rest is the step's own service clock and what is owed on it. All of it is worked
 * out from `availableSince` and the calendar of the office the case's subject worked in,
 * and none of it is stored anywhere:
 *
 * - `dueAt` — when this step's own target runs out, or null where the step has no
 *   target and nothing is ever chased. It is not the case's statutory deadline, which is
 *   frozen on the case itself and is the one with a legal consequence.
 * - `nudgesOwed` — how many staged reminders have fallen due by now: one past half the
 *   target, two past three-quarters. It is what is owed in total rather than what is
 *   new, because what has already been sent is the notification log's answer to give.
 * - `lastNudgeDueAt` — when the most recent of those fell due. A reminder sent since then
 *   answers every stage before it, which is how several missed chases collapse into one.
 * - `escalationOwed` — whether the target has run out, which is the moment the chase
 *   goes above whoever is holding it. At the deadline rather than after it, because
 *   against a two-working-day statutory clock a chase that lands after the breach is
 *   worth nothing.
 * - `escalateTo` — the manager of the person holding the step, resolved now rather than
 *   frozen, because the question is genuinely about who is above them today. Null where
 *   nobody has picked the step up, where the holder has nobody above them, and where the
 *   holder is somebody with no account. **Null does not mean nobody is chased**: the
 *   step's own `assignee_rule` names the group the work was meant for, and module 03
 *   turns that into people. Every product that runs a clock escalates into that group
 *   rather than to whoever opened the case; see section 28 of module 02's research.
 *
 * **`escalateTo` and a step's own `escalate_to` rule answer different questions and must
 * not be confused.** This one is who gets *told* the step is late — the person above
 * whoever is holding it, which is the shape every chase-up takes. The rule on the step is
 * who may then *act* on it, added beside the people it already belonged to. A step can
 * have one, both or neither.
 */
final readonly class AvailableStep
{
    public function __construct(
        public ProcessCase $case,
        public ProcessStep $step,
        public CarbonImmutable $availableSince,
        public ?CaseStep $attempt,
        public ?CarbonImmutable $dueAt = null,
        public int $nudgesOwed = 0,
        public bool $escalationOwed = false,
        public ?User $escalateTo = null,
        public ?CarbonImmutable $lastNudgeDueAt = null,
    ) {}

    /**
     * Whether somebody has this step right now: picked it up, or picked it up and held it.
     *
     * A step the case was sent back from keeps the sender's name on its row, but once the
     * correction comes forward it is everybody's it belongs to again, as the engine already
     * treats it. Read as the sender's, it showed in their queue alone and chased only them,
     * so a sender who left took it into nobody's queue (the review of 22 November 2026).
     */
    public function isPickedUp(): bool
    {
        return $this->attempt?->assignee_id !== null
            && in_array($this->attempt->outcome, [null, 'held'], true);
    }
}
