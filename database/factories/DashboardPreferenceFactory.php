<?php

namespace Database\Factories;

use App\Models\DashboardPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardPreference>
 */
class DashboardPreferenceFactory extends Factory
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
            'role' => 'admin',
            'widgets' => null,
        ];
    }
}
