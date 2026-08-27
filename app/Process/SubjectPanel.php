<?php

namespace App\Process;

use App\Models\ProcessCase;
use DateTimeInterface;

/**
 * The person a case is about, as the case froze them — the fixed panel above every
 * step's form.
 *
 * Somebody asked to clear an exit under a two-working-day clock cannot decide anything
 * without the person's details in front of them, and they will not go and look them up
 * in another screen. They will guess, or they will hold. So every step shows the same
 * details, whatever the step asks, and no client configures which of them appear.
 *
 * **It reads the job row the case pinned when it opened, never the person's current
 * one.** While a case is live the two are the same row, so nothing is lost; a year later,
 * reading a closed exit, it is the difference between an audit trail and a
 * plausible-looking wrong one. The designation is read from the words the row copied down
 * rather than through the list it points at, for the same reason one step further on — a
 * client tidying "Sr. Manager" into "Senior Manager" must not rewrite what every closed
 * case says the person was.
 *
 * Nothing here decides who may look. Whoever the queue screen already put this step in
 * front of is who sees it, which is a question answered before the page is drawn.
 */
final class SubjectPanel
{
    /** What is shown where a fact was never recorded. One word, one meaning. */
    private const NotRecorded = 'Not recorded';

    /**
     * The panel for one case: who it is about, and the details as of the day it opened.
     *
     * `instead` carries the sentence shown when there is no person to describe, and is
     * null whenever there is one.
     *
     * @return array{who: ?string, instead: ?string, facts: array<string, string>}
     */
    public function of(ProcessCase $case): array
    {
        $record = $case->subjectEmploymentRecord;

        if ($record === null) {
            return [
                'who' => $case->subject?->name,
                'instead' => $this->whyThereIsNothingToShow($case),
                'facts' => [],
            ];
        }

        return [
            'who' => $case->subject?->name,
            'instead' => null,
            'facts' => [
                'Employee code' => $record->employee_code ?? self::NotRecorded,
                'Designation' => $record->recorded_designation_name ?? self::NotRecorded,
                'Department' => $record->orgUnit?->name ?? self::NotRecorded,
                'Office' => $record->office?->name ?? self::NotRecorded,
                'Joined' => $this->asADate($record->joining_date),
                'Last working day' => $this->asADate($record->last_working_day),
                'Reports to' => $record->reportsTo?->name ?? self::NotRecorded,
                'Legal deadline' => $this->asADate($case->statutory_due_at),
            ],
        ];
    }

    /**
     * Why a case has no person to describe.
     *
     * A hiring request is about a vacancy and a case about a candidate is about somebody
     * who does not work here yet, so neither has a job row to read. Rendering the vacancy
     * itself, and a candidate's own submitted answers, belongs to the modules that create
     * them — this says plainly that there is nothing rather than drawing an empty panel.
     */
    private function whyThereIsNothingToShow(ProcessCase $case): string
    {
        return match (true) {
            $case->subject !== null => 'No job record is pinned to this case, so nothing here can be shown as it was.',
            $case->template->subject_kind === 'candidate' => 'This is about somebody who has not joined yet, so there is no job record to read.',
            default => 'This case is not about a person, so there is nothing here to read.',
        };
    }

    private function asADate(?DateTimeInterface $date): string
    {
        return $date === null ? self::NotRecorded : $date->format('j F Y');
    }
}
