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

    /** This step's own service target, in hours. Blank means it is never chased. */
    public function dueIn(int $hours): static
    {
        return $this->state(['sla_hours' => $hours]);
    }

    /**
     * Fractions of this step's own target at which whoever holds it gets a nudge,
     * replacing the usual half and three-quarters.
     */
    public function nudgingAt(float ...$fractions): static
    {
        return $this->state(['reminder_rule' => ['nudge_at' => $fractions]]);
    }

    /** What this step's actor may choose. */
    public function offering(string ...$outcomes): static
    {
        return $this->state(['allowed_outcomes' => $outcomes]);
    }

    /**
     * The step happens only when any one of these groups is fully true. Each argument is
     * one group.
     *
     * @param  array<int, array<string, mixed>>  ...$sets
     */
    public function happensWhen(array ...$sets): static
    {
        return $this->state(['open_conditions' => $sets]);
    }

    /**
     * Somebody with no account, acting through a signed link. Both fields together,
     * because publishing refuses a step that disagrees with itself about whether its
     * actor has an account.
     */
    public function external(): static
    {
        return $this->state([
            'participant_kind' => 'external',
            'assignee_rule' => ['kind' => 'external'],
        ]);
    }

    /**
     * Who this step widens to once its own target has run out, written in the same shape
     * as who it belongs to — they are the same six ways of finding people.
     *
     * @param  array<string, mixed>  $rule
     */
    public function escalatingTo(array $rule): static
    {
        return $this->state(['escalate_to' => $rule]);
    }

    /** Holders of a role in the subject's own department, or above it. */
    public function heldByTheRole(string $roleKey): static
    {
        return $this->state(['assignee_rule' => ['kind' => 'role_in_scope', 'role' => $roleKey]]);
    }

    /** Holders of a role anywhere in the client company. */
    public function heldByTheRoleAnywhere(string $roleKey): static
    {
        return $this->state(['assignee_rule' => ['kind' => 'role_global', 'role' => $roleKey]]);
    }

    /** The manager of whoever opened the case, rather than of the person it is about. */
    public function heldByTheInitiatorsManager(): static
    {
        return $this->state(['assignee_rule' => ['kind' => 'initiators_manager']]);
    }

    /** One named person, by work address — the escape hatch, discouraged in templates. */
    public function heldBy(string $workEmail): static
    {
        return $this->state(['assignee_rule' => ['kind' => 'specific_user', 'email' => $workEmail]]);
    }
}
