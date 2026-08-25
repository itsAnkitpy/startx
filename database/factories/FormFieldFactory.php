<?php

namespace Database\Factories;

use App\Models\FormDefinition;
use App\Models\FormField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormField>
 */
class FormFieldFactory extends Factory
{
    protected $model = FormField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_definition_id' => FormDefinition::factory(),
            'key' => 'remarks',
            'label' => 'Remarks',
            'type' => FormField::Text,
            'required' => false,
            'options' => [],
            'validation' => [],
            'sort_order' => 1,
            'visible_if' => null,
        ];
    }

    public function on(FormDefinition $form): static
    {
        return $this->state(['form_definition_id' => $form->getKey()]);
    }

    /** The question itself: what it is stored under, what it is called, and its type. */
    public function asking(string $key, string $label, string $type = FormField::Text): static
    {
        return $this->state(['key' => $key, 'label' => $label, 'type' => $type]);
    }

    public function required(): static
    {
        return $this->state(['required' => true]);
    }

    public function at(int $sortOrder): static
    {
        return $this->state(['sort_order' => $sortOrder]);
    }

    /**
     * The choices, stored as `{value, label}` pairs so that renaming a choice on the next
     * version cannot change what an answer already given meant.
     *
     * @param  array<string, string>  $choices  value => label
     */
    public function choosing(array $choices): static
    {
        return $this->state([
            'options' => array_map(
                fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys($choices),
                array_values($choices),
            ),
        ]);
    }

    /**
     * Named limits only — `min`, `max`, `max_length`. Never a rule string; see the
     * comment on the column.
     *
     * @param  array<string, mixed>  $limits
     */
    public function limitedBy(array $limits): static
    {
        return $this->state(['validation' => $limits]);
    }
}
