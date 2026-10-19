<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Школа',
            'short_name' => 'МАОУ СОШ № '.fake()->numberBetween(1, 200),
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => fake()->numerify('##########'),
            'settings' => [],
        ];
    }
}
