<?php

namespace Tests\Feature;

use App\McuCatalog;
use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class McuReportLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_omits_unselected_items_and_keeps_reference_numbers_and_both_identifiers(): void
    {
        $package = Paket::factory()->create(['active_items' => ['bmi' => true, 'ronchi' => true]]);
        $participant = Karyawan::factory()->create(['paket_id' => $package->id, 'nik' => '1234567890123456', 'nip' => 'EMP-001']);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => $package->active_items, 'bmi' => 22.49, 'kategori_bmi' => 'Normal', 'ronchi' => 'Negatif']);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]));
        $response->assertOk()->assertSee('NIK KTP')->assertSee('NIP / No. Karyawan')->assertSee('1234567890123456')->assertSee('EMP-001')
            ->assertDontSee('data-item-key="tinggi_badan"', false)->assertDontSee('ANAMNESA PASIEN')->assertDontSee('HASIL PEMERIKSAAN LABORATORIUM')->assertDontSee('HASIL PEMERIKSAAN EKG');
        $sections = $response->viewData('sections');
        $this->assertCount(1, $sections);
        $rows = collect($sections[0]['groups'])->pluck('rows')->flatten(1);
        $this->assertSame([3, 4, 25], $rows->pluck('number')->all());
        $this->assertSame([0, 1], array_column($sections[0]['groups'], 'column'));
    }

    public function test_complete_physical_report_uses_the_reference_two_column_split(): void
    {
        $items = collect(McuCatalog::physical())->collapse()->keys()->all();
        $package = Paket::factory()->create(['active_items' => array_fill_keys($items, true)]);
        $participant = Karyawan::factory()->create(['paket_id' => $package->id]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk();
        $groups = collect($response->viewData('sections')[0]['groups']);
        $this->assertSame(range(1, 20), $groups->where('column', 0)->pluck('rows')->flatten(1)->pluck('number')->all());
        $this->assertSame(range(21, 44), $groups->where('column', 1)->pluck('rows')->flatten(1)->pluck('number')->all());
    }

    public function test_master_snapshot_controls_lab_columns_and_mapping_instead_of_live_master(): void
    {
        $item = McuItem::factory()->create(['input_type' => 'number', 'label' => 'Hemoglobin', 'lab_config' => ['result_type' => 'number', 'rule' => 'range', 'min' => 12, 'max' => 16, 'reference' => '12 - 16', 'unit' => 'g/dL', 'normal_report' => '', 'abnormal_report' => 'Tinggi']]);
        $schema = [['id' => $item->category->id, 'name' => 'LAB', 'order' => 0, 'items' => [$item->definition()]]];
        $package = Paket::factory()->create(['active_items' => [], 'dynamic_schema' => $schema]);
        $participant = Karyawan::factory()->create(['paket_id' => $package->id]);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => [], 'dynamic_schema' => $schema, 'dynamic_answers' => [$item->id => '18']]);
        $item->update(['label' => 'Label baru', 'lab_config' => null]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]));
        $response->assertOk()->assertSeeInOrder(['Pemeriksaan', 'Hasil', 'Unit', 'Nilai Normal', 'Keterangan'])->assertSee('Hemoglobin')->assertSee('g/dL')->assertSee('12 - 16')->assertSee('Tinggi')->assertDontSee('Label baru');
        $this->assertSame('laboratory', $response->viewData('sections')[0]['kind']);
    }

    public function test_report_escapes_long_results_and_keeps_only_historical_snapshot_categories(): void
    {
        $item = McuItem::factory()->create(['input_type' => 'text', 'label' => 'Kesimpulan']);
        $schema = [['id' => $item->category->id, 'name' => 'HASIL PEMERIKSAAN EKG', 'order' => 0, 'items' => [$item->definition()]]];
        $package = Paket::factory()->create(['active_items' => ['tbc' => true], 'dynamic_schema' => []]);
        $participant = Karyawan::factory()->create(['paket_id' => $package->id]);
        $text = str_repeat('Catatan pemeriksaan panjang. ', 60).'<script>alert(1)</script>';
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'revisions' => [['reason' => 'Koreksi', 'at' => now()->toIso8601String(), 'snapshot' => ['package_items' => [], 'dynamic_schema' => $schema, 'dynamic_answers' => [$item->id => $text]]]]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('pemeriksaan.laporan-riwayat.version', [$participant->rencanaKunjungan, $participant, 1]))
            ->assertOk()->assertSee('HASIL PEMERIKSAAN EKG')->assertSee($text)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('ANAMNESA PASIEN');
    }

    public function test_guest_and_mismatched_visit_cannot_access_report(): void
    {
        $participant = Karyawan::factory()->create();
        $url = route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]);
        $this->get($url)->assertRedirect(route('login'));
        $other = Karyawan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('pemeriksaan.report', [$other->rencanaKunjungan, $participant]))->assertNotFound();
    }

    public function test_laboratory_preserves_reference_breaks_without_adding_unselected_groups(): void
    {
        $package = Paket::factory()->create(['active_items' => ['lab_sgot' => true, 'lab_sgpt' => true, 'lab_ureum' => true, 'lab_warna_urine' => true]]);
        $participant = Karyawan::factory()->create(['paket_id' => $package->id]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]));

        $response->assertOk()->assertDontSee('HEMATOLOGI')->assertDontSee('DRUG TEST');
        $groups = $response->viewData('sections')[0]['groups'];
        $this->assertSame([0, 1, 1, 2], array_column($groups, 'reference_page'));
        $this->assertSame(['lab_sgot', 'lab_sgpt', 'lab_ureum', 'lab_warna_urine'], collect($groups)->pluck('rows')->flatten(1)->pluck('key')->all());
    }

    public function test_historical_identity_and_absent_photo_do_not_use_current_values_or_mutate_patient(): void
    {
        $participant = Karyawan::factory()->create(['nama_karyawan' => 'Nama terbaru', 'nik' => '2222222222222222', 'nip' => 'BARU', 'foto' => 'mcu/current/foto.jpg']);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'revisions' => [[
            'reason' => 'Koreksi', 'snapshot' => ['package_items' => [], 'dynamic_schema' => []],
            'episode' => ['nama_karyawan' => 'Nama sebelumnya', 'nik' => '1111111111111111', 'nip' => 'LAMA', 'foto' => null,
                'perusahaan' => ['nama_perusahaan' => 'Perusahaan sebelumnya'], 'paket' => ['nama_paket' => 'Paket sebelumnya']],
        ]]]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('pemeriksaan.laporan-riwayat.version', [$participant->rencanaKunjungan, $participant, 1]));

        $response->assertOk()->assertSee('Nama sebelumnya')->assertSee('1111111111111111')->assertSee('LAMA')->assertSee('Perusahaan sebelumnya')->assertSee('Paket sebelumnya')->assertSee('Foto tidak')
            ->assertDontSee('Nama terbaru')->assertDontSee('2222222222222222')->assertDontSee('ANAMNESA PASIEN');
        $this->assertDatabaseHas('karyawans', ['id' => $participant->id, 'nama_karyawan' => 'Nama terbaru', 'nip' => 'BARU']);
    }

    public function test_historical_photo_and_supporting_attachment_return_versioned_files_and_reject_missing_versions(): void
    {
        Storage::fake('local');
        $participant = Karyawan::factory()->create();
        $oldPhoto = 'mcu/'.$participant->id.'/foto/old.jpg';
        $newPhoto = 'mcu/'.$participant->id.'/foto/new.jpg';
        $oldResult = 'mcu/'.$participant->id.'/rontgen/old.jpg';
        $newResult = 'mcu/'.$participant->id.'/rontgen/new.jpg';
        foreach ([$oldPhoto => 'old-photo', $newPhoto => 'new-photo', $oldResult => 'old-result', $newResult => 'new-result'] as $path => $content) {
            Storage::disk('local')->put($path, $content);
        }
        $participant->update(['foto' => $newPhoto]);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'penunjang' => ['rontgen' => ['attachment' => $newResult]], 'revisions' => [
            ['snapshot' => ['penunjang' => ['rontgen' => ['attachment' => $oldResult]]], 'episode' => ['foto' => $oldPhoto]],
            ['snapshot' => [], 'episode' => ['foto' => null]],
        ]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $photo = $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'foto', 'version' => 1]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $result = $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'rontgen', 'version' => 1]))->assertOk();

        $this->assertSame('old-photo', $photo->streamedContent());
        $this->assertSame('old-result', $result->streamedContent());
        $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'foto', 'version' => 2]))->assertNotFound();
        $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'rontgen', 'version' => 3]))->assertNotFound();
        Storage::disk('local')->assertExists([$oldPhoto, $oldResult]);
    }

    public function test_reopening_report_preserves_company_and_package_names_for_historical_rendering(): void
    {
        $participant = Karyawan::factory()->create();
        $doctor = Dokter::factory()->create(['nama_dokter' => 'Dokter saat penerbitan']);
        $companyName = $participant->perusahaan->nama_perusahaan;
        $packageName = $participant->paket->nama_paket;
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => [], 'dokter_id' => $doctor->id]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('pemeriksaan.revise', [$participant->rencanaKunjungan, $participant]), ['reason' => 'Koreksi hasil'])->assertSessionHasNoErrors();

        $revision = $participant->fresh()->anamnesa->revisions[0];
        $this->assertSame($companyName, $revision['episode']['perusahaan']['nama_perusahaan']);
        $this->assertSame($packageName, $revision['episode']['paket']['nama_paket']);
        $this->assertSame('Dokter saat penerbitan', $revision['doctors'][$doctor->id]);
        $this->assertDatabaseHas('anamnesas', ['karyawan_id' => $participant->id, 'status' => 'draft']);
        $participant->perusahaan->update(['nama_perusahaan' => 'Perusahaan setelah revisi']);
        $participant->paket->update(['nama_paket' => 'Paket setelah revisi']);
        $doctor->update(['nama_dokter' => 'Dokter setelah revisi']);
        $this->get(route('pemeriksaan.laporan-riwayat.version', [$participant->rencanaKunjungan, $participant, 1]))->assertOk()->assertSee($companyName)->assertSee($packageName)->assertSee('Dokter saat penerbitan')->assertDontSee('Perusahaan setelah revisi')->assertDontSee('Paket setelah revisi')->assertDontSee('Dokter setelah revisi');
    }

    public function test_report_keeps_the_name_of_a_soft_deleted_selected_doctor(): void
    {
        $doctor = Dokter::factory()->create(['nama_dokter' => 'Dokter pemeriksa sebelumnya']);
        $participant = Karyawan::factory()->create();
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'dokter_id' => $doctor->id]);
        $doctor->delete();

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Dokter pemeriksa sebelumnya');
    }
}
