<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReusableMcuPackageMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_backfills_old_binding_without_modifying_participants_or_medical_snapshots(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => ['hipertensi' => true]]);
        $before = DB::table('karyawans')->where('id', $participant->id)->first();
        $beforeExam = DB::table('anamnesas')->where('id', $exam->id)->first();
        $migration = require database_path('migrations/2026_10_10_024721_make_mcu_packages_reusable.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('paket_rencana_kunjungan', ['paket_id' => $participant->paket_id, 'rencana_kunjungan_id' => $participant->rencana_kunjungan_id]);
        $this->assertEquals($before, DB::table('karyawans')->where('id', $participant->id)->first());
        $this->assertEquals($beforeExam, DB::table('anamnesas')->where('id', $exam->id)->first());
        $this->assertSame('final', $exam->fresh()->status);
        $this->assertSame(['hipertensi' => true], $exam->fresh()->package_items);
    }
}
