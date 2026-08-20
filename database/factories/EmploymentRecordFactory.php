<?php

namespace Database\Factories;

use App\Models\EmploymentRecord;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmploymentRecord>
 */
class EmploymentRecordFactory extends Factory
{
    protected $model = EmploymentRecord::class;

    /**
     * Fixed dates rather than random ones: every test here asks what was true on a
     * particular day, and a random start date makes that unreadable.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_code' => fake()->unique()->numerify('EMP####'),
            'org_unit_id' => OrgUnit::factory(),
            'employment_type' => 'full_time',
            'employment_status' => 'confirmed',
            'reports_to_id' => null,
            'joining_date' => '2024-04-01',
            'last_working_day' => null,
            'effective_from' => '2024-04-01',
            'effective_to' => null,
            'change_reason' => 'joined',
        ];
    }

    public function forPerson(User $person): static
    {
        return $this->state([
            'user_id' => $person->getKey(),
            'joining_date' => '2024-04-01',
        ]);
    }

    public function in(OrgUnit $unit): static
    {
        return $this->state(['org_unit_id' => $unit->getKey()]);
    }

    public function reportingTo(User $manager): static
    {
        return $this->state(['reports_to_id' => $manager->getKey()]);
    }

    /**
     * The window this row was true for. A null end means it is the row that is true
     * today, and only one row per person may be in that state.
     */
    public function effective(string $from, ?string $to = null): static
    {
        return $this->state(['effective_from' => $from, 'effective_to' => $to]);
    }
}
