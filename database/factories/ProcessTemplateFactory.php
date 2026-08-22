<?php

namespace Database\Factories;

use App\Models\ProcessTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessTemplate>
 */
class ProcessTemplateFactory extends Factory
{
    protected $model = ProcessTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => 'Exit',
            'version' => 1,
            'status' => 'draft',
            'subject_kind' => 'employee',
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

    public function published(): static
    {
        return $this->state(['status' => 'published']);
    }

    /** `employee`, `candidate` or `none` — what the process is about. */
    public function about(string $subjectKind): static
    {
        return $this->state(['subject_kind' => $subjectKind]);
    }
}
