<?php

namespace Database\Factories;

use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RencanaKunjungan>
 */
class RencanaKunjunganFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'perusahaan_id' => Perusahaan::factory(),
            'nomor_kunjungan' => fake()->unique()->bothify('MCU-####-????'),
            'periode_kunjungan' => 'Oktober 2026',
            'kuota_peserta' => 100,
        ];
    }
}
