<?php

namespace Database\Factories;

use App\Models\PatientImportBatch;
use App\Models\User;
use App\PatientSpreadsheet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientImportBatch>
 */
class PatientImportBatchFactory extends Factory
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
            'file_name' => 'pasien.xlsx',
            'status' => 'preview',
            'headers' => PatientSpreadsheet::HEADERS,
            'source_rows' => [],
            'mapping' => array_combine(PatientSpreadsheet::HEADERS, PatientSpreadsheet::HEADERS),
            'expires_at' => now()->addDay(),
        ];
    }
}
