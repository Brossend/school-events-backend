<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolMembership>
 */
class SchoolMembershipFactory extends Factory
{
    protected $model = SchoolMembership::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'user_id' => User::factory(),
            'role' => 'teacher',
            'is_default' => false,
        ];
    }

    /**
     * Default school state.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * School administrator state.
     */
    public function schoolAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'school_admin',
        ]);
    }

    /**
     * Teacher state.
     */
    public function teacher(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'teacher',
        ]);
    }
}
