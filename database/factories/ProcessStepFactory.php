<?php

namespace Database\Factories;

use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessStep>
 */
class ProcessStepFactory extends Factory
{
    protected $model = ProcessStep::class;

    /**
     * The minimal step the adoption rule promises: a name and who it belongs to, with
     * everything else defaulted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_id' => ProcessTemplate::factory(),
            'sequence' => 1,
            'group_no' => 1,
            'name' => 'Manager approval',
            'participant_kind' => 'internal',
            'assignee_rule' => ['kind' => 'reporting_manager'],
            'open_conditions' => [],
            'allowed_outcomes' => ['approved', 'rejected'],
            'sla_hours' => null,
            'reminder_rule' => null,
            'on_open' => [],
            'on_complete' => [],
        ];
    }

    public function of(ProcessTemplate $template): static
    {
        return $this->state(['template_id' => $template->getKey()]);
    }

    /** Where the step sits, and which steps it runs alongside. */
    public function at(int $sequence, int $groupNo): static
    {
        return $this->state(['sequence' => $sequence, 'group_no' => $groupNo]);
    }

    public function named(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    /** A clearance: approve or hold with a reason, and no authority to reject. */
    public function clearance(): static
    {
        return $this->state(['allowed_outcomes' => ['approved', 'held']]);
    }

    /** Somebody with no account, acting through a signed link. */
    public function external(): static
    {
        return $this->state(['participant_kind' => 'external']);
    }
}
