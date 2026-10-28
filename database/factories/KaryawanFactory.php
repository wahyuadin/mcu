<?php

namespace Database\Factories;

use App\Models\Karyawan;
use App\Models\Paket;
use App\PatientPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;

/**
 * @extends Factory<Karyawan>
 */
class KaryawanFactory extends Factory
{
    public function withPhoto(): static
    {
        return $this->afterCreating(function (Karyawan $participant): void {
            $path = (new PatientPhoto)->store(UploadedFile::fake()->image('foto.png', 100, 100), $participant);
            $participant->update(['foto' => $path]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paket_id' => Paket::factory(),
            'rencana_kunjungan_id' => fn (array $attributes): string => Paket::findOrFail($attributes['paket_id'])->rencana_kunjungan_id,
            'perusahaan_id' => fn (array $attributes): string => Paket::findOrFail($attributes['paket_id'])->perusahaan_id,
            'nama_karyawan' => fake()->name(),
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '1988-04-17',
            'nip' => fake()->bothify('EMP-#####'),
            'bagian' => 'Produksi',
            'tanggal_mcu' => '2026-10-12',
        ];
    }
}
