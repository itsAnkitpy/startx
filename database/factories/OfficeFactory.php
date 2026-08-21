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
            'weekly_off_days' => [0, 6],
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

    /**
     * Which weekdays this office does not work, as Carbon counts them — Sunday is 0 and
     * Saturday is 6. A Gulf office is `weekendOn([5, 6])`.
     *
     * @param  list<int>  $days
     */
    public function weekendOn(array $days): static
    {
        return $this->state(['weekly_off_days' => $days]);
    }

    public function switchedOff(): static
    {
        return $this->state(['active' => false]);
    }
}
