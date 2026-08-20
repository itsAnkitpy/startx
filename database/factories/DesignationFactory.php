<?php

namespace Database\Factories;

use App\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Designation>
 */
class DesignationFactory extends Factory
{
    protected $model = Designation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'active' => true,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    /** Retired: out of the picker, still readable on every job row that named it. */
    public function switchedOff(): static
    {
        return $this->state(['active' => false]);
    }
}
