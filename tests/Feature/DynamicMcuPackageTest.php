<?php

namespace Tests\Feature;

use App\Models\Anamnesa;
use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\McuSubcategory;
use App\Models\Paket;
use App\Models\User;
use Database\Seeders\McuTemplateMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicMcuPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_category_and_radio_mapping_and_selects_it_in_package(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('mcu-category.create'))->assertOk();
        $this->post(route('mcu-category.store'), ['name' => 'Riwayat sebelumnya', 'sort_order' => 2, 'is_active' => 1])->assertSessionHasNoErrors();
        $category = McuCategory::where('name', 'Riwayat sebelumnya')->firstOrFail();
        $this->get(route('mcu-item.create', ['category' => $category->id]))->assertOk()->assertSee('Mapping hasil laporan');
        $this->post(route('mcu-item.store'), $this->itemPayload($category))->assertSessionHasNoErrors();
        $item = $category->items()->firstOrFail();
        $this->get(route('paket.create'))->assertOk()->assertSee('Riwayat sebelumnya')->assertSee('Hepatitis')->assertSee('TIDAK → Normal')
            ->assertSee('Anamnesis')->assertSee('Hasil Pemeriksaan Fisik')->assertDontSee('Anamnesa Riwayat')->assertDontSee('Antropometri Vital');
        $this->post(route('paket.store'), ['nama_paket' => 'Paket dinamis', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $package = Paket::firstOrFail();
        $this->assertSame([], $package->active_items);
        $this->assertSame('Riwayat sebelumnya', $package->dynamic_schema[0]['name']);
        $this->assertSame('Normal', $package->dynamic_schema[0]['items'][0]['no_report']);
        $this->get(route('paket.show', $package))->assertOk()->assertSee('Paket dinamis')->assertSee('Hepatitis');
        $this->get(route('paket.edit', $package))->assertOk();
        $this->get(route('mcu-category.index'))->assertOk()->assertSee('Riwayat sebelumnya')
            ->assertSee('aria-label="Daftar kategori MCU"', false)->assertSee('Jumlah item');
        $this->get(route('mcu-item.index'))->assertOk()->assertSee('Hepatitis')
            ->assertSee('aria-label="Daftar item MCU"', false)->assertSee('Mapping laporan')->assertSee('Normal');
        $this->get(route('mcu-category.edit', $category))->assertOk();
        $this->get(route('mcu-item.edit', $item))->assertOk();
    }

    public function test_master_requires_both_radio_mappings_and_does_not_accept_existing_type_for_new_item(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        $payload = $this->itemPayload($category);
        unset($payload['no_report']);
        $this->post(route('mcu-item.store'), $payload)->assertSessionHasErrors('no_report');
        $this->assertSame(0, $category->items()->count());
        $payload['input_type'] = 'existing';
        $this->post(route('mcu-item.store'), $payload)->assertSessionHasErrors('input_type');
        $this->assertSame(0, $category->items()->count());
    }

    public function test_non_admin_cannot_configure_masters_and_inactive_items_cannot_be_selected(): void
    {
        $this->get(route('mcu-category.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->get(route('mcu-category.index'))->assertForbidden();
        $this->post(route('mcu-category.store'), ['name' => 'Ditolak'])->assertForbidden();
        $this->post(route('mcu-item.store'), [])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $item = McuItem::factory()->create(['is_active' => false]);
        $this->post(route('paket.store'), ['nama_paket' => 'Ditolak', 'master_items' => [$item->id]])->assertSessionHasErrors('master_items.0');
        $item->update(['is_active' => true]);
        $item->category->update(['is_active' => false]);
        $this->post(route('paket.store'), ['nama_paket' => 'Ditolak', 'master_items' => [$item->id]])->assertSessionHasErrors('master_items');
        $this->assertDatabaseCount('pakets', 0);
    }

    public function test_unanswered_radio_is_not_normal_and_required_answers_block_completion(): void
    {
        [$participant, $item] = $this->dynamicParticipant();
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$item->mcu_category_id]))->assertOk()->assertSee('Hepatitis')->assertDontSee('checked', false);
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Belum diisi')->assertDontSee('Ada riwayat Hepatitis');
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 0])->assertSessionHasErrors('kelengkapan');
        $this->assertDatabaseCount('anamnesas', 0);
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), ['section' => 'dinamis', 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => [$item->id => 'MUNGKIN']])->assertSessionHasErrors('dynamic_answers.'.$item->id);
        $this->assertDatabaseCount('anamnesas', 0);
    }

    public function test_no_and_yes_answers_render_mapped_results_and_master_edits_do_not_change_snapshot(): void
    {
        [$participant, $item] = $this->dynamicParticipant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $report = route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => 'TIDAK']])->assertSessionHasNoErrors();
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('TIDAK', $exam->dynamic_answers[$item->id]);
        $this->assertSame('Selesai', $exam->progress($participant)['dinamis']['status']);
        $this->get($report)->assertOk()->assertSee('Normal')->assertDontSee('Ada riwayat Hepatitis');
        $payload = $this->itemPayload($item->category);
        $payload['no_report'] = 'Mapping baru';
        $payload['yes_report'] = 'Temuan baru';
        $this->put(route('mcu-item.update', $item), $payload)->assertSessionHasNoErrors();
        $this->put(route('mcu-category.update', $item->category), ['name' => 'Kategori baru', 'sort_order' => 1, 'is_active' => 0])->assertSessionHasNoErrors();
        $this->get($report)->assertOk()->assertSee('Normal')->assertDontSee('Mapping baru')->assertSee('Kategori baru');
        $this->put($url, ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 1, 'dynamic_answers' => [$item->id => 'YA']])->assertSessionHasNoErrors();
        $this->get($report)->assertOk()->assertSee('Ada riwayat Hepatitis')->assertDontSee('Temuan baru');
        $this->put($url, ['section' => 'dinamis', 'status' => 'draft', 'lock_version' => 1, 'dynamic_answers' => [$item->id => 'TIDAK']])->assertSessionHasErrors('pemeriksaan');
        $this->assertSame('YA', $exam->fresh()->dynamic_answers[$item->id]);
        $this->get(route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee($exam->dynamic_schema[0]['name']);
    }

    public function test_only_package_answers_are_allowed_and_label_text_and_zero_are_supported(): void
    {
        [$participant, $radio] = $this->dynamicParticipant();
        $category = $radio->category;
        $text = McuItem::factory()->create(['mcu_category_id' => $category->id, 'input_type' => 'text', 'label' => 'Keterangan']);
        $number = McuItem::factory()->create(['mcu_category_id' => $category->id, 'input_type' => 'number', 'label' => 'Jumlah']);
        $label = McuItem::factory()->create(['mcu_category_id' => $category->id, 'input_type' => 'label', 'label' => 'Informasi', 'help_text' => 'Petunjuk aman']);
        $participant->paket->update(['dynamic_schema' => [['id' => $category->id, 'name' => $category->name, 'order' => 0, 'items' => [$radio->definition(), $text->definition(), $number->definition(), $label->definition()]]]]);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'dinamis', 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => ['unknown' => 'YA']])->assertSessionHasErrors('dynamic_answers');
        $this->assertDatabaseCount('anamnesas', 0);
        $this->put($url, ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$radio->id => 'TIDAK', $text->id => '<script>alert(1)</script>', $number->id => '0']])->assertSessionHasNoErrors();
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)->assertSee('Petunjuk aman');
        $this->assertSame('0', $participant->fresh()->anamnesa->dynamic_answers[$number->id]);
    }

    public function test_final_exam_is_locked_and_revision_preserves_dynamic_report_version(): void
    {
        [$participant, $item] = $this->dynamicParticipant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => 'TIDAK']])->assertSessionHasNoErrors();
        $doctor = Dokter::factory()->create(['is_active' => true]);
        $this->put($url, ['section' => 'kesimpulan', 'status' => 'selesai', 'lock_version' => 1, 'dokter_id' => $doctor->id, 'catatan_dokter' => 'Kesimpulan dokter', 'anjuran_saran' => 'Saran dokter', 'status_kelaikan' => 'Fit to Work'])->assertSessionHasNoErrors();
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['confirmed' => 1, 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame('final', $participant->fresh()->anamnesa->status);
        $this->put($url, ['section' => 'dinamis', 'status' => 'draft', 'lock_version' => 3, 'dynamic_answers' => [$item->id => 'YA']])->assertForbidden();
        $this->post(route('pemeriksaan.revise', [$participant->rencanaKunjungan, $participant]), ['reason' => 'Koreksi hasil sumber'])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 4, 'dynamic_answers' => [$item->id => 'YA']])->assertSessionHasNoErrors();
        $this->get(route('pemeriksaan.laporan-riwayat.version', [$participant->rencanaKunjungan, $participant, 1]))->assertOk()->assertSee('Normal')->assertDontSee('Ada riwayat Hepatitis');
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Ada riwayat Hepatitis');
    }

    public function test_finalization_rejects_missing_dynamic_answer_even_when_conclusion_is_complete(): void
    {
        [$participant, $item] = $this->dynamicParticipant();
        $doctor = Dokter::factory()->create(['is_active' => true]);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'kesimpulan', 'status' => 'selesai', 'lock_version' => 0, 'dokter_id' => $doctor->id, 'catatan_dokter' => 'Kesimpulan dokter', 'anjuran_saran' => 'Saran dokter', 'status_kelaikan' => 'Fit to Work'])->assertSessionHasNoErrors();
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['confirmed' => 1, 'lock_version' => 1])->assertSessionHasErrors('kelengkapan');
        $this->assertSame('draft', $participant->fresh()->anamnesa->status);
        $this->assertNull($participant->fresh()->anamnesa->finalized_at);
        $this->assertContains($item->category->name.' — Hepatitis', $participant->fresh()->anamnesa->progress($participant)['dinamis']['missing']);
    }

    public function test_template_master_import_is_idempotent_and_does_not_change_packages_or_bmi(): void
    {
        $package = Paket::factory()->create(['active_items' => ['bmi' => true, 'tinggi_badan' => true, 'berat_badan' => true]]);
        $this->seed(McuTemplateMasterSeeder::class);
        $count = McuItem::count();
        $item = McuItem::where('label', 'Irama')->firstOrFail();
        $item->update(['label' => 'Irama diperbarui']);
        $this->seed(McuTemplateMasterSeeder::class);
        $this->assertSame($count, McuItem::count());
        $this->assertSame('Irama', $item->fresh()->label);
        $this->assertSame('text', $item->input_type);
        $this->assertSame(0, McuItem::where('legacy_key', 'bmi')->count());
        $this->assertSame(1, McuItem::where('legacy_key', 'tinggi_badan')->count());
        $this->assertSame(1, McuItem::where('legacy_key', 'keluhan_saat_ini')->count());
        $this->assertSame(['bmi' => true, 'tinggi_badan' => true, 'berat_badan' => true], $package->fresh()->active_items);
        $this->assertSame(22.49, Anamnesa::calculateBmi(170, 65)['bmi']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('paket.create'))->assertOk()->assertSee('BMI &amp; Kategori BMI', false)->assertSee('Irama');
        $this->post(route('paket.store'), ['nama_paket' => 'Paket existing', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $existing = Paket::where('nama_paket', 'Paket existing')->firstOrFail();
        $this->assertSame([], $existing->active_items);
        $this->assertSame('text', $existing->dynamic_schema[0]['items'][0]['type']);
        $this->put(route('mcu-item.update', $item), array_replace($this->itemPayload($item->category), ['label' => 'Irama', 'yes_report' => 'Ada kelainan', 'no_report' => 'Tidak ada kelainan']))->assertSessionHasNoErrors();
        $this->post(route('paket.store'), ['nama_paket' => 'Paket radio baru', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $new = Paket::where('nama_paket', 'Paket radio baru')->firstOrFail();
        $this->assertSame([], $new->active_items);
        $this->assertSame('radio', $new->dynamic_schema[0]['items'][0]['type']);
        $this->assertSame('text', $existing->fresh()->dynamic_schema[0]['items'][0]['type']);
    }

    public function test_legacy_labels_remain_readable_but_cannot_be_created_or_selected_in_new_packages(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        $this->post(route('mcu-item.store'), array_replace($this->itemPayload($category), ['input_type' => 'label', 'help_text' => 'Informasi pasien']))->assertSessionHasErrors('input_type');
        $label = McuItem::factory()->create(['input_type' => 'label', 'help_text' => 'Informasi pasien', 'is_required' => false, 'yes_report' => null, 'no_report' => null]);
        $this->assertFalse($label->is_required);
        $this->assertNull($label->yes_report);
        $this->assertNull($label->no_report);
        $this->post(route('paket.store'), ['nama_paket' => 'Paket label', 'master_items' => [$label->id]])->assertSessionHasErrors('master_items.0');
        $participant = Karyawan::factory()->withPhoto()->create();
        $package = Paket::factory()->master()->create(['dynamic_schema' => [['id' => $category->id, 'name' => $category->name, 'order' => 0, 'items' => [$label->definition()]]]]);
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$label->id => 'YA']])->assertSessionHasErrors('dynamic_answers');
        $this->put($url, ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Selesai', $participant->fresh()->anamnesa->progress($participant)['dinamis']['status']);
    }

    public function test_dynamic_form_cannot_bypass_photo_or_closed_visit(): void
    {
        [$participant, $item] = $this->dynamicParticipant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $payload = ['section' => 'dinamis', 'status' => 'draft', 'lock_version' => 0];
        $participant->update(['foto' => null]);
        $this->put($url, $payload)->assertSessionHasErrors('foto_pasien');
        $this->assertDatabaseCount('anamnesas', 0);
        $participant->rencanaKunjungan->update(['is_closing' => true]);
        $this->put($url, $payload)->assertForbidden();
        $this->assertDatabaseCount('anamnesas', 0);
    }

    public function test_deleting_all_masters_does_not_restore_legacy_options_in_new_package_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->seed(McuTemplateMasterSeeder::class);
        $skin = McuItem::where('label', 'Irama')->firstOrFail();
        $schema = [['id' => $skin->category->id, 'name' => $skin->category->name, 'order' => 0, 'items' => [$skin->definition()]]];
        $oldPackage = Paket::factory()->master()->create(['dynamic_schema' => $schema]);
        foreach (McuItem::pluck('id')->chunk(30) as $ids) {
            $this->post(route('mcu-item.bulk-destroy'), ['ids' => $ids->values()->all()])->assertRedirect(route('mcu-item.index'));
        }
        foreach (McuSubcategory::doesntHave('items')->pluck('id') as $id) {
            $this->delete(route('mcu-subcategory.destroy', $id))->assertRedirect(route('mcu-subcategory.index'));
        }
        foreach (McuCategory::pluck('id')->chunk(30) as $ids) {
            $this->post(route('mcu-category.bulk-destroy'), ['ids' => $ids->values()->all()])->assertRedirect(route('mcu-category.index'));
        }

        $this->assertDatabaseCount('mcu_items', 2);
        $this->assertDatabaseCount('mcu_categories', 2);
        $this->get(route('paket.create'))->assertOk()->assertSee('Tinggi Badan (cm)')->assertSee('Berat Badan (kg)')
            ->assertSee('value="bmi"', false)->assertDontSee('value="hipertensi"', false)
            ->assertDontSee('value="kulit"', false)->assertDontSee('value="rontgen"', false)->assertDontSee('value="lab_kolesterol"', false)
            ->assertDontSee('value="'.$skin->id.'"', false);
        $this->post(route('paket.store'), ['nama_paket' => 'Katalog terhapus', 'items' => ['kulit']])->assertSessionHasErrors('items.0');
        $this->post(route('paket.store'), ['nama_paket' => 'Master terhapus', 'master_items' => [$skin->id]])->assertSessionHasErrors('master_items.0');
        $this->assertSame($schema, $oldPackage->fresh()->dynamic_schema);
        $this->get(route('paket.show', $oldPackage))->assertOk()->assertSee($skin->label);
        $this->assertDatabaseCount('pakets', 1);
    }

    public function test_legacy_items_in_existing_packages_remain_editable_without_becoming_new_package_options(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->master()->create(['active_items' => ['kulit' => true]]);

        $this->get(route('paket.edit', $package))->assertOk()->assertSee('value="kulit"', false)->assertDontSee('value="rontgen"', false);
        $this->put(route('paket.update', $package), ['nama_paket' => 'Paket lama dipertahankan', 'items' => ['kulit']])->assertSessionHasNoErrors();
        $this->assertSame(['kulit' => true], $package->fresh()->active_items);
        $this->get(route('paket.create'))->assertOk()->assertDontSee('value="kulit"', false);
        $item = McuItem::factory()->create(['label' => 'Pertanyaan baru']);
        $this->get(route('paket.create'))->assertOk()->assertSee('value="'.$item->id.'"', false)->assertSee('Pertanyaan baru');
    }

    public function test_master_and_exam_snapshots_remain_independent_across_patients(): void
    {
        [$first, $item] = $this->dynamicParticipant();
        $this->put(route('pemeriksaan.update', [$first->rencanaKunjungan, $first]), ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => 'TIDAK']])->assertSessionHasNoErrors();
        $firstExam = $first->fresh()->anamnesa;
        $this->post(route('pasien.store'), ['nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'rencana_kunjungan_id' => $first->rencana_kunjungan_id, 'paket_id' => $first->paket_id, 'nama_karyawan' => 'Peserta kedua', 'jenis_kelamin' => 'P'])->assertSessionHasNoErrors();
        $second = Karyawan::where('nama_karyawan', 'Peserta kedua')->firstOrFail();
        $this->assertSame($first->paket_id, $second->paket_id);
        $this->assertNull($second->anamnesa);
        $this->assertSame('TIDAK', $firstExam->fresh()->dynamic_answers[$item->id]);
        $this->assertNotContains('Pemeriksaan sesuai mapping paket belum selesai', $firstExam->finalizationErrors($first));
        $this->assertTrue((new Anamnesa)->progress($second)['dinamis']['included']);
        $this->assertNotEmpty((new Anamnesa)->progress($second)['dinamis']['missing']);
    }

    /** @return array<string, mixed> */
    private function itemPayload(McuCategory $category): array
    {
        return ['mcu_category_id' => $category->id, 'label' => 'Hepatitis', 'input_type' => 'radio', 'yes_report' => 'Ada riwayat Hepatitis', 'no_report' => 'Normal', 'is_required' => 1, 'is_active' => 1, 'sort_order' => 0];
    }

    /** @return array{Karyawan, McuItem} */
    private function dynamicParticipant(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $item = McuItem::factory()->create();
        $this->post(route('paket.store'), ['nama_paket' => 'Paket dinamis', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $package = Paket::latest()->firstOrFail();
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);

        return [$participant->fresh(), $item];
    }
}
