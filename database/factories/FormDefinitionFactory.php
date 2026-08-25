<?php

namespace Database\Factories;

use App\Models\FormDefinition;
use App\Models\FormField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormDefinition>
 */
class FormDefinitionFactory extends Factory
{
    protected $model = FormDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => 'Finance clearance',
            'version' => 1,
            'status' => FormDefinition::Draft,
        ];
    }

    public function named(string $key, string $name): static
    {
        return $this->state(['key' => $key, 'name' => $name]);
    }

    public function version(int $version): static
    {
        return $this->state(['version' => $version]);
    }

    /**
     * A live form, made live the way the product makes one: a draft with a question on
     * it, then published. Setting the status straight to published would produce a form
     * that asks nothing, which publishing itself refuses.
     */
    public function published(): static
    {
        return $this->afterCreating(function (FormDefinition $form): void {
            if ($form->fields()->count() === 0) {
                FormField::factory()->on($form)->create();
            }

            $form->publish();
        });
    }
}
