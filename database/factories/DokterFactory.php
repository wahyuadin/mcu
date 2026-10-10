<?php

namespace Database\Factories;

use App\Models\Dokter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dokter>
 */
class DokterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama_dokter' => 'dr. '.fake()->name(),
            'no_str' => fake()->unique()->numerify('STR-######'),
            'spesialisasi' => 'Dokter MCU',
        ];
    }
}
