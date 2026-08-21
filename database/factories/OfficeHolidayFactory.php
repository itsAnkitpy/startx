<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\OfficeHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeHoliday>
 */
class OfficeHolidayFactory extends Factory
{
    protected $model = OfficeHoliday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'date' => fake()->dateTimeBetween('+1 week', '+1 year')->format('Y-m-d'),
        ];
    }

    public function on(string $date, ?string $name = null): static
    {
        return $this->state(array_filter([
            'date' => $date,
            'name' => $name,
        ]));
    }

    public function at(Office $office): static
    {
        return $this->state(['office_id' => $office->getKey()]);
    }
}
