<?php

namespace Database\Factories;

use App\Models\McuCategory;
use App\Models\McuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McuItem>
 */
class McuItemFactory extends Factory
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
            'label' => 'Hepatitis', 'input_type' => 'radio', 'yes_report' => 'Ada riwayat Hepatitis',
            'no_report' => 'Normal', 'is_required' => true, 'is_active' => true, 'sort_order' => 0,
        ];
    }
}
