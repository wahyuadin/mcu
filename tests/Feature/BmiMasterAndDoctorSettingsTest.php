<?php

namespace Tests\Feature;

use App\CategoryResults;
use App\McuReport;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BmiMasterAndDoctorSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_measurements_allow_only_label_updates_and_cannot_be_deleted_individually_or_in_bulk(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::where('legacy_group', 'pemeriksaan_fisik')->sole();
        foreach (['tinggi_badan', 'berat_badan'] as $key) {
            $item = $category->items()->where('legacy_key', $key)->sole();
            $this->get(route('mcu-item.edit', $item))->assertOk()->assertSee('name="label"', false)->assertDontSee('name="mcu_category_id"', false);
            $this->put(route('mcu-item.update', $item), ['label' => 'Nama baru '.$key])->assertSessionHasNoErrors();
            $this->assertSame('Nama baru '.$key, $item->fresh()->label);
            foreach (['input_type' => 'text', 'is_active' => 0, 'is_required' => 0, 'sort_order' => 123, 'mcu_category_id' => $category->id, 'lab_unit' => 'meter'] as $field => $value) {
                $this->put(route('mcu-item.update', $item), ['label' => 'Tidak boleh tersimpan', $field => $value])->assertSessionHasErrors('item');
                $this->assertSame('Nama baru '.$key, $item->fresh()->label);
            }
            $this->delete(route('mcu-item.destroy', $item))->assertSessionHasErrors('item');
            $this->post(route('mcu-item.bulk-destroy'), ['ids' => [$item->id]])->assertSessionHas('bulkDeleteResults', fn (array $rows): bool => ! $rows[0]['deleted']);
            $this->assertModelExists($item);
            $this->assertSame('number', $item->fresh()->input_type);
        }
    }

    public function test_physical_notes_are_hidden_and_not_importable_while_old_notes_are_preserved(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = Karyawan::factory()->withPhoto()->create();
        $category = McuCategory::where('legacy_group', 'pemeriksaan_fisik')->sole();
        $height = $category->items()->where('legacy_key', 'tinggi_badan')->sole();
        $weight = $category->items()->where('legacy_key', 'berat_badan')->sole();
        $this->put(route('paket.update', $participant->paket), ['nama_paket' => 'BMI', 'master_items' => [$height->id, $weight->id]])->assertSessionHasNoErrors();
        $participant->refresh()->load('paket');
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => [], 'dynamic_schema' => $participant->paket->dynamic_schema, 'dynamic_notes' => [$height->id => 'Catatan historis']]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'fisik']))->assertOk()
            ->assertDontSee('name="dynamic_notes['.$height->id.']"', false)->assertDontSee('name="dynamic_notes['.$weight->id.']"', false)->assertSee('data-bmi', false);
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), ['section' => 'fisik', 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => [$height->id => 160, $weight->id => 60], 'dynamic_notes' => [$height->id => 'Tidak disimpan']])->assertSessionHasNoErrors();
        $this->assertSame('Catatan historis', $exam->fresh()->dynamic_notes[$height->id]);
        $this->assertSame('23.44', $exam->fresh()->bmi);
        $this->assertSame('Kelebihan Berat Badan (Overweight)', $exam->fresh()->kategori_bmi);
        $columns = (new CategoryResults)->columns($participant->rencanaKunjungan, $category);
        $this->assertArrayNotHasKey('note:d_'.$height->id, $columns);
        $this->assertArrayNotHasKey('note:d_'.$weight->id, $columns);
    }

    public function test_builtin_doctor_setting_is_editable_and_copied_to_new_packages_without_rewriting_old_snapshots(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['anamnesa_riwayat', 'pemeriksaan_fisik'] as $legacy) {
            $category = McuCategory::where('legacy_group', $legacy)->sole();
            $this->get(route('mcu-category.edit', $category))->assertOk()->assertSee('Dokter penanggung jawab')->assertSee('name="has_responsible_doctor" value="1"', false);
            $item = $category->items()->firstOrFail();
            foreach ([true, false] as $enabled) {
                $this->put(route('mcu-category.update', $category), ['name' => $category->name, 'is_active' => 1, 'has_responsible_doctor' => (int) $enabled])->assertSessionHasNoErrors();
                $participant = Karyawan::factory()->withPhoto()->create();
                $this->put(route('paket.update', $participant->paket), ['nama_paket' => 'Dokter', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
                $participant->refresh()->load('paket');
                $schema = $participant->paket->dynamic_schema;
                $this->assertSame($enabled, $schema[0]['has_responsible_doctor']);
                $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => [], 'dynamic_schema' => $schema]);
                $tab = $legacy === 'pemeriksaan_fisik' ? 'fisik' : 'anamnesa';
                $doctorField = $tab === 'fisik' ? 'dokter_fisik_id' : 'category_doctors['.$category->id.']';
                $response = $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $tab]))->assertOk();
                if ($enabled) {
                    $response->assertSee('name="'.$doctorField.'"', false);
                } else {
                    $response->assertDontSee('name="'.$doctorField.'"', false);
                }
                $sections = (new McuReport)->sections($participant, $exam);
                $this->assertSame($enabled, $sections[0]['show_doctor']);
                $category->update(['has_responsible_doctor' => ! $enabled]);
                $this->assertSame($enabled, $exam->sectionHasDoctor($participant, $tab));
                $this->assertSame($schema, $exam->fresh()->dynamic_schema);
                if ($tab === 'fisik' && ! $enabled) {
                    $this->assertNotContains('Dokter pemeriksa fisik', $exam->workflowProgress($participant)['fisik']['missing']);
                }
            }
        }
    }
}
