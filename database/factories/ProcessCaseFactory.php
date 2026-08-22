<?php

namespace Database\Factories;

use App\Models\EmploymentRecord;
use App\Models\ProcessCase;
use App\Models\ProcessTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessCase>
 */
class ProcessCaseFactory extends Factory
{
    protected $model = ProcessCase::class;

    /**
     * A fixed opening date rather than a random one, for the same reason module 01's job
     * rows have one: every deadline question here is about a particular day, and a random
     * one makes the answer unreadable.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_id' => ProcessTemplate::factory()->published(),
            'subject_user_id' => null,
            'subject_employment_record_id' => null,
            'initiated_by' => null,
            'opened_at' => '2026-09-04 10:00:00',
            'statutory_from' => null,
            'statutory_due_at' => null,
            'gratuity_due_at' => null,
            'settings_snapshot' => [],
            'subject_facts_snapshot' => [],
        ];
    }

    public function on(ProcessTemplate $template): static
    {
        return $this->state(['template_id' => $template->getKey()]);
    }

    /**
     * The person the case is about, pinned to the job row that was true for them when it
     * opened. Both together, because the audit trail is the reason either exists.
     */
    public function about(User $subject, EmploymentRecord $record): static
    {
        return $this->state([
            'subject_user_id' => $subject->getKey(),
            'subject_employment_record_id' => $record->getKey(),
        ]);
    }

    public function openedOn(string $at): static
    {
        return $this->state(['opened_at' => $at]);
    }

    /** The date every legal clock on the case counts from — for an exit, the last working day. */
    public function countingFrom(string $date): static
    {
        return $this->state(['statutory_from' => $date]);
    }
}
