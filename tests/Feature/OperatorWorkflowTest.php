<?php

namespace Tests\Feature;

use App\McuCatalog;
use App\Models\Anamnesa;
use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OperatorWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    public function test_operator_can_render_every_section_of_a_full_package(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $items = array_keys(collect(Paket::getDefaultItemCatalog())->collapse()->all());
        $participant = $this->participant($items);
        foreach (['identitas', 'anamnesa', 'fisik', 'laboratorium', 'penunjang', 'kesimpulan', 'review'] as $tab) {
            $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $tab]))->assertOk();
        }
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'anamnesa']))
            ->assertSee('anamnesa[hazard_bising][answer]', false)->assertSee('anamnesa[keluarga_hepatitis][answer]', false)->assertSee('anamnesa[obat_rutin][answer]', false);
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('DRAFT — BELUM DIFINALISASI');
    }

    public function test_partial_drafts_preserve_other_sections_and_never_default_to_normal(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi', 'tinggi_badan', 'kulit', 'lab_kolesterol']);
        $this->save($participant, 'anamnesa', ['anamnesa' => ['hipertensi' => ['answer' => 'Ya', 'note' => 'Sejak 2023']]]);
        $this->save($participant, 'fisik', ['tinggi_badan' => 172]);
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('Sejak 2023', $exam->anamnesa_data['hipertensi']['note']);
        $this->assertSame('172.00', $exam->tinggi_badan);
        $this->assertNull($exam->kulit);
        $this->assertNull($exam->status_kelaikan);
        $this->assertNull($exam->laboratorium);
        $this->assertSame('proses', $participant->fresh()->status_pemeriksaan);
        $this->assertSame('Draft', $exam->progress($participant)['fisik']['status']);
    }

    public function test_inactive_items_and_hidden_nested_fields_are_not_saved(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi', 'lab_kolesterol']);
        $this->save($participant, 'anamnesa', ['anamnesa' => ['hipertensi' => ['answer' => 'Tidak', 'note' => null, 'admin' => true], 'tbc' => ['answer' => 'Ya']]]);
        $this->save($participant, 'laboratorium', ['laboratorium' => [
            'lab_kolesterol' => ['result' => '228', 'reference' => '< 200', 'unit' => 'mg/dL', 'flag' => 'auto', 'secret' => 'hidden'],
            'lab_hbsag' => ['result' => 'Reaktif'],
        ]]);
        $exam = $participant->fresh()->anamnesa;
        $this->assertArrayNotHasKey('tbc', $exam->anamnesa_data);
        $this->assertArrayNotHasKey('admin', $exam->anamnesa_data['hipertensi']);
        $this->assertArrayNotHasKey('lab_hbsag', $exam->laboratorium);
        $this->assertArrayNotHasKey('secret', $exam->laboratorium['lab_kolesterol']);
        $this->assertSame('Tinggi', $exam->laboratorium['lab_kolesterol']['flag']);
    }

    public function test_incomplete_section_cannot_be_marked_complete_or_finalized(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), [
            'section' => 'anamnesa', 'status' => 'selesai', 'lock_version' => 0,
        ])->assertSessionHasErrors('kelengkapan');
        $this->assertNull($participant->fresh()->anamnesa);
        $this->save($participant, 'anamnesa', ['anamnesa' => ['hipertensi' => ['answer' => 'Tidak']]]);
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['confirmed' => 1, 'lock_version' => 1])
            ->assertSessionHasErrors('kelengkapan');
        $this->assertSame('draft', $participant->fresh()->anamnesa->status);
    }

    public function test_complete_results_can_be_finalized_locked_and_revised_without_losing_the_previous_result(): void
    {
        $user = User::factory()->create(['role' => 'petugas']);
        $this->actingAs($user);
        $participant = $this->participant(['hipertensi', 'tinggi_badan', 'berat_badan', 'bmi', 'lab_kolesterol', 'rontgen']);
        $this->complete($participant);
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame([], $exam->finalizationErrors($participant));
        $this->assertSame('siap_finalisasi', $participant->fresh()->status_pemeriksaan);
        $this->assertSame('28.39', $exam->bmi);
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['lock_version' => $exam->lock_version])
            ->assertSessionHasErrors('confirmed');
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['confirmed' => 1, 'lock_version' => $exam->lock_version])
            ->assertRedirect()->assertSessionHasNoErrors();
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('final', $exam->status);
        $this->assertSame($user->id, $exam->finalized_by);
        $this->assertNotNull($exam->finalized_at);
        $this->assertSame('selesai', $participant->fresh()->status_pemeriksaan);
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), [
            'section' => 'fisik', 'status' => 'draft', 'lock_version' => $exam->lock_version, 'berat_badan' => 90,
        ])->assertForbidden();
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'review']))->assertOk()->assertSee('Hasil final — hanya baca');
        $this->post(route('pemeriksaan.revise', [$participant->rencanaKunjungan, $participant]), ['reason' => 'Koreksi berat dari hasil sumber'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('draft', $exam->status);
        $this->assertSame('84.00', $exam->revisions[0]['snapshot']['berat_badan']);
        $this->assertSame($user->id, $exam->revisions[0]['operator_id']);
        $this->save($participant, 'fisik', ['berat_badan' => 85], 'selesai');
        $this->assertSame('85.00', $participant->fresh()->anamnesa->berat_badan);
        $this->assertSame('84.00', $participant->fresh()->anamnesa->revisions[0]['snapshot']['berat_badan']);
    }

    public function test_stale_edit_is_rejected_without_overwriting_a_newer_draft(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $this->save($participant, 'anamnesa', ['anamnesa' => ['hipertensi' => ['answer' => 'Ya', 'note' => 'Sejak 2023']]]);
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), [
            'section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0,
            'anamnesa' => ['hipertensi' => ['answer' => 'Tidak']],
        ])->assertSessionHasErrors('pemeriksaan');
        $this->assertSame('Ya', $participant->fresh()->anamnesa->historyValue('hipertensi')['answer']);
    }

    public function test_same_patient_can_have_two_independent_mcu_episodes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = $this->participant(['hipertensi']);
        $this->save($first, 'anamnesa', ['anamnesa' => ['hipertensi' => ['answer' => 'Ya', 'note' => 'Hasil pertama']]]);
        $secondPackage = Paket::factory()->create();
        $this->post(route('pasien.store'), [
            'pasien_id' => $first->pasien_id, 'rencana_kunjungan_id' => $secondPackage->rencana_kunjungan_id,
            'paket_id' => $secondPackage->id, 'nip' => 'EMP-002', 'bagian' => 'Keuangan',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $second = Karyawan::where('rencana_kunjungan_id', $secondPackage->rencana_kunjungan_id)->firstOrFail();
        $this->assertSame($first->pasien_id, $second->pasien_id);
        $this->assertSame($first->nama_karyawan, $second->nama_karyawan);
        $this->assertNotSame($first->nomor_mcu, $second->nomor_mcu);
        $this->save($second, 'anamnesa', ['anamnesa' => ['hipertensi' => ['answer' => 'Tidak']]]);
        $this->assertSame('Ya', $first->fresh()->anamnesa->historyValue('hipertensi')['answer']);
        $this->get(route('pasien.show', $second))->assertOk()->assertSee($first->nomor_mcu)->assertSee($second->nomor_mcu);
        $this->post(route('pasien.store'), ['pasien_id' => $first->pasien_id, 'rencana_kunjungan_id' => $secondPackage->rencana_kunjungan_id, 'paket_id' => $secondPackage->id])
            ->assertSessionHasErrors('pasien_id');
        $this->assertSame(2, $first->pasien->episodes()->count());
        $this->get(route('pasien.index'))->assertOk()->assertViewHas('pasiens', fn ($patients): bool => $patients->total() === 1);
    }

    public function test_skipped_tests_require_a_reason_and_do_not_become_normal_results(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['lab_hbsag', 'ekg']);
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), [
            'section' => 'laboratorium', 'status' => 'selesai', 'lock_version' => 0,
            'laboratorium' => ['lab_hbsag' => ['skipped' => 1]],
        ])->assertSessionHasErrors('kelengkapan');
        $this->save($participant, 'laboratorium', ['laboratorium' => ['lab_hbsag' => ['skipped' => 1, 'reason' => 'Sampel tidak tersedia']]], 'selesai');
        $this->save($participant, 'penunjang', ['penunjang' => ['ekg' => ['skipped' => 1, 'reason' => 'Alat tidak tersedia']]], 'selesai');
        $exam = $participant->fresh()->anamnesa;
        $this->assertNull($exam->laboratorium['lab_hbsag']['result']);
        $this->assertNull($exam->laboratorium['lab_hbsag']['flag']);
        $this->assertSame('Selesai', $exam->progress($participant)['laboratorium']['status']);
        $this->assertSame('Selesai', $exam->progress($participant)['penunjang']['status']);
    }

    public function test_medical_images_are_private_and_scoped_to_the_participant(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['rontgen']);
        $this->save($participant, 'penunjang', ['images' => ['rontgen' => UploadedFile::fake()->image('thorax.png')]]);
        $path = $participant->fresh()->anamnesa->penunjang['rontgen']['attachment'];
        Storage::disk('local')->assertExists($path);
        $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'rontgen']))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->participant(['rontgen']);
        $this->get(route('pemeriksaan.attachment', [$other->rencanaKunjungan, $participant, 'rontgen']))->assertNotFound();
        $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'unknown']))->assertNotFound();
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), [
            'section' => 'penunjang', 'status' => 'draft', 'lock_version' => 1,
            'images' => ['rontgen' => UploadedFile::fake()->create('bad.svg', 5, 'image/svg+xml')],
        ])->assertSessionHasErrors('images.rontgen');
        $this->app['auth']->forgetGuards();
        auth()->logout();
        $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'rontgen']))->assertRedirect(route('login'));
    }

    public function test_closing_requires_terminal_statuses_and_blocks_future_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = $this->participant(['hipertensi']);
        $visit = $participant->rencanaKunjungan;
        $this->post(route('rencana-kunjungan.status', $visit), ['status' => 'closed'])->assertSessionHasErrors('status');
        $this->assertFalse($visit->fresh()->is_closing);
        $this->post(route('peserta.status', [$visit, $participant]), ['status' => 'tidak_hadir', 'reason' => 'Tidak datang pada jadwal MCU'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('rencana-kunjungan.status', $visit), ['status' => 'closed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($visit->fresh()->is_closing);
        $this->put(route('pemeriksaan.update', [$visit, $participant]), ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0])->assertForbidden();
        $this->get(route('pasien.edit', $participant))->assertForbidden();
        $this->delete(route('pasien.destroy', $participant))->assertForbidden();
    }

    public function test_package_cannot_change_after_examination_and_bmi_includes_its_inputs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = $this->participant(['hipertensi']);
        $this->save($participant, 'anamnesa', ['anamnesa' => ['hipertensi' => ['answer' => 'Tidak']]]);
        $this->put(route('paket.update', $participant->paket), ['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'nama_paket' => 'Diganti', 'items' => ['tbc']])->assertSessionHasErrors('paket');
        $this->assertTrue($participant->paket->fresh()->isItemActive('hipertensi'));
        $this->post(route('paket.store'), ['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'nama_paket' => 'BMI', 'items' => ['bmi']])->assertRedirect()->assertSessionHasNoErrors();
        $package = Paket::where('nama_paket', 'BMI')->firstOrFail();
        $this->assertTrue($package->isItemActive('tinggi_badan'));
        $this->assertTrue($package->isItemActive('berat_badan'));
    }

    #[TestWith(['admin', true])]
    #[TestWith(['petugas', true])]
    #[TestWith(['dokter', false])]
    #[TestWith(['unknown', false])]
    public function test_examination_gate_allows_only_the_supported_roles(string $role, bool $allowed): void
    {
        $this->assertSame($allowed, Gate::forUser(User::factory()->make(['role' => $role]))->allows('examine-mcu'));
        $this->assertSame($allowed, Gate::forUser(User::factory()->make(['role' => $role]))->allows('view-patients'));
    }

    public function test_guest_and_unsupported_role_cannot_update_results(): void
    {
        $participant = $this->participant(['hipertensi']);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['status' => 'draft'])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'unknown']))->put($url, ['status' => 'draft'])->assertForbidden();
        $this->assertNull($participant->fresh()->anamnesa);
    }

    #[TestWith(['228', '< 200', 'Tinggi'])]
    #[TestWith(['200', '< 200', 'Tinggi'])]
    #[TestWith(['200', '<= 200', 'Normal'])]
    #[TestWith(['38', '> 40', 'Rendah'])]
    #[TestWith(['90', '>= 90', 'Normal'])]
    #[TestWith(['3,7', '3.8 - 10.6', 'Rendah'])]
    #[TestWith(['10.6', '3.8 - 10.6', 'Normal'])]
    #[TestWith(['8 - 10', '0 - 5', null])]
    #[TestWith(['Positif (+1)', 'Negatif', null])]
    #[TestWith(['1', 'invalid', null])]
    public function test_lab_flags_do_not_guess_for_unsupported_references(string $result, string $reference, ?string $expected): void
    {
        $this->assertSame($expected, McuCatalog::laboratoryFlag($result, $reference));
    }

    public function test_a_second_patient_with_multiple_episodes_still_has_its_own_list_entry(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = $this->participant(['hipertensi']);
        $other = $this->participant(['hipertensi']);
        $this->get(route('pasien.index'))->assertOk()->assertSee($first->nama_karyawan)->assertSee($other->nama_karyawan)->assertViewHas('pasiens', fn ($patients): bool => $patients->total() === 2 && $patients->every(fn ($patient): bool => $patient->episodes->count() === 1));
    }

    public function test_medical_values_are_escaped_in_review_and_reports(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $this->save($participant, 'kesimpulan', ['catatan_dokter' => '<script>alert("x")</script>']);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'review']))
            ->assertOk()->assertDontSee('<script>alert("x")</script>', false)->assertSee('&lt;script&gt;', false);
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))
            ->assertOk()->assertDontSee('<script>alert("x")</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_bmi_categories_handle_values_between_decimal_boundaries(): void
    {
        $this->assertSame('Normal', Anamnesa::calculateBmi(200, 91.8)['kategori']);
        $this->assertSame('Kelebihan Berat Badan (Overweight)', Anamnesa::calculateBmi(200, 99.8)['kategori']);
        $this->assertSame('Obesitas I', Anamnesa::calculateBmi(200, 119.8)['kategori']);
    }

    public function test_upgrade_preserves_existing_patient_and_final_medical_results(): void
    {
        $participant = $this->participant(['hipertensi']);
        $participant->anamnesa()->create([
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id,
            'riwayat_penyakit_dahulu' => ['hipertensi' => false],
            'riwayat_penyakit_keluarga' => ['hipertensi' => true],
            'riwayat_kebiasaan' => ['olahraga' => 'Jarang (1x seminggu)'],
            'kulit' => 'Normal', 'status' => 'final', 'catatan_dokter' => 'Hasil lama yang wajib dipertahankan',
        ]);
        $migration = require database_path('migrations/2026_10_09_162014_extend_mcu_operator_workflow.php');
        $birthplaceMigration = require database_path('migrations/2026_10_10_173927_add_tempat_lahir_to_patient_identity_tables.php');
        $birthplaceMigration->down();
        $migration->down();
        $this->assertDatabaseCount('anamnesas', 1);
        $migration->up();
        $birthplaceMigration->up();
        $participant->refresh();
        $this->assertSame($participant->nama_karyawan, $participant->pasien->nama);
        $this->assertNotNull($participant->nomor_mcu);
        $this->assertSame('final', $participant->anamnesa->status);
        $this->assertSame('Normal', $participant->anamnesa->kulit);
        $this->assertSame('Hasil lama yang wajib dipertahankan', $participant->anamnesa->catatan_dokter);
        $this->assertSame('Tidak', $participant->anamnesa->historyValue('hipertensi')['answer']);
        $this->assertSame('Ya', $participant->anamnesa->historyValue('keluarga_hipertensi')['answer']);
        $this->assertSame('Jarang (1x seminggu)', $participant->anamnesa->historyValue('olahraga')['answer']);
    }

    /** @param list<string> $items */
    private function participant(array $items): Karyawan
    {
        $package = Paket::factory()->create(['active_items' => array_fill_keys($items, true)]);

        return Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id]);
    }

    /** @param array<string, mixed> $data */
    private function save(Karyawan $participant, string $section, array $data, string $status = 'draft'): void
    {
        if (! $participant->fresh()->hasStoredPhoto()) {
            $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), ['foto_pasien' => UploadedFile::fake()->image('foto.png')])->assertSessionHasNoErrors();
        }
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), [
            ...$data, 'section' => $section, 'status' => $status, 'lock_version' => $participant->fresh()->anamnesa?->lock_version ?? 0,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function complete(Karyawan $participant): void
    {
        $doctor = Dokter::factory()->create();
        $this->save($participant, 'anamnesa', ['anamnesa' => ['hipertensi' => ['answer' => 'Ya', 'note' => 'Sejak 2023']]], 'selesai');
        $this->save($participant, 'fisik', ['tinggi_badan' => 172, 'berat_badan' => 84, 'dokter_fisik_id' => $doctor->id], 'selesai');
        $this->save($participant, 'laboratorium', ['laboratorium' => ['lab_kolesterol' => ['result' => '228', 'unit' => 'mg/dL', 'reference' => '< 200', 'flag' => 'auto']]], 'selesai');
        $this->save($participant, 'penunjang', ['penunjang' => ['rontgen' => ['jantung' => 'CTR < 50%', 'aorta' => 'Normal', 'paru' => 'Normal', 'tulang' => 'Normal', 'kesimpulan' => 'Normal Chest', 'doctor_id' => $doctor->id]]], 'selesai');
        $this->save($participant, 'kesimpulan', ['dokter_id' => $doctor->id, 'status_kelaikan' => 'Fit with Note', 'catatan_dokter' => 'Dislipidemia', 'anjuran_saran' => 'Kontrol berkala'], 'selesai');
    }
}
