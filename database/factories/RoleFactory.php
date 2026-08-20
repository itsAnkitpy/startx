<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'key' => Str::snake(Str::lower($name)).'_'.Str::lower(Str::random(5)),
            'name' => $name,
            'description' => null,
            'is_system' => false,
        ];
    }

    /**
     * A role with a fixed permanent internal name, for the tests that need to name one.
     */
    public function keyed(string $key, ?string $name = null): static
    {
        return $this->state([
            'key' => $key,
            'name' => $name ?? Str::headline($key),
        ]);
    }

    public function system(): static
    {
        return $this->state(['is_system' => true]);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->afterCreating(function (Role $role) use ($permissions): void {
            foreach ($permissions as $permission) {
                $role->permissions()->create(['permission' => $permission]);
            }
        });
    }
}
