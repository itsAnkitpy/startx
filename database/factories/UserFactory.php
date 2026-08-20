<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'work_email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A person named as a whole, for tests that care who somebody is rather than which
     * part of their name is which.
     */
    public function named(string $fullName): static
    {
        [$first, $last] = array_pad(explode(' ', $fullName, 2), 2, null);

        return $this->state(['first_name' => $first, 'last_name' => $last]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    /**
     * A leaver, past their last working day. Their record stays readable and their work
     * address stays theirs — a rehire adds employment rows to this same account.
     */
    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
