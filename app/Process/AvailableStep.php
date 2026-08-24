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
 * holding it, or sent the case back from it and is waiting to act again. Null is the
 * ordinary case for a step nobody has started.
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
    ) {}
}
