<?php

namespace Tests\Feature;

use App\McuReport;
use App\Models\Anamnesa;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use App\ParticipantAnamnesaForm;
use Database\Seeders\McuTemplateMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DynamicAnamnesisTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_category_and_items_exist_and_the_category_cannot_be_deleted_individually_or_in_bulk(): void
    {
        $category = McuCategory::where('legacy_group', 'anamnesa_riwayat')->firstOrFail();
        $this->assertSame(36, $category->items()->count());
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('mcu-category.index'))->assertOk()->assertDontSee('aria-label="Hapus Anamnesis"', false);
        $this->delete(route('mcu-category.destroy', $category))->assertSessionHasErrors('category');
        $category->items()->delete();
        $category->subcategories()->delete();
        $this->delete(route('mcu-category.destroy', $category))->assertSessionHasErrors('category');
        $this->post(route('mcu-category.bulk-destroy'), ['ids' => [$category->id]])->assertSessionHas('bulkDeleteResults', fn ($rows): bool => $rows[0]['deleted'] === false);

        $this->assertModelExists($category);
    }

    public function test_category_settings_and_items_can_be_edited_and_disabled_without_removing_builtin_identity(): void
    {
        $category = McuCategory::where('legacy_group', 'anamnesa_riwayat')->firstOrFail();
        $item = $category->items()->where('legacy_key', 'keluhan_saat_ini')->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('mcu-category.edit', $category))->assertOk()->assertSee('name="is_active"', false)->assertSee('name="label_enabled"', false);
        $this->put(route('mcu-category.update', $category), ['name' => 'Anamnesis disesuaikan', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->put(route('mcu-category.update', $category), ['name' => 'Anamnesis disesuaikan', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($category->fresh()->is_active);
        $this->assertTrue($category->fresh()->isAnamnesis());
        $this->put(route('mcu-item.update', $item), ['mcu_category_id' => $category->id, 'label' => 'Keluhan saat bekerja', 'input_type' => 'text', 'is_required' => 0, 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Keluhan saat bekerja', $item->fresh()->label);
        $this->assertFalse($item->fresh()->is_active);
        $this->post(route('paket.store'), ['nama_paket' => 'Item nonaktif', 'master_items' => [$item->id]])->assertSessionHasErrors('master_items.0');
        $this->post(route('paket.store'), ['nama_paket' => 'Jalur lama nonaktif', 'items' => ['keluhan_saat_ini']])->assertSessionHasErrors('items');
    }

    public function test_new_items_render_in_the_existing_anamnesis_tab_and_are_required_for_completion(): void
    {
        [$participant, $item] = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'anamnesa']))->assertOk()
            ->assertSee('name="dynamic_answers['.$item->id.']"', false)->assertSee($item->label)
            ->assertViewHas('tabs', fn ($tabs): bool => ! isset($tabs['kategori-'.$item->mcu_category_id]));
        $this->put($url, ['section' => 'anamnesa', 'status' => 'selesai', 'lock_version' => 0])->assertSessionHasErrors('kelengkapan');
        $this->assertNull($participant->fresh()->anamnesa);
        $this->put($url, ['section' => 'anamnesa', 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => 'Jawaban pasien']])->assertSessionHasNoErrors();

        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('Jawaban pasien', $exam->dynamic_answers[$item->id]);
        $this->assertSame('Selesai', $exam->workflowProgress($participant)['anamnesa']['status']);
        $this->assertSame([], $exam->categoryGroups($participant));
        $this->assertSame([], array_filter($exam->finalizationErrors($participant), fn (string $error): bool => str_contains($error, 'Anamnesa')));
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Jawaban pasien');
    }

    public function test_snapshot_answers_and_reports_survive_later_master_edits_or_deactivation(): void
    {
        [$participant, $item] = $this->participant();
        $original = $item->label;
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => $participant->paket->dynamic_schema, 'dynamic_answers' => [$item->id => 'Hasil lama']]);
        $item->update(['label' => 'Pertanyaan baru', 'is_active' => false]);

        $sections = (new McuReport)->sections($participant, $participant->anamnesa);

        $this->assertSame('Anamnesis', $sections[0]['title']);
        $this->assertSame($original, $sections[0]['groups'][0]['rows'][0]['label']);
        $this->assertSame('Hasil lama', $sections[0]['groups'][0]['rows'][0]['value']);
    }

    public function test_legacy_anamnesis_is_not_replaced_and_mixed_report_has_one_history_section(): void
    {
        [$participant, $item] = $this->participant();
        $exam = new Anamnesa(['package_items' => ['hipertensi' => true], 'anamnesa_data' => ['hipertensi' => ['answer' => 'Tidak', 'note' => null]], 'dynamic_schema' => $participant->paket->dynamic_schema, 'dynamic_answers' => [$item->id => 'Tambahan']]);

        $sections = (new McuReport)->sections($participant, $exam);

        $this->assertCount(1, $sections);
        $this->assertStringContainsString('Hipertensi', json_encode($sections));
        $this->assertStringContainsString('Tambahan', json_encode($sections));
    }

    public function test_anamnesis_attachments_are_private_and_other_category_answers_are_rejected(): void
    {
        [$participant, $item] = $this->participant();
        $item->update(['allow_attachment' => true]);
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['items'][0] = $item->definition();
        $participant->paket->update(['dynamic_schema' => $schema]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);

        $this->put($url, ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => ['not-in-package' => 'forged']])->assertSessionHasErrors('dynamic_answers');
        $this->put($url, ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0, 'dynamic_files' => [$item->id => UploadedFile::fake()->image('scan.png')]])->assertSessionHasNoErrors();

        Storage::disk('local')->assertExists($participant->fresh()->anamnesa->dynamic_attachments[$item->id]['path']);
    }

    public function test_public_questionnaire_includes_dynamic_anamnesis_and_review_loads_the_answer(): void
    {
        [$participant, $item] = $this->participant();
        $item->update(['input_type' => 'radio', 'yes_report' => 'Ada riwayat', 'no_report' => 'Tidak ada riwayat']);
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['items'][0] = $item->definition();
        $participant->paket->update(['dynamic_schema' => $schema]);
        $groups = ParticipantAnamnesaForm::groups($participant);
        $this->assertSame('choice', collect($groups)->collapse()[$item->id]['type']);
        $participant->anamnesa_submission = ['id' => 'submission-test', 'groups' => $groups, 'answers' => [$item->id => ['answer' => 'Ya', 'note' => null]], 'submitted_at' => now()->toIso8601String(), 'reviewed_at' => null];
        $participant->save();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'anamnesa', 'review_submission' => 1]))->assertOk()->assertSee('value="YA" checked', false);
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), ['section' => 'anamnesa', 'status' => 'selesai', 'lock_version' => 0, 'submission_id' => 'submission-test', 'dynamic_answers' => [$item->id => 'YA']])->assertSessionHasNoErrors();

        $this->assertSame('YA', $participant->fresh()->anamnesa->dynamic_answers[$item->id]);
        $this->assertNotNull($participant->fresh()->anamnesa_submission['reviewed_at']);
    }

    public function test_template_reseeding_preserves_edited_deactivated_anamnesis_items_and_subcategories(): void
    {
        $category = McuCategory::where('legacy_group', 'anamnesa_riwayat')->sole();
        $item = $category->items()->where('legacy_key', 'hepatitis')->sole();
        $item->update(['label' => 'Riwayat hepatitis disesuaikan', 'is_active' => false, 'sort_order' => 87]);
        $subcategory = $item->subcategory;
        $subcategory->update(['name' => 'Riwayat khusus', 'is_active' => false]);
        $beforeItem = $item->fresh()->toArray();
        $beforeSubcategory = $subcategory->fresh()->toArray();

        $this->seed(McuTemplateMasterSeeder::class);
        $this->seed(McuTemplateMasterSeeder::class);

        $this->assertSame($beforeItem, $item->fresh()->toArray());
        $this->assertSame($beforeSubcategory, $subcategory->fresh()->toArray());
        $this->assertSame(36, $category->items()->count());
    }

    public function test_all_optional_public_questions_can_be_omitted_without_creating_an_exam(): void
    {
        [$participant, $item] = $this->participant();
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['items'][0]['required'] = false;
        $participant->paket->update(['dynamic_schema' => $schema]);
        $token = $this->identifyPublicParticipant($participant);

        $response = $this->get(route('anamnesa.public', $token))->assertOk()->assertSee('(opsional)');
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->assertSame(0, (new \DOMXPath($document))->query('//*[@name="answers['.$item->id.'][answer]" and @required]')->length);
        $this->post(route('anamnesa.submit', $token), ['confirmed' => 1])->assertSessionHasNoErrors();

        $this->assertSame([], $participant->fresh()->anamnesa_submission['answers']);
        $this->assertNull($participant->fresh()->anamnesa);
    }

    public function test_public_dynamic_numeric_answers_reject_text_and_accept_zero(): void
    {
        [$participant, $item] = $this->participant();
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['items'][0]['type'] = 'number';
        $participant->paket->update(['dynamic_schema' => $schema]);
        $token = $this->identifyPublicParticipant($participant);
        $url = route('anamnesa.submit', $token);

        $this->post($url, ['confirmed' => 1, 'answers' => [$item->id => ['answer' => 'bukan angka']]])->assertSessionHasErrors('answers.'.$item->id.'.answer');
        $this->assertNull($participant->fresh()->anamnesa_submission);
        $this->post($url, ['confirmed' => 1, 'answers' => [$item->id => ['answer' => '0']]])->assertSessionHasNoErrors();

        $this->assertSame('0', $participant->fresh()->anamnesa_submission['answers'][$item->id]['answer']);
        $this->assertNull($participant->fresh()->anamnesa);
    }

    public function test_public_numeric_answers_use_the_same_range_as_operator_answers(): void
    {
        [$participant, $item] = $this->participant();
        $schema = $participant->paket->dynamic_schema;
        $schema[0]['items'][0]['type'] = 'number';
        $participant->paket->update(['dynamic_schema' => $schema]);
        $token = $this->identifyPublicParticipant($participant);

        $this->post(route('anamnesa.submit', $token), ['confirmed' => 1, 'answers' => [$item->id => ['answer' => '2001.5']]])->assertSessionHasNoErrors();

        $this->assertSame('2001.5', $participant->fresh()->anamnesa_submission['answers'][$item->id]['answer']);
    }

    public function test_finalized_dynamic_anamnesis_and_hrd_write_boundaries_are_enforced(): void
    {
        [$participant, $item] = $this->participant();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => $participant->paket->dynamic_schema, 'dynamic_answers' => [$item->id => 'Jawaban final']]);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $payload = ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0, 'dynamic_answers' => [$item->id => 'Diubah']];

        $this->put($url, $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]))->put($url, $payload)->assertForbidden();

        $this->assertSame('Jawaban final', $exam->fresh()->dynamic_answers[$item->id]);
    }

    private function identifyPublicParticipant(Karyawan $participant): string
    {
        $this->post(route('anamnesa.barcode', $participant->rencanaKunjungan))->assertOk();
        $token = $participant->rencanaKunjungan->fresh()->anamnesa_token;
        $this->post(route('logout'));
        $this->post(route('anamnesa.identify', $token), ['nip' => $participant->nip, 'tanggal_lahir' => $participant->tanggal_lahir->format('Y-m-d')])->assertSessionHasNoErrors();

        return $token;
    }

    /** @return array{Karyawan, McuItem} */
    private function participant(): array
    {
        $category = McuCategory::where('legacy_group', 'anamnesa_riwayat')->firstOrFail();
        $item = McuItem::factory()->create(['mcu_category_id' => $category->id, 'label' => 'Riwayat tambahan pasien', 'input_type' => 'text', 'is_required' => true]);
        $package = Paket::factory()->create(['active_items' => []]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('paket.update', $package), ['nama_paket' => $package->nama_paket, 'master_items' => [$item->id]])->assertSessionHasNoErrors();

        return [Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id]), $item];
    }
}
