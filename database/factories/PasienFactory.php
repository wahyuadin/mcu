<?php

namespace Database\Factories;

use App\Models\Pasien;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pasien>
 */
class PasienFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama' => fake()->name(),
            'nik' => fake()->numerify('################'),
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '1988-04-17',
        ];
    }
}
