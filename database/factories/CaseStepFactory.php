<?php

namespace Database\Factories;

use App\Models\CaseStep;
use App\Models\ProcessCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaseStep>
 */
class CaseStepFactory extends Factory
{
    protected $model = CaseStep::class;

    /**
     * A step somebody has picked up and not yet acted on, which is the earliest a row
     * exists at all.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'case_id' => ProcessCase::factory(),
            'sequence' => 1,
            'assignee_id' => User::factory(),
            'external_assignee' => null,
            'acted_at' => null,
            'outcome' => null,
            'payload' => [],
        ];
    }

    public function of(ProcessCase $case): static
    {
        return $this->state(['case_id' => $case->getKey()]);
    }

    public function at(int $sequence): static
    {
        return $this->state(['sequence' => $sequence]);
    }

    public function heldBy(User $person): static
    {
        return $this->state(['assignee_id' => $person->getKey()]);
    }

    /** Somebody with no account, so the row carries an address instead of a user. */
    public function external(string $name, string $email): static
    {
        return $this->state([
            'assignee_id' => null,
            'external_assignee' => ['name' => $name, 'email' => $email, 'token_reference' => 'tok_'.fake()->uuid()],
        ]);
    }

    public function decided(string $outcome, string $at = '2026-09-07 09:00:00'): static
    {
        return $this->state(['outcome' => $outcome, 'acted_at' => $at]);
    }
}
