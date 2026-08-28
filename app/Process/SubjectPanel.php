<?php

namespace App\Process;

use App\Models\FormField;
use App\Models\ProcessCase;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The fixed panel above every step's form: what the person acting has to know before
 * they can decide anything.
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
 * **A case about nobody shows what was asked for instead.** A hiring request is about a
 * vacancy, so there is no person and no job row, and an approver was being shown a
 * sentence saying there was nothing to see — on the one screen where the whole decision
 * is what is being asked for. It shows the first step's own answers: the department, the
 * designation, how many, the salary. That rule names no process and no question, which is
 * what keeps it true of any process about nobody, and it is what Jira Service Management
 * and Camunda both do. ServiceNow is the counter-example and its customers hand-build a
 * summary field per flow to work around it.
 *
 * Nothing here decides who may look. Whoever the queue screen already put this step in
 * front of is who sees it, which is a question answered before the page is drawn.
 */
final class SubjectPanel
{
    /** What is shown where a fact was never recorded. One word, one meaning. */
    private const NotRecorded = 'Not recorded';

    /**
     * The panel for one case: what it is called, who it is about, and the details.
     *
     * `instead` carries the sentence shown when there is nothing to describe at all.
     * `asOf` carries the line underneath, and is there only where the details really are
     * a copy of somebody's record taken on a day — a request's answers are simply its
     * answers, and claiming they were frozen would be a claim about nothing.
     *
     * @return array{heading: string, who: ?string, instead: ?string, facts: array<string, string>, asOf: ?string}
     */
    public function of(ProcessCase $case): array
    {
        $record = $case->subjectEmploymentRecord;

        if ($record === null) {
            // Only where there is no person at all. A case that names somebody but has no
            // job row pinned to it is a gap in that person's record, and saying so is
            // right; showing the first step's answers under it would hide it.
            $asked = $case->subject === null ? $this->whatWasAskedFor($case) : [];

            if ($asked !== []) {
                return [
                    'heading' => 'What this request is for',
                    'who' => null,
                    'instead' => null,
                    'facts' => $asked,
                    'asOf' => null,
                ];
            }

            return [
                'heading' => 'Who this is about',
                'who' => $case->subject?->name,
                'instead' => $this->whyThereIsNothingToShow($case),
                'facts' => [],
                'asOf' => null,
            ];
        }

        return [
            'heading' => 'Who this is about',
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
            // The whole claim of the panel, said on the panel. Without it a designation
            // read a year after the exit closed looks like today's.
            'asOf' => 'As they were when this case opened on '
                .$case->opened_at->format('j F Y').', not as they are today.',
        ];
    }

    /**
     * What the case was raised asking for, read off the first step's own answers.
     *
     * The first step rather than every step, because the later ones are the approvals
     * themselves: an approver's own remarks belong on their card, not in the panel above
     * it telling them what they are deciding.
     *
     * Empty when nothing has been answered yet, which is what sends a case about nobody
     * back to the plain sentence below.
     *
     * @return array<string, string>
     */
    private function whatWasAskedFor(ProcessCase $case): array
    {
        $first = $case->template->steps->sortBy('sequence')->first();

        if ($first === null) {
            return [];
        }

        $answers = (array) ($case->liveSteps->firstWhere('sequence', $first->sequence)?->payload ?? []);

        if ($answers === []) {
            return [];
        }

        $forms = new StepForm;
        $facts = [];

        foreach ($forms->fields($first) as $question) {
            if (array_key_exists($question->key, $answers)) {
                $facts[$question->label] = $this->inWords($question, $answers[$question->key], $forms);
            }
        }

        return $facts;
    }

    /**
     * One answer as a person reads it.
     *
     * A picker stores the number of a row, so on its own it tells an approver nothing —
     * the row it points at is what turns it back into the client's own words. A choice is
     * read off the stored pairs rather than off the value, so renaming a choice on the
     * next version of the form does not rewrite what an answer already given said.
     */
    private function inWords(FormField $question, mixed $answer, StepForm $forms): string
    {
        return match ($question->type) {
            FormField::UserPicker,
            FormField::OrgUnitPicker,
            FormField::DesignationPicker => $forms->nameOfThePicked($question, $answer) ?? self::NotRecorded,
            FormField::Select => $this->chosen($question, $answer),
            FormField::Multiselect => implode(', ', array_map(
                fn (mixed $one): string => $this->chosen($question, $one),
                (array) $answer,
            )),
            FormField::Boolean => $answer ? 'Yes' : 'No',
            // Every writer checks the shape of a date before it is stored, so this is the
            // answer stored under a question that used to ask for something else. It reads
            // as nothing rather than taking the panel — and every form under it — down.
            FormField::Date => rescue(
                fn (): string => $this->asADate(CarbonImmutable::createFromFormat('Y-m-d', (string) $answer)),
                self::NotRecorded,
                report: false,
            ),
            FormField::File => (string) (((array) $answer)['name'] ?? self::NotRecorded),
            default => is_scalar($answer) ? (string) $answer : self::NotRecorded,
        };
    }

    /** The client's own label for one chosen value, or the value where it has none. */
    private function chosen(FormField $question, mixed $value): string
    {
        foreach ((array) $question->options as $option) {
            if (is_array($option) && ($option['value'] ?? null) == $value) {
                return (string) ($option['label'] ?? $value);
            }
        }

        return is_scalar($value) ? (string) $value : self::NotRecorded;
    }

    /**
     * Why a case has nothing to describe at all.
     *
     * Reached only where the first step has been answered with nothing — a request nobody
     * has filled in yet, or a process whose first step asks no questions. A case about a
     * candidate is about somebody who does not work here yet, so there is no job row
     * either; what they submitted belongs to the module that collects it.
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
