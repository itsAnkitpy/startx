<?php

namespace Database\Factories;

use App\Models\ProcessCase;
use App\Models\Succession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Succession>
 */
class SuccessionFactory extends Factory
{
    protected $model = Succession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_user_id' => User::factory(),
            'to_user_id' => User::factory(),
            'case_id' => ProcessCase::factory(),
            'effective_at' => now()->toDateString(),
        ];
    }

    /** Who left, and who took the work on. */
    public function handingOver(User $leaver, User $successor): static
    {
        return $this->state([
            'from_user_id' => $leaver->getKey(),
            'to_user_id' => $successor->getKey(),
        ]);
    }
}
