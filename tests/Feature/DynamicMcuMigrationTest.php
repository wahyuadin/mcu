<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DynamicMcuMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_dynamic_schema_upgrade_preserves_legacy_participant_package_and_final_results(): void
    {
        $participant = Karyawan::factory()->create();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'anamnesa_data' => ['hipertensi' => ['answer' => 'Tidak']], 'bmi' => 22.49]);
        $migration = require database_path('migrations/2026_10_10_142409_create_dynamic_mcu_form_masters.php');
        $laboratoryMigration = require database_path('migrations/2026_10_11_163019_add_laboratory_configuration_and_subcategories.php');
        $attachmentMigration = require database_path('migrations/2026_10_12_092445_add_optional_item_attachments.php');
        $doctorMigration = require database_path('migrations/2026_10_12_095742_add_category_doctors_to_mcu_examinations.php');
        $doctorMigration->down();
        $attachmentMigration->down();
        $laboratoryMigration->down();
        $migration->down();
        $beforeExam = (array) DB::table('anamnesas')->where('id', $exam->id)->first();
        $beforeParticipant = (array) DB::table('karyawans')->where('id', $participant->id)->first();
        $beforePackage = (array) DB::table('pakets')->where('id', $participant->paket_id)->first();

        $migration->up();
        $laboratoryMigration->up();
        $attachmentMigration->up();
        $doctorMigration->up();

        $afterExam = (array) DB::table('anamnesas')->where('id', $exam->id)->first();
        unset($afterExam['dynamic_schema'], $afterExam['dynamic_answers'], $afterExam['dynamic_attachments'], $afterExam['category_doctors']);
        $afterPackage = (array) DB::table('pakets')->where('id', $participant->paket_id)->first();
        unset($afterPackage['dynamic_schema']);
        $this->assertSame($beforeExam, $afterExam);
        $this->assertSame($beforePackage, $afterPackage);
        $this->assertSame($beforeParticipant, (array) DB::table('karyawans')->where('id', $participant->id)->first());
        $this->assertSame([], $exam->fresh()->dynamicGroups($participant));
    }
}
