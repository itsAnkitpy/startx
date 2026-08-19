<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'legal_name' => $name.' Private Limited',
            'country' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'active' => true,
            'onboarded_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
