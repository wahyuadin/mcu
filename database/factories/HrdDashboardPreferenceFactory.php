<?php

namespace Database\Factories;

use App\Models\HrdDashboardPreference;
use App\Models\Perusahaan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HrdDashboardPreference>
 */
class HrdDashboardPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'perusahaan_id' => Perusahaan::factory(),
            'widgets' => null,
        ];
    }
}
