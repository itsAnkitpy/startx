<?php

namespace Database\Factories;

use App\Models\CaseEvent;
use App\Models\ProcessCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaseEvent>
 */
class CaseEventFactory extends Factory
{
    protected $model = CaseEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'case_id' => ProcessCase::factory(),
            'actor_id' => null,
            'type' => 'case_opened',
            'payload' => [],
        ];
    }

    public function of(ProcessCase $case): static
    {
        return $this->state(['case_id' => $case->getKey()]);
    }

    public function by(User $actor): static
    {
        return $this->state(['actor_id' => $actor->getKey()]);
    }

    public function ofType(string $type): static
    {
        return $this->state(['type' => $type]);
    }
}
