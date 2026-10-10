<?php

namespace Database\Factories;

use App\Models\McuCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McuCategory>
 */
class McuCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true), 'is_active' => true, 'sort_order' => 0,
        ];
    }
}
