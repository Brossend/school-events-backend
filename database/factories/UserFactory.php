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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::uuid().'@example.test',
            'password' => static::$password ??= Hash::make('password'),
            'system_role' => 'none',
            'curator_scope' => null,
            'curated_region_codes' => [],
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Inactive user state.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * System administrator state.
     */
    public function systemAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'system_role' => 'system_admin',
        ]);
    }

    /**
     * Catalog curator state.
     */
    public function curator(string $scope = 'global', array $regions = []): static
    {
        return $this->state(fn (array $attributes) => [
            'system_role' => 'catalog_curator',
            'curator_scope' => $scope,
            'curated_region_codes' => $regions,
        ]);
    }
}
