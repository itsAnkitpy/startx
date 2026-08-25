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
     * Asked only when an earlier question on the same form was answered a certain way.
     *
     * One comparison, which is every case any form in this product has needed. A question
     * hidden by two things at once is written straight onto `visible_if` as the list of
     * sets it is.
     */
    public function askedWhen(string $earlierQuestion, string $operator, mixed $value = null): static
    {
        $condition = ['field' => $earlierQuestion, 'operator' => $operator];

        return $this->state(['visible_if' => [[
            $operator === 'is_set' ? $condition : [...$condition, 'value' => $value],
        ]]]);
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
