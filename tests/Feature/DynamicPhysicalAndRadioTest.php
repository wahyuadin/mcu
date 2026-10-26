<?php

namespace Tests\Feature;

use App\McuReport;
use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use Database\Seeders\McuTemplateMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicPhysicalAndRadioTest extends TestCase
{
    use RefreshDatabase;

    public function test_physical_masters_render_and_save_on_the_physical_tab_with_server_calculated_bmi(): void
    {
        [$participant, $height, $weight] = $this->physicalParticipant();
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'fisik']))->assertOk()
            ->assertSee('name="dynamic_answers['.$height->id.']"', false)->assertSee('data-bmi', false)->assertSee('Contoh: 170')
            ->assertViewHas('tabs', fn ($tabs): bool => ! isset($tabs['kategori-'.$height->mcu_category_id]));
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'fisik', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$height->id => 170]])->assertSessionHasErrors('kelengkapan');
        $this->assertNull($participant->fresh()->anamnesa);

        $this->put($url, ['section' => 'fisik', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$height->id => 170, $weight->id => 65]])->assertSessionHasErrors(['kelengkapan' => 'Bagian belum lengkap: Dokter pemeriksa fisik']);
        $this->assertNull($participant->fresh()->anamnesa);

        $this->put($url, ['section' => 'fisik', 'status' => 'selesai', 'lock_version' => 0, 'dokter_fisik_id' => Dokter::factory()->create(['is_active' => true])->id, 'bmi' => 999, 'kategori_bmi' => 'Palsu', 'dynamic_answers' => [$height->id => 170, $weight->id => 65]])->assertSessionHasNoErrors();

        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('22.49', $exam->bmi);
        $this->assertSame('Normal', $exam->kategori_bmi);
        $this->assertSame('Selesai', $exam->workflowProgress($participant)['fisik']['status']);
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('22.49')->assertSee('Tinggi Badan (cm)');
    }

    public function test_protected_physical_category_and_snapshots_survive_master_edits_and_template_reseeding(): void
    {
        [$participant, $height] = $this->physicalParticipant();
        $category = $height->category;
        $schema = $participant->paket->dynamic_schema;
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => $schema, 'dynamic_answers' => [$height->id => 170]]);
        $height->update(['label' => 'Tinggi diubah', 'is_active' => false]);
        $this->delete(route('mcu-category.destroy', $category))->assertSessionHasErrors('category');
        $this->put(route('mcu-category.update', $category), ['name' => $category->name, 'is_active' => 0])->assertSessionHasNoErrors();
        $this->post(route('mcu-category.bulk-destroy'), ['ids' => [$category->id]])->assertSessionHas('bulkDeleteResults', fn ($rows): bool => ! $rows[0]['deleted']);
        $this->seed(McuTemplateMasterSeeder::class);

        $this->assertSame('Tinggi diubah', $height->fresh()->label);
        $this->assertFalse($height->fresh()->is_active);
        $this->assertSame($schema, $participant->paket->fresh()->dynamic_schema);
        $this->assertSame($schema, $exam->fresh()->dynamic_schema);
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), ['section' => 'fisik', 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => [$height->id => 180]])->assertForbidden();
        $this->assertSame(170, $exam->fresh()->dynamic_answers[$height->id]);
        $this->post(route('paket.store'), ['nama_paket' => 'Item nonaktif', 'master_items' => [$height->id]])->assertSessionHasErrors('master_items.0');
        $this->post(route('paket.store'), ['nama_paket' => 'BMI tanpa item aktif', 'items' => ['bmi']])->assertSessionHasErrors('items');
        $this->put(route('mcu-item.update', $height), ['mcu_category_id' => $category->id, 'label' => 'Tinggi badan', 'input_type' => 'text', 'is_active' => 0, 'is_required' => 1])->assertSessionHasErrors('input_type');
        $this->assertSame('number', $height->fresh()->input_type);
    }

    public function test_master_radio_default_is_validated_and_copied_to_new_snapshots_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::where('legacy_group', 'anamnesa_riwayat')->sole();
        $payload = ['mcu_category_id' => $category->id, 'label' => 'Riwayat khusus', 'input_type' => 'radio', 'yes_report' => 'Ada', 'no_report' => 'Tidak ada', 'is_active' => 1, 'is_required' => 1, 'default_answer' => 'OTHER'];
        $this->post(route('mcu-item.store'), $payload)->assertSessionHasErrors('default_answer');
        $this->post(route('mcu-item.store'), array_replace($payload, ['default_answer' => 'TIDAK']))->assertSessionHasNoErrors();
        $item = $category->items()->where('label', 'Riwayat khusus')->sole();
        $this->assertSame('TIDAK', $item->default_answer);
        $this->post(route('paket.store'), ['nama_paket' => 'Default radio', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $package = Paket::where('nama_paket', 'Default radio')->sole();
        $item->update(['default_answer' => 'YA']);

        $this->assertSame('TIDAK', $package->fresh()->dynamic_schema[0]['items'][0]['default_answer']);
        $this->put(route('mcu-item.update', $item), array_replace($payload, ['input_type' => 'text', 'default_answer' => 'YA']))->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->default_answer);
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'anamnesa']))->assertOk()->assertSee('value="TIDAK" checked', false);
    }

    public function test_bmi_selection_uses_dynamic_measurements_without_duplicate_legacy_fields(): void
    {
        [$participant, $height, $weight] = $this->physicalParticipant();
        $package = $participant->paket;
        $this->put(route('paket.update', $package), ['nama_paket' => $package->nama_paket, 'items' => ['bmi'], 'master_items' => [$height->id, $weight->id]])->assertSessionHasNoErrors();
        $this->assertSame(['bmi' => true], $package->fresh()->active_items);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'fisik', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$height->id => 0, $weight->id => 65]])->assertSessionHasErrors('dynamic_answers.'.$height->id);
        $this->assertNull($participant->fresh()->anamnesa);
        $this->put($url, ['section' => 'fisik', 'status' => 'selesai', 'lock_version' => 0, 'dokter_fisik_id' => Dokter::factory()->create(['is_active' => true])->id, 'dynamic_answers' => [$height->id => 170, $weight->id => 65]])->assertSessionHasNoErrors();

        $exam = $participant->fresh()->anamnesa;
        $sections = (new McuReport)->sections($participant, $exam);
        $this->assertCount(1, array_filter($sections, fn ($section): bool => $section['title'] === 'Pemeriksaan Fisik'));
        $this->assertSame('22.49', $exam->bmi);
    }

    public function test_dynamic_yes_notes_are_reported_and_no_discards_hidden_notes_without_overwriting_saved_answers_with_defaults(): void
    {
        [$participant, $item] = $this->physicalParticipant();
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['items'] = [array_replace($item->definition(), ['type' => 'radio', 'physical_key' => null, 'yes_report' => 'Ada', 'no_report' => 'Tidak ada', 'default_answer' => 'YA'])];
        $participant->paket->update(['dynamic_schema' => $schema]);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'fisik', 'status' => 'draft', 'lock_version' => 0, 'dynamic_notes' => ['not-in-package' => 'Palsu']])->assertSessionHasErrors('dynamic_notes');
        $this->put($url, ['section' => 'fisik', 'status' => 'selesai', 'lock_version' => 0, 'dokter_fisik_id' => Dokter::factory()->create(['is_active' => true])->id, 'dynamic_answers' => [$item->id => 'YA'], 'dynamic_notes' => [$item->id => 'Sejak 2020']])->assertSessionHasNoErrors();
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Ada — Sejak 2020');
        $this->put($url, ['section' => 'fisik', 'status' => 'selesai', 'lock_version' => 1, 'dynamic_answers' => [$item->id => 'TIDAK'], 'dynamic_notes' => [$item->id => 'Jangan simpan']])->assertSessionHasNoErrors();

        $this->assertNull($participant->fresh()->anamnesa->dynamic_notes[$item->id]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'fisik']))->assertOk()->assertSee('value="TIDAK" checked', false);
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertDontSee('Jangan simpan')->assertDontSee('Sejak 2020');
    }

    public function test_public_default_and_conditional_notes_are_applied_without_creating_an_exam(): void
    {
        [$participant, $item] = $this->physicalParticipant();
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['section'] = 'anamnesa';
        $schema[0]['items'] = [array_replace($item->definition(), ['type' => 'radio', 'yes_report' => 'Ada', 'no_report' => 'Tidak ada', 'default_answer' => 'TIDAK'])];
        $participant->paket->update(['dynamic_schema' => $schema]);
        $this->post(route('anamnesa.barcode', $participant->rencanaKunjungan))->assertOk();
        $token = $participant->rencanaKunjungan->fresh()->anamnesa_token;
        $this->post(route('logout'));
        $this->post(route('anamnesa.identify', $token), ['nip' => $participant->nip, 'tanggal_lahir' => $participant->tanggal_lahir->format('Y-m-d')])->assertSessionHasNoErrors();
        $this->get(route('anamnesa.public', $token))->assertOk()->assertSee('value="Tidak" required checked', false)->assertSee('data-conditional-note', false);
        $this->post(route('anamnesa.submit', $token), ['confirmed' => 1, 'answers' => [$item->id => ['answer' => 'Tidak', 'note' => 'Abaikan']]])->assertSessionHasNoErrors();

        $this->assertNull($participant->fresh()->anamnesa_submission['answers'][$item->id]['note']);
        $this->assertNull($participant->fresh()->anamnesa);
    }

    /** @return array{Karyawan, McuItem, McuItem} */
    private function physicalParticipant(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::where('legacy_group', 'pemeriksaan_fisik')->sole();
        $height = $category->items()->where('legacy_key', 'tinggi_badan')->sole();
        $weight = $category->items()->where('legacy_key', 'berat_badan')->sole();
        $package = Paket::factory()->create(['active_items' => []]);
        $this->put(route('paket.update', $package), ['nama_paket' => $package->nama_paket, 'master_items' => [$height->id, $weight->id]])->assertSessionHasNoErrors();

        return [Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id]), $height, $weight];
    }
}
