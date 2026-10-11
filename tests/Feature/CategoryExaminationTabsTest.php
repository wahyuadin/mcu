<?php

namespace Tests\Feature;

use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\McuSubcategory;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryExaminationTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabs_follow_selected_snapshot_categories_and_show_only_the_current_category_items(): void
    {
        [$participant, $radio, $lab] = $this->participant();
        McuCategory::factory()->create(['name' => 'Kategori tidak dipilih']);
        $category = 'kategori-'.$radio->mcu_category_id;
        $labCategory = 'kategori-'.$lab->mcu_category_id;

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $category]))->assertOk()
            ->assertViewHas('tabs', fn (array $tabs): bool => array_keys(array_filter($tabs, fn (string $key): bool => str_starts_with($key, 'kategori-'), ARRAY_FILTER_USE_KEY)) === [$category, $labCategory])
            ->assertSee('RIWAYAT PASIEN')->assertSee('HASIL LABORATORIUM')->assertSee('Riwayat keluarga')
            ->assertSee('name="dynamic_answers['.$radio->id.']"', false)->assertDontSee('name="dynamic_answers['.$lab->id.']"', false)
            ->assertDontSee('Kategori tidak dipilih')->assertDontSee('Form paket')->assertDontSee('tab=dinamis', false);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $labCategory]))->assertOk()
            ->assertSee('HEMATOLOGI')->assertSee('Nilai Normal')->assertSee('name="dynamic_answers['.$lab->id.']"', false)
            ->assertDontSee('name="dynamic_answers['.$radio->id.']"', false)->assertViewHas('tab', $labCategory);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'dinamis']))
            ->assertRedirect(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $category]));
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('RIWAYAT PASIEN')->assertSee('HASIL LABORATORIUM')->assertDontSee('Form sesuai mapping paket');
    }

    public function test_saving_a_category_is_scoped_and_does_not_complete_or_overwrite_other_categories(): void
    {
        [$participant, $radio, $lab] = $this->participant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $category = 'kategori-'.$radio->mcu_category_id;
        $labCategory = 'kategori-'.$lab->mcu_category_id;

        $this->put($url, ['section' => $category, 'status' => 'selesai', 'lock_version' => 0])->assertSessionHasErrors('kelengkapan');
        $this->put($url, ['section' => $category, 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => [$lab->id => 14]])->assertSessionHasErrors('dynamic_answers');
        $this->assertDatabaseCount('anamnesas', 0);
        $this->put($url, ['section' => $category, 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$radio->id => 'TIDAK']])->assertSessionHasNoErrors()
            ->assertRedirect(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $category]));
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('Selesai', $exam->workflowProgress($participant)[$category]['status']);
        $this->assertSame('Belum diisi', $exam->workflowProgress($participant)[$labCategory]['status']);
        $this->assertSame(['completed' => 2, 'total' => 3, 'percent' => 67], $exam->examinationCompletion($participant));
        $this->put($url, ['section' => $labCategory, 'status' => 'selesai', 'lock_version' => 1, 'dynamic_answers' => [$lab->id => '14']])->assertSessionHasNoErrors();
        $exam = $exam->fresh();
        $this->assertSame('TIDAK', $exam->dynamic_answers[$radio->id]);
        $this->assertSame('14', $exam->dynamic_answers[$lab->id]);
        $this->assertSame(['completed' => 3, 'total' => 3, 'percent' => 100], $exam->examinationCompletion($participant));
        $this->put($url, ['section' => $category, 'status' => 'draft', 'lock_version' => 1, 'dynamic_answers' => [$radio->id => 'YA']])->assertSessionHasErrors('pemeriksaan');
        $this->assertSame('TIDAK', $exam->fresh()->dynamic_answers[$radio->id]);
    }

    public function test_unused_legacy_sections_are_hidden_in_navigation_and_review(): void
    {
        [$participant] = $this->participant();
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'review']))->assertOk()
            ->assertViewHas('tabs', fn (array $tabs): bool => ! array_key_exists('penunjang', $tabs) && ! array_key_exists('laboratorium', $tabs))
            ->assertDontSee('tab=penunjang', false)->assertDontSee('tab=laboratorium', false)
            ->assertDontSee('<strong>Laboratorium</strong>', false)->assertDontSee('<strong>Penunjang</strong>', false)
            ->assertDontSee('Belum ada hasil laboratorium.')->assertSee('HASIL LABORATORIUM')->assertSee('RIWAYAT PASIEN');
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'penunjang']))->assertOk()
            ->assertViewHas('tab', 'ringkasan')->assertDontSee('tab=penunjang', false);
    }

    public function test_report_omits_empty_legacy_laboratory_section_but_keeps_master_category_results(): void
    {
        [$participant] = $this->participant();

        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()
            ->assertDontSee('Hasil laboratorium')->assertSee('HASIL LABORATORIUM')->assertSee('Hemoglobin')->assertSee('Nilai Normal');
    }

    public function test_report_uses_nayaka_and_company_master_logos_without_example_patient_data(): void
    {
        [$participant] = $this->participant();
        $participant->perusahaan->update(['logo_path' => 'perusahaan/report-logo.webp']);

        $response = $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()
            ->assertSee(asset('Template/assets/logo-nayaka.jpeg'), false)
            ->assertSee(route('perusahaan.logo', $participant->perusahaan), false)
            ->assertSee($participant->nama_karyawan)->assertSee($participant->perusahaan->nama_perusahaan)
            ->assertSee('PEMERIKSAAN')->assertSee('Ringkasan MCU')->assertSee('DRAFT — BELUM DIFINALISASI')
            ->assertSee(asset('css/mcu-report.css'), false)->assertDontSee('9 halaman')->assertDontSee('dr. Dita')->assertDontSee('Dislipidemia,');
        $this->assertSame(3, substr_count($response->getContent(), 'class="brand-header"'));
        $this->assertSame(3, substr_count($response->getContent(), 'class="report-footer"'));
    }

    public function test_report_without_company_logo_keeps_company_name_and_hides_unused_sections(): void
    {
        [$participant] = $this->participant();

        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()
            ->assertSee($participant->perusahaan->nama_perusahaan)->assertSee('Nayaka Era Husada')
            ->assertDontSee(route('perusahaan.logo', $participant->perusahaan), false)
            ->assertDontSee('Anamnesa pasien')->assertDontSee('Dokter pemeriksa fisik:')
            ->assertDontSee('Hasil laboratorium')->assertSee('HASIL LABORATORIUM')->assertSee('Nilai Normal');
    }

    public function test_unknown_category_and_answers_outside_package_are_rejected(): void
    {
        [$participant, $radio] = $this->participant();
        $foreign = McuItem::factory()->create();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);

        $this->put($url, ['section' => 'kategori-'.$foreign->mcu_category_id, 'status' => 'draft', 'lock_version' => 0])->assertSessionHasErrors('section');
        $this->put($url, ['section' => 'kategori-'.$radio->mcu_category_id, 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => [$foreign->id => 'YA']])->assertSessionHasErrors('dynamic_answers');
        $this->assertDatabaseCount('anamnesas', 0);
    }

    public function test_category_snapshot_and_status_survive_master_deletion_and_package_changes(): void
    {
        [$participant, $radio, $lab] = $this->participant();
        $key = 'kategori-'.$radio->mcu_category_id;
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => $key, 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$radio->id => 'TIDAK']])->assertSessionHasNoErrors();
        $schema = $participant->fresh()->anamnesa->dynamic_schema;
        $this->delete(route('mcu-item.destroy', $radio))->assertSessionHasNoErrors();
        $this->delete(route('mcu-category.destroy', $radio->category))->assertSessionHasNoErrors();
        $lab->category->update(['name' => 'Nama baru', 'sort_order' => 99]);
        $participant->paket->update(['dynamic_schema' => []]);

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $key]))->assertOk()->assertSee('RIWAYAT PASIEN')->assertSee('HASIL LABORATORIUM')->assertDontSee('Nama baru')->assertSee('aria-label="Selesai"', false);
        $this->assertSame($schema, $participant->fresh()->anamnesa->dynamic_schema);
        $this->get(route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('RIWAYAT PASIEN')->assertSee('HASIL LABORATORIUM');
    }

    public function test_old_combined_section_states_remain_readable_without_duplicate_completion_entries(): void
    {
        [$participant, $radio, $lab] = $this->participant();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => [], 'dynamic_schema' => $participant->paket->dynamic_schema,
            'dynamic_answers' => [$radio->id => 'TIDAK', $lab->id => '14'], 'section_status' => ['dinamis' => 'selesai', 'laboratorium' => 'selesai']]);

        $progress = $exam->workflowProgress($participant);
        $this->assertArrayNotHasKey('dinamis', $progress);
        $this->assertFalse($progress['laboratorium']['included']);
        $this->assertSame('Selesai', $progress['kategori-'.$radio->mcu_category_id]['status']);
        $this->assertSame('Selesai', $progress['kategori-'.$lab->mcu_category_id]['status']);
        $this->assertSame(['completed' => 3, 'total' => 3, 'percent' => 100], $exam->examinationCompletion($participant));
    }

    public function test_finalization_requires_each_category_and_revisions_preserve_previous_results(): void
    {
        [$participant, $radio, $lab] = $this->participant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $doctor = Dokter::factory()->create(['is_active' => true]);
        $this->put($url, ['section' => 'kesimpulan', 'status' => 'selesai', 'lock_version' => 0, 'dokter_id' => $doctor->id, 'catatan_dokter' => 'Kesimpulan', 'anjuran_saran' => 'Saran', 'status_kelaikan' => 'Fit to Work'])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'kategori-'.$radio->mcu_category_id, 'status' => 'selesai', 'lock_version' => 1, 'dynamic_answers' => [$radio->id => 'TIDAK']])->assertSessionHasNoErrors();
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['confirmed' => 1, 'lock_version' => 2])->assertSessionHasErrors('kelengkapan');
        $this->put($url, ['section' => 'kategori-'.$lab->mcu_category_id, 'status' => 'selesai', 'lock_version' => 2, 'dynamic_answers' => [$lab->id => '14']])->assertSessionHasNoErrors();
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['confirmed' => 1, 'lock_version' => 3])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'kategori-'.$lab->mcu_category_id, 'status' => 'draft', 'lock_version' => 4, 'dynamic_answers' => [$lab->id => '0']])->assertForbidden();
        $this->post(route('pemeriksaan.revise', [$participant->rencanaKunjungan, $participant]), ['reason' => 'Koreksi hasil lab'])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'kategori-'.$lab->mcu_category_id, 'status' => 'selesai', 'lock_version' => 5, 'dynamic_answers' => [$lab->id => '0']])->assertSessionHasNoErrors();
        $this->get(route('pemeriksaan.laporan-riwayat.version', [$participant->rencanaKunjungan, $participant, 1]))->assertOk()->assertSee('Dalam batas normal')->assertDontSee('Di luar batas normal');
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Di luar batas normal');
    }

    public function test_mixed_category_saves_radio_and_laboratory_answers_together_and_requires_both(): void
    {
        [$participant, $radio, $lab] = $this->participant();
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['items'][] = $schema[1]['items'][0];
        $participant->paket->update(['dynamic_schema' => [$schema[0]]]);
        $participant = $participant->fresh();
        $key = 'kategori-'.$lab->mcu_category_id;
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);

        $this->put($url, ['section' => $key, 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$radio->id => 'TIDAK']])->assertSessionHasErrors('kelengkapan');
        $this->put($url, ['section' => $key, 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$radio->id => 'TIDAK', $lab->id => '0']])->assertSessionHasNoErrors();
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('Selesai', $exam->workflowProgress($participant)[$key]['status']);
        $this->assertSame('0', $exam->dynamic_answers[$lab->id]);
        $this->assertSame('TIDAK', $exam->dynamic_answers[$radio->id]);
    }

    public function test_category_tabs_do_not_bypass_photo_closed_visit_or_participant_boundaries(): void
    {
        [$participant, $radio] = $this->participant();
        $section = 'kategori-'.$radio->mcu_category_id;
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $participant->update(['foto' => null]);

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $section]))->assertOk()->assertViewIs('mcu.pemeriksaan-foto');
        $this->put($url, ['section' => $section, 'status' => 'draft', 'lock_version' => 0])->assertSessionHasErrors('foto_pasien');
        $participant->rencanaKunjungan->update(['is_closing' => true]);
        $this->put($url, ['section' => $section, 'status' => 'draft', 'lock_version' => 0])->assertForbidden();
        $other = Karyawan::factory()->create();
        $this->get(route('pemeriksaan.edit', [$other->rencanaKunjungan, $participant, 'tab' => $section]))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'dokter']));
        $this->put($url, ['section' => $section, 'status' => 'draft', 'lock_version' => 0])->assertForbidden();
        $this->assertDatabaseCount('anamnesas', 0);
    }

    public function test_old_dynamic_link_without_selected_categories_returns_to_summary(): void
    {
        [$participant] = $this->participant();
        $participant->paket->update(['dynamic_schema' => []]);

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'dinamis']))
            ->assertRedirect(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'ringkasan']));
    }

    /** @return array{Karyawan, McuItem, McuItem} */
    private function participant(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = McuCategory::factory()->create(['name' => 'RIWAYAT PASIEN', 'sort_order' => 1]);
        $second = McuCategory::factory()->create(['name' => 'HASIL LABORATORIUM', 'sort_order' => 2]);
        $radio = McuItem::factory()->create(['mcu_category_id' => $first->id, 'label' => 'Riwayat keluarga']);
        $subcategory = McuSubcategory::factory()->create(['mcu_category_id' => $second->id, 'name' => 'HEMATOLOGI']);
        $lab = McuItem::factory()->create(['mcu_category_id' => $second->id, 'mcu_subcategory_id' => $subcategory->id, 'input_type' => 'laboratory', 'label' => 'Hemoglobin',
            'lab_config' => ['result_type' => 'number', 'rule' => 'range', 'min' => 12, 'max' => 16, 'normal_values' => [], 'unit' => 'g/dL', 'reference' => '12–16', 'normal_report' => 'Dalam batas normal', 'abnormal_report' => 'Di luar batas normal']]);
        $package = Paket::factory()->master()->create(['active_items' => [], 'dynamic_schema' => [
            ['id' => $second->id, 'name' => $second->name, 'order' => 2, 'items' => [$lab->definition()]],
            ['id' => $first->id, 'name' => $first->name, 'order' => 1, 'items' => [$radio->definition()]],
        ]]);
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);

        return [$participant->fresh(), $radio, $lab];
    }
}
