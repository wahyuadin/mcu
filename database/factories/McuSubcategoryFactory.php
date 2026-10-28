<?php

namespace Database\Factories;

use App\Models\McuCategory;
use App\Models\McuSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McuSubcategory>
 */
class McuSubcategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mcu_category_id' => McuCategory::factory(),
            'name' => fake()->words(2, true),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
