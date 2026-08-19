<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Operators are admins; the migration defaults the column to 'admin'
            // for exactly that reason. Set it on the model too so a freshly-made
            // factory user is admin IN MEMORY (create() does not reload the
            // DB-applied default), otherwise every admin-gated action is hidden
            // under test. Auditor tests set ['role' => UserRole::Auditor] to override.
            'role' => UserRole::Admin,
        ];
    }

    /**
     * A read-only Auditor account.
     */
    public function auditor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Auditor,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
