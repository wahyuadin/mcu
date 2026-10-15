<?php

namespace Database\Factories;

use App\Models\McuExport;
use App\Models\Perusahaan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McuExport>
 */
class McuExportFactory extends Factory
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
            'status' => 'queued',
            'manifest' => [],
            'total' => 0,
            'processed' => 0,
            'expires_at' => now()->addDay(),
        ];
    }
}
