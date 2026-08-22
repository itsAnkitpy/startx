<?php

namespace App\Process;

use App\Models\CaseStep;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
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
 */
final readonly class AvailableStep
{
    public function __construct(
        public ProcessCase $case,
        public ProcessStep $step,
        public CarbonImmutable $availableSince,
        public ?CaseStep $attempt,
    ) {}
}
