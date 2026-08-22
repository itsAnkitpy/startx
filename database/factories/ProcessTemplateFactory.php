<?php

namespace Database\Factories;

use App\Models\ProcessStep;
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

    /**
     * A live process, made live the way the product makes one: a draft with a step in
     * it, then published.
     *
     * Rewritten on 22 August 2026 reviewing step 2. Setting the status straight to
     * published produced a live process with no steps at all — a shape publishing itself
     * now refuses — so every case built on this state was running against something the
     * product can never produce.
     */
    public function published(): static
    {
        return $this->afterCreating(function (ProcessTemplate $template): void {
            ProcessStep::factory()->of($template)->at(1, 1)->create();

            $template->publish();
        });
    }

    /** `employee`, `candidate` or `none` — what the process is about. */
    public function about(string $subjectKind): static
    {
        return $this->state(['subject_kind' => $subjectKind]);
    }
}
