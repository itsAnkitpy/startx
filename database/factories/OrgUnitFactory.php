<?php

namespace Database\Factories;

use App\Models\OrgUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrgUnit>
 */
class OrgUnitFactory extends Factory
{
    protected $model = OrgUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'type' => 'department',
            'name' => fake()->unique()->words(2, true),
            'code' => null,
            'active' => true,
        ];
    }

    public function under(OrgUnit $parent, ?string $type = null): static
    {
        return $this->state([
            'parent_id' => $parent->getKey(),
            'type' => $type ?? $parent->type,
        ]);
    }

    public function ofType(string $type): static
    {
        return $this->state(['type' => $type]);
    }
}
