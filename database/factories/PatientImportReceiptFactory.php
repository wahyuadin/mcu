<?php

namespace Database\Factories;

use App\Models\Karyawan;
use App\Models\PatientImportReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientImportReceipt>
 */
class PatientImportReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fingerprint' => hash('sha256', fake()->uuid()),
            'karyawan_id' => Karyawan::factory(),
        ];
    }
}
