<?php

namespace Database\Factories;

use App\Models\Delegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delegation>
 */
class DelegationFactory extends Factory
{
    protected $model = Delegation::class;

    /**
     * A cover running today, which is the only state resolution can see.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'delegate_id' => User::factory(),
            'process_key' => 'exit',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => now()->addWeek()->toDateString(),
        ];
    }

    /** Somebody away, and whoever is holding their queue while they are. */
    public function covering(User $away, User $delegate): static
    {
        return $this->state([
            'user_id' => $away->getKey(),
            'delegate_id' => $delegate->getKey(),
        ]);
    }

    /** Only the process named, which is the whole point of a cover being scoped. */
    public function forTheProcess(string $key): static
    {
        return $this->state(['process_key' => $key]);
    }

    public function between(string $from, string $to): static
    {
        return $this->state(['effective_from' => $from, 'effective_to' => $to]);
    }
}
