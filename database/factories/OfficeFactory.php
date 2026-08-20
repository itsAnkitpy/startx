<?php

namespace Database\Factories;

use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Office>
 */
class OfficeFactory extends Factory
{
    protected $model = Office::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'country' => 'IN',
            'state_code' => 'IN-HP',
            'address_block' => null,
            'active' => true,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    /** An office outside India, which needs no schema change and may have no state. */
    public function in(string $country, ?string $stateCode = null): static
    {
        return $this->state(['country' => $country, 'state_code' => $stateCode]);
    }

    public function switchedOff(): static
    {
        return $this->state(['active' => false]);
    }
}
