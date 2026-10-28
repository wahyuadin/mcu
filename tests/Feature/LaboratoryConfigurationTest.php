<?php

namespace Tests\Feature;

use App\LaboratoryResult;
use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\McuSubcategory;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_subcategory_list_preserves_filters_statuses_and_edit_delete_actions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create(['name' => 'LAB']);
        $subcategory = McuSubcategory::factory()->create(['mcu_category_id' => $category->id, 'name' => 'Hematologi', 'is_active' => false]);
        McuSubcategory::factory()->create(['name' => 'Kelompok lain']);

        $this->get(route('mcu-subcategory.index'))->assertOk()
            ->assertSee('Daftar subkategori MCU')->assertSee('Aktif')->assertSee('Nonaktif')
            ->assertSee('aria-label="Ubah Hematologi"', false)->assertSee('aria-label="Hapus Hematologi"', false)
            ->assertSee(route('mcu-subcategory.edit', $subcategory))->assertSee(route('mcu-subcategory.destroy', $subcategory))
            ->assertSee('Atur urutan subkategori')->assertSee('Urutan');
        $this->get(route('mcu-subcategory.index', ['category' => $category->id, 'q' => 'Hema']))->assertOk()
            ->assertSee('Hematologi')->assertDontSee('Kelompok lain')->assertSee('value="Hema"', false)->assertSee('Reset');
        $this->get(route('mcu-subcategory.index', ['q' => 'Tidak ditemukan']))->assertOk()
            ->assertSee('Tidak ada subkategori yang cocok dengan filter.')->assertDontSee('aria-label="Ubah Hematologi"', false);
    }

    public function test_admin_builds_subcategory_and_laboratory_item_and_package_snapshot(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create(['name' => 'HASIL PEMERIKSAAN LABORATORIUM']);
        $this->get(route('mcu-subcategory.create'))->assertOk();
        $this->post(route('mcu-subcategory.store'), ['mcu_category_id' => $category->id, 'name' => 'HEMATOLOGI', 'is_active' => 1])->assertSessionHasNoErrors();
        $subcategory = $category->subcategories()->firstOrFail();
        $this->get(route('mcu-subcategory.index'))->assertOk()->assertSee('HEMATOLOGI');
        $this->get(route('mcu-subcategory.edit', $subcategory))->assertOk()->assertSee('HEMATOLOGI');
        $this->post(route('mcu-item.store'), $this->payload($subcategory))->assertSessionHasNoErrors();
        $item = McuItem::where('mcu_subcategory_id', $subcategory->id)->firstOrFail();
        $this->assertSame('g/dL', $item->lab_config['unit']);
        $this->assertSame($subcategory->id, $item->mcu_subcategory_id);
        $this->get(route('mcu-item.edit', $item))->assertOk()->assertSee('Mapping hasil laporan')->assertDontSee('Konfigurasi laboratorium')->assertSee('Dalam batas normal');
        $this->get(route('mcu-item.index'))->assertOk()->assertSee('HEMATOLOGI')->assertSee('Di luar batas normal');
        $this->get(route('paket.create'))->assertOk()->assertSee('HEMATOLOGI')->assertSee('Pilih / lepas semua subkategori');
        $this->post(route('paket.store'), ['nama_paket' => 'Paket hematologi', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $package = Paket::firstOrFail();
        $snapshot = $package->dynamic_schema;
        $this->assertSame('HEMATOLOGI', $snapshot[0]['items'][0]['subcategory']['name']);
        $this->assertSame($item->lab_config, $snapshot[0]['items'][0]['lab_config']);
        $this->get(route('paket.show', $package))->assertOk()->assertSee('HEMATOLOGI')->assertSee('g/dL')->assertSee('Dalam batas normal');
        $this->put(route('mcu-subcategory.update', $subcategory), ['mcu_category_id' => $category->id, 'name' => 'Nama baru', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->delete(route('mcu-subcategory.destroy', $subcategory))->assertSessionHasErrors('subcategory');
        $this->delete(route('mcu-category.destroy', $category))->assertSessionHasErrors('category');
        $this->post(route('mcu-category.bulk-destroy'), ['ids' => [$category->id]])->assertSessionHas('bulkDeleteResults', fn ($results): bool => ! $results[0]['deleted']);
        $this->delete(route('mcu-item.destroy', $item))->assertSessionHasNoErrors();
        $this->delete(route('mcu-subcategory.destroy', $subcategory))->assertSessionHasNoErrors();
        $this->delete(route('mcu-category.destroy', $category))->assertSessionHasNoErrors();
        $this->assertSame($snapshot, $package->fresh()->dynamic_schema);
        $this->get(route('paket.show', $package))->assertOk()->assertSee('HEMATOLOGI')->assertDontSee('Nama baru');
        $this->get(route('paket.create'))->assertOk()->assertDontSee('value="'.$item->id.'"', false);
    }

    public function test_subcategories_are_scoped_active_and_protected_from_cross_category_moves(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $subcategory = McuSubcategory::factory()->create();
        $other = McuCategory::factory()->create();
        $payload = $this->payload($subcategory);
        $payload['mcu_category_id'] = $other->id;
        $this->post(route('mcu-item.store'), $payload)->assertSessionHasErrors('mcu_subcategory_id');
        $this->post(route('mcu-item.store'), $this->payload($subcategory))->assertSessionHasNoErrors();
        $item = McuItem::where('mcu_subcategory_id', $subcategory->id)->firstOrFail();
        $this->put(route('mcu-subcategory.update', $subcategory), ['mcu_category_id' => $other->id, 'name' => 'Pindah', 'is_active' => 1])->assertSessionHasErrors('mcu_category_id');
        $this->assertSame($subcategory->mcu_category_id, $subcategory->fresh()->mcu_category_id);
        $subcategory->update(['is_active' => false]);
        $this->get(route('paket.create'))->assertOk()->assertDontSee('value="'.$item->id.'"', false);
        $this->post(route('paket.store'), ['nama_paket' => 'Ditolak', 'master_items' => [$item->id]])->assertSessionHasErrors('master_items');
        $this->assertDatabaseCount('pakets', 0);
    }

    public function test_subcategory_order_is_scoped_versioned_and_does_not_restore_deleted_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = McuSubcategory::factory()->create();
        $second = McuSubcategory::factory()->create(['mcu_category_id' => $first->mcu_category_id, 'sort_order' => 1, 'is_active' => false]);
        $foreign = McuSubcategory::factory()->create();
        $url = route('mcu-subcategory-order.show', $first->category);
        $update = route('mcu-subcategory-order.update', $first->category);
        $response = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(2, 'items');
        $this->putJson($update, ['version' => $response->json('version'), 'items' => [$second->id, $foreign->id]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $saved = $this->putJson($update, ['version' => $response->json('version'), 'items' => [$second->id, $first->id]])->assertOk();
        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
        $this->putJson($update, ['version' => $response->json('version'), 'items' => [$first->id, $second->id]])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->delete(route('mcu-subcategory.destroy', $first))->assertSessionHasNoErrors();
        $this->delete(route('mcu-subcategory.destroy', $second))->assertSessionHasNoErrors();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'items');
        $this->putJson($update, ['version' => $saved->json('version'), 'items' => [$first->id, $second->id]])->assertUnprocessable();
    }

    public function test_subcategory_endpoints_require_administrator(): void
    {
        $subcategory = McuSubcategory::factory()->create();
        $this->get(route('mcu-subcategory.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->get(route('mcu-subcategory.index'))->assertForbidden();
        $this->post(route('mcu-subcategory.store'), [])->assertForbidden();
        $this->put(route('mcu-subcategory.update', $subcategory), [])->assertForbidden();
        $this->delete(route('mcu-subcategory.destroy', $subcategory))->assertForbidden();
        $this->getJson(route('mcu-subcategory-order.show', $subcategory->category))->assertForbidden();
        $this->putJson(route('mcu-subcategory-order.update', $subcategory->category), [])->assertForbidden();
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_laboratory_configuration_cannot_be_saved(array $changes, string $error): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $subcategory = McuSubcategory::factory()->create();
        $payload = $this->payload($subcategory);
        $payload['questions'][0] = array_replace($payload['questions'][0], $changes);

        $this->post(route('mcu-item.store'), $payload)->assertSessionHasErrors('questions.0.'.$error);
        $this->assertSame(0, McuItem::where('mcu_subcategory_id', $subcategory->id)->count());
    }

    public static function invalidConfigurations(): array
    {
        return [
            'reference too long' => [['lab_reference' => str_repeat('A', 501)], 'lab_reference'],
            'default requires reference' => [['lab_reference' => '', 'lab_default_result' => '14'], 'lab_reference'],
            'missing type' => [['input_type' => ''], 'input_type'],
            'removed laboratory type' => [['input_type' => 'laboratory'], 'input_type'],
            'invalid rule' => [['lab_rule' => 'guess'], 'lab_rule'],
            'missing minimum' => [['lab_min' => null], 'lab_rule'],
            'missing maximum' => [['lab_max' => null], 'lab_rule'],
            'reversed range' => [['lab_min' => 18, 'lab_max' => 12], 'lab_rule'],
            'text rule for number' => [['lab_rule' => 'text'], 'lab_rule'],
            'number rule for text' => [['input_type' => 'text'], 'lab_rule'],
            'empty normal texts' => [['input_type' => 'text', 'lab_rule' => 'text', 'lab_normal_values' => " \n "], 'lab_rule'],
            'range is not numeric default' => [['lab_default_result' => '12–16'], 'lab_default_result'],
            'not numeric' => [['lab_min' => 'abc'], 'lab_min'],
        ];
    }

    #[DataProvider('resultCases')]
    public function test_normal_evaluation_boundaries_and_empty_results(string $rule, mixed $answer, string $status): void
    {
        $config = ['result_type' => $rule === 'text' ? 'text' : 'number', 'rule' => $rule, 'min' => 0, 'max' => 10, 'normal_values' => ['Negatif', 'Tidak ditemukan'], 'normal_report' => 'Normal mapping', 'abnormal_report' => 'Abnormal mapping'];
        $result = LaboratoryResult::evaluate($config, $answer);

        $this->assertSame($status, $result['status']);
        $this->assertSame(match ($status) {
            'Normal' => 'Normal mapping', 'Tidak normal' => 'Abnormal mapping', default => ''
        }, $result['remark']);
        $config['normal_report'] = $config['abnormal_report'] = '';
        $this->assertSame('', LaboratoryResult::evaluate($config, $answer)['remark']);
    }

    public static function resultCases(): array
    {
        return [
            ['range', null, 'Belum diperiksa'], ['range', '', 'Belum diperiksa'], ['range', ' ', 'Belum diperiksa'],
            ['range', '0', 'Normal'], ['range', '10', 'Normal'], ['range', '-0.01', 'Tidak normal'], ['range', '10.01', 'Tidak normal'],
            ['range', 'abc', 'Tidak dapat dibandingkan'], ['range', [], 'Tidak dapat dibandingkan'], ['range', '1e999', 'Tidak dapat dibandingkan'],
            ['lt', 10, 'Tidak normal'], ['lt', 9.9, 'Normal'], ['lte', 10, 'Normal'], ['lte', 10.1, 'Tidak normal'],
            ['gt', 0, 'Tidak normal'], ['gt', 0.1, 'Normal'], ['gte', 0, 'Normal'], ['gte', -1, 'Tidak normal'],
            ['text', ' negatif ', 'Normal'], ['text', 'Tidak ditemukan', 'Normal'], ['text', 'Positif', 'Tidak normal'], ['text', null, 'Belum diperiksa'],
        ];
    }

    public function test_laboratory_results_use_snapshot_mapping_and_preserve_final_revision_history(): void
    {
        [$participant, $item] = $this->participant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $report = route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$item->mcu_category_id]))->assertOk()->assertSee('HEMATOLOGI')->assertSee('Nilai Normal')->assertSee('Belum diperiksa');
        $this->get($report)->assertOk()->assertSee('Belum diisi')->assertDontSee('Dalam batas normal')->assertDontSee('Di luar batas normal');
        $this->put($url, ['section' => 'laboratorium', 'status' => 'selesai', 'lock_version' => 0])->assertSessionHasErrors('kelengkapan');
        $this->put($url, ['section' => 'laboratorium', 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => [$item->id => 'invalid']])->assertSessionHasErrors('dynamic_answers.'.$item->id);
        $this->put($url, ['section' => 'laboratorium', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => '14'], 'lab_config' => ['normal_report' => 'Injected']])->assertSessionHasNoErrors();
        $this->get($report)->assertOk()->assertSee('Dalam batas normal')->assertDontSee('Injected');
        $this->assertSame('Selesai', $participant->fresh()->anamnesa->progress($participant)['laboratorium']['status']);
        $changed = $item->lab_config;
        $changed['normal_report'] = 'Mapping baru';
        $item->update(['lab_config' => $changed]);
        $item->subcategory->update(['name' => 'Subkategori baru']);
        $this->get($report)->assertOk()->assertSee('HEMATOLOGI')->assertSee('Dalam batas normal')->assertDontSee('Mapping baru')->assertDontSee('Subkategori baru');
        $doctor = Dokter::factory()->create(['is_active' => true]);
        $this->put($url, ['section' => 'kesimpulan', 'status' => 'selesai', 'lock_version' => 1, 'dokter_id' => $doctor->id, 'catatan_dokter' => 'Kesimpulan', 'anjuran_saran' => 'Saran', 'status_kelaikan' => 'Fit to Work'])->assertSessionHasNoErrors();
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['confirmed' => 1, 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'laboratorium', 'status' => 'draft', 'lock_version' => 3, 'dynamic_answers' => [$item->id => '0']])->assertForbidden();
        $this->post(route('pemeriksaan.revise', [$participant->rencanaKunjungan, $participant]), ['reason' => 'Koreksi hasil lab'])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'laboratorium', 'status' => 'selesai', 'lock_version' => 4, 'dynamic_answers' => [$item->id => '0']])->assertSessionHasNoErrors();
        $this->assertSame('0', $participant->fresh()->anamnesa->dynamic_answers[$item->id]);
        $this->get($report)->assertOk()->assertSee('Di luar batas normal');
        $this->get(route('pemeriksaan.laporan-riwayat.version', [$participant->rencanaKunjungan, $participant, 1]))->assertOk()->assertSee('Dalam batas normal')->assertDontSee('Di luar batas normal');
    }

    public function test_text_mapping_optional_empty_remarks_and_escaping(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $subcategory = McuSubcategory::factory()->create();
        $payload = $this->payload($subcategory);
        $payload['questions'][0] = array_replace($payload['questions'][0], ['input_type' => 'text', 'lab_rule' => 'text', 'lab_normal_values' => "Negatif\nTidak ditemukan", 'lab_normal_report' => '', 'lab_abnormal_report' => '<script>temuan</script>']);
        $this->post(route('mcu-item.store'), $payload)->assertSessionHasNoErrors();
        $item = McuItem::where('mcu_subcategory_id', $subcategory->id)->firstOrFail();
        $this->assertSame('', $item->lab_config['normal_report']);
        $this->assertSame(['Negatif', 'Tidak ditemukan'], $item->lab_config['normal_values']);
        $package = Paket::factory()->master()->create(['active_items' => [], 'dynamic_schema' => [['id' => $subcategory->mcu_category_id, 'name' => 'Laboratorium', 'order' => 0, 'items' => [$item->definition()]]]]);
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'laboratorium', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => ' positif ']])->assertSessionHasNoErrors();
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('<script>temuan</script>')->assertDontSee('<script>temuan</script>', false);
    }

    public function test_mixed_package_answers_are_isolated_by_section_and_stale_updates_are_rejected(): void
    {
        [$participant, $laboratory] = $this->participant();
        $radio = McuItem::factory()->create(['mcu_category_id' => $laboratory->mcu_category_id]);
        $package = $participant->paket;
        $schema = $package->dynamic_schema;
        $schema[0]['items'][] = $radio->definition();
        $package->update(['dynamic_schema' => $schema]);
        $participant = $participant->fresh();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);

        $this->put($url, ['section' => 'laboratorium', 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => [$radio->id => 'YA']])->assertSessionHasErrors('dynamic_answers');
        $this->put($url, ['section' => 'laboratorium', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$laboratory->id => '14']])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'dinamis', 'status' => 'selesai', 'lock_version' => 1, 'dynamic_answers' => [$radio->id => 'TIDAK']])->assertSessionHasNoErrors();
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('14', $exam->dynamic_answers[$laboratory->id]);
        $this->assertSame('TIDAK', $exam->dynamic_answers[$radio->id]);
        $this->assertSame('Selesai', $exam->progress($participant)['laboratorium']['status']);
        $this->assertSame('Selesai', $exam->progress($participant)['dinamis']['status']);
        $this->put($url, ['section' => 'laboratorium', 'status' => 'draft', 'lock_version' => 1, 'dynamic_answers' => [$laboratory->id => '0']])->assertSessionHasErrors('pemeriksaan');
        $this->assertSame('14', $exam->fresh()->dynamic_answers[$laboratory->id]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$laboratory->mcu_category_id]))->assertOk()->assertSee('Hemoglobin')->assertSee('name="dynamic_answers['.$radio->id.']"', false)->assertSee('name="dynamic_answers['.$laboratory->id.']"', false);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'dinamis']))->assertRedirect(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$laboratory->mcu_category_id]));
    }

    public function test_optional_laboratory_result_can_be_empty_without_generating_normal_remark(): void
    {
        [$participant, $item] = $this->participant();
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['items'][0]['required'] = false;
        $participant->paket->update(['dynamic_schema' => $schema]);
        $participant = $participant->fresh();

        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), ['section' => 'laboratorium', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => '']])->assertSessionHasNoErrors();
        $this->assertSame('Selesai', $participant->fresh()->anamnesa->progress($participant)['laboratorium']['status']);
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Belum diisi')->assertDontSee('Dalam batas normal')->assertDontSee('Di luar batas normal');
    }

    /** @return array<string, mixed> */
    private function payload(McuSubcategory $subcategory): array
    {
        return ['mcu_category_id' => $subcategory->mcu_category_id, 'mcu_subcategory_id' => $subcategory->id, 'questions' => [[
            'label' => 'Hemoglobin', 'input_type' => 'number', 'is_required' => 1, 'is_active' => 1,
            'lab_rule' => 'range', 'lab_min' => 12, 'lab_max' => 16,
            'lab_unit' => 'g/dL', 'lab_reference' => '12–16', 'lab_normal_report' => 'Dalam batas normal', 'lab_abnormal_report' => 'Di luar batas normal',
        ]]];
    }

    #[DataProvider('configuredAnswerTypes')]
    public function test_text_and_number_keep_their_answer_type_and_render_configured_laboratory_columns(string $type, string $answer): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $subcategory = McuSubcategory::factory()->create(['name' => 'HEMATOLOGI']);
        $payload = $this->payload($subcategory);
        $payload['questions'][0]['input_type'] = $type;
        if ($type === 'text') {
            $payload['questions'][0]['lab_rule'] = 'text';
            $payload['questions'][0]['lab_normal_values'] = 'Negatif';
        }
        $this->post(route('mcu-item.store'), $payload)->assertSessionHasNoErrors();
        $item = McuItem::where('mcu_subcategory_id', $subcategory->id)->firstOrFail();
        $this->assertSame($type, $item->input_type);
        $this->assertSame($type, $item->lab_config['result_type']);
        $this->get(route('mcu-item.edit', $item))->assertOk()->assertSee('Mapping hasil laporan')->assertSee('value="'.$type.'" selected', false);
        $this->post(route('paket.store'), ['nama_paket' => 'Paket konfigurasi', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $package = Paket::firstOrFail();
        $this->assertSame('laboratory', $package->dynamic_schema[0]['items'][0]['type']);
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$item->mcu_category_id]))->assertOk()->assertSee('Nilai Normal')->assertSee('HEMATOLOGI');
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), ['section' => 'laboratorium', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => $answer]])->assertSessionHasNoErrors();
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Dalam batas normal');
        $schema = $package->dynamic_schema;
        $payload['questions'][0]['lab_reference'] = '';
        $this->put(route('mcu-item.update', $item), $payload)->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->lab_config);
        $this->assertSame($type, $item->fresh()->definition()['type']);
        $this->assertSame($schema, $package->fresh()->dynamic_schema);
    }

    public static function configuredAnswerTypes(): array
    {
        return [['text', 'Negatif'], ['number', '14']];
    }

    public function test_editor_has_only_radio_text_and_number_and_reads_legacy_laboratory_configuration(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('mcu-item.create'))->assertOk()->assertSee('Mapping hasil laporan')->assertSee('Hasil default (opsional)')
            ->assertDontSee('value="laboratory"', false)->assertDontSee('Konfigurasi laboratorium');
        $subcategory = McuSubcategory::factory()->create();
        $this->post(route('mcu-item.store'), $this->payload($subcategory))->assertSessionHasNoErrors();
        $item = McuItem::where('mcu_subcategory_id', $subcategory->id)->firstOrFail();
        $item->update(['input_type' => 'laboratory']);

        $this->get(route('mcu-item.edit', $item))->assertOk()->assertSee('value="number" selected', false)->assertDontSee('value="laboratory"', false)->assertSee('12–16');
        $this->put(route('mcu-item.update', $item), $this->payload($subcategory))->assertSessionHasNoErrors();
        $this->assertSame('number', $item->fresh()->input_type);
        $this->assertSame('laboratory', $item->fresh()->definition()['type']);
    }

    #[DataProvider('defaultResults')]
    public function test_default_results_prefill_only_unsaved_forms_and_require_explicit_saving(string $type, string $default): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $subcategory = McuSubcategory::factory()->create();
        $payload = $this->payload($subcategory);
        $payload['questions'][0]['input_type'] = $type;
        $payload['questions'][0]['lab_default_result'] = $default;
        if ($type === 'text') {
            $payload['questions'][0]['lab_rule'] = 'text';
            $payload['questions'][0]['lab_normal_values'] = 'Negatif';
        }
        $this->post(route('mcu-item.store'), $payload)->assertSessionHasNoErrors();
        $item = McuItem::where('mcu_subcategory_id', $subcategory->id)->firstOrFail();
        $this->assertSame($default, $item->lab_config['default_result']);
        $this->post(route('paket.store'), ['nama_paket' => 'Paket default', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $package = Paket::firstOrFail();
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);
        $section = 'kategori-'.$item->mcu_category_id;
        $form = route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $section]);
        $update = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $report = route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]);
        $config = $item->lab_config;
        $config['default_result'] = $type === 'text' ? 'Positif' : '99';
        $item->update(['lab_config' => $config]);

        $this->get($form)->assertOk()->assertSee('value="'.$default.'" class="min-h-12 w-36', false)->assertSee('Hasil default dari master — belum tersimpan');
        $this->assertDatabaseCount('anamnesas', 0);
        $this->get($report)->assertOk()->assertSee('Belum diisi')->assertDontSee('Dalam batas normal')->assertDontSee('Di luar batas normal');
        $this->put($update, ['section' => $section, 'status' => 'selesai', 'lock_version' => 0])->assertSessionHasErrors('kelengkapan');
        $this->put($update, ['section' => $section, 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => $default]])->assertSessionHasNoErrors();
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame($default, $exam->dynamic_answers[$item->id]);
        $this->get($form)->assertOk()->assertSee('value="'.$default.'" class="min-h-12 w-36', false)->assertDontSee('Hasil default dari master — belum tersimpan');
        $this->put($update, ['section' => $section, 'status' => 'draft', 'lock_version' => 1, 'dynamic_answers' => [$item->id => '']])->assertSessionHasNoErrors();
        $this->get($form)->assertOk()->assertSee('value="" class="min-h-12 w-36', false)->assertDontSee('Hasil default dari master — belum tersimpan');
        $this->assertNull($exam->fresh()->dynamic_answers[$item->id]);
    }

    public static function defaultResults(): array
    {
        return [['text', 'Negatif'], ['number', '0'], ['number', '14']];
    }

    public function test_default_does_not_overwrite_validation_input_or_appear_in_readonly_empty_results(): void
    {
        [$participant, $item] = $this->participant();
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['items'][0]['lab_config']['default_result'] = '14';
        $participant->paket->update(['dynamic_schema' => $schema]);
        $form = route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$item->mcu_category_id]);

        $this->withSession(['_old_input' => ['dynamic_answers' => [$item->id => '']]])->get($form)->assertOk()->assertSee('value="" class="min-h-12 w-36', false)->assertDontSee('Hasil default dari master — belum tersimpan');
        $this->withSession(['_old_input' => ['dynamic_answers' => [$item->id => '16']]])->get($form)->assertOk()->assertSee('value="16" class="min-h-12 w-36', false);
        session()->forget('_old_input');
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'dynamic_schema' => $schema]);
        $this->get($form)->assertOk()->assertSee('value="" class="min-h-12 w-36', false)->assertDontSee('Hasil default dari master — belum tersimpan');
    }

    /** @return array{Karyawan, McuItem} */
    private function participant(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $subcategory = McuSubcategory::factory()->create(['name' => 'HEMATOLOGI']);
        $this->post(route('mcu-item.store'), $this->payload($subcategory))->assertSessionHasNoErrors();
        $item = McuItem::where('mcu_subcategory_id', $subcategory->id)->firstOrFail();
        $this->post(route('paket.store'), ['nama_paket' => 'Paket Lab', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $package = Paket::firstOrFail();
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);

        return [$participant->fresh(), $item];
    }
}
