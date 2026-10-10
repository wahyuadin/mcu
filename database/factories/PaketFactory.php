<?php

namespace Database\Factories;

use App\Models\Paket;
use App\Models\RencanaKunjungan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paket>
 */
class PaketFactory extends Factory
{
    public function master(): static
    {
        return $this->state(['rencana_kunjungan_id' => null, 'perusahaan_id' => null]);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rencana_kunjungan_id' => RencanaKunjungan::factory(),
            'perusahaan_id' => fn (array $attributes): string => RencanaKunjungan::findOrFail($attributes['rencana_kunjungan_id'])->perusahaan_id,
            'nama_paket' => 'Paket MCU',
            'active_items' => ['hipertensi' => true],
        ];
    }
}
