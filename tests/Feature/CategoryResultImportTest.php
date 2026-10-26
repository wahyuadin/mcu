<?php

namespace Tests\Feature;

use App\CategoryResults;
use App\McuReport;
use App\Models\CategoryResultImport;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryResultImportTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return ['admin' => ['admin', true], 'dokter' => ['dokter', true], 'petugas' => ['petugas', false], 'hrd' => ['hrd', false], 'viewer' => ['viewer', false]];
    }

    #[DataProvider('roles')]
    public function test_only_doctor_and_master_admin_have_import_permission(string $role, bool $allowed): void
    {
        [$participant, $category] = $this->participant();
        $user = User::factory()->create(['role' => $role]);
        $this->assertSame($allowed, Gate::forUser($user)->allows('import-category-results'));
        $this->actingAs($user);
        $url = route('category-import.categories', $participant->rencanaKunjungan);
        if ($allowed) {
            $this->getJson($url)->assertOk()->assertJsonPath('categories.0.id', $category->id);
            $this->get(route('kunjungan-mcu.index'))->assertOk()->assertDontSee('data-category-import-open', false);
            $this->get(route('kunjungan-mcu.show', [$participant->rencanaKunjungan, 'status' => 'kategori-'.$category->id]))->assertOk()->assertDontSee('data-category-import-open', false)->assertDontSee('data-category-import', false);
            $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$category->id]))->assertOk()->assertSee('data-category-import-open', false)->assertSee('Download template');
        } else {
            $this->getJson($url)->assertForbidden();
            $this->get(route('category-import.template', [$participant->rencanaKunjungan, $category]))->assertForbidden();
            $this->postJson(route('category-import.store', [$participant->rencanaKunjungan, $category]), [])->assertForbidden();
            if ($role === 'petugas') {
                $this->get(route('kunjungan-mcu.index'))->assertOk()->assertDontSee('data-category-import-open', false)->assertDontSee('data-category-import', false);
                $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$category->id]))->assertOk()->assertDontSee('data-category-import-open', false)->assertDontSee('data-category-import', false);
            }
        }
    }

    public function test_guests_and_other_roles_cannot_confirm_or_download_import_reports(): void
    {
        [$participant, $category] = $this->participant();
        $url = route('category-import.template', [$participant->rencanaKunjungan, $category]);
        $this->get($url)->assertRedirect(route('login'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $payload = $this->stage($participant, $category, []);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertForbidden();
        $this->get($payload['report_url'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'dokter']));
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertNotFound();
        $this->get($payload['report_url'])->assertNotFound();
        $this->assertNull($participant->fresh()->anamnesa);
    }

    public function test_template_contains_registered_patients_and_snapshot_items_not_later_master_edits(): void
    {
        [$participant, $category, $radio, $number] = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'dokter']));
        $radio->update(['label' => 'Label baru tidak boleh masuk snapshot', 'is_active' => false]);
        $book = (new CategoryResults)->template($participant->rencanaKunjungan, $category);
        $sheet = $book->getActiveSheet();
        $this->assertSame($participant->nomor_mcu, $sheet->getCell('A3')->getValue());
        $this->assertSame($participant->nama_karyawan, $sheet->getCell('C3')->getValue());
        $this->assertFalse($sheet->getRowDimension(1)->getVisible());
        $this->assertSame('Riwayat snapshot [diisi Ya/Tidak]', $sheet->getCell('D2')->getValue());
        $this->assertStringNotContainsString('Label baru tidak boleh', json_encode($sheet->toArray()));
        $this->assertStringContainsString('Riwayat snapshot', json_encode($sheet->toArray()));
        $this->get(route('category-import.template', [$participant->rencanaKunjungan, $category]))->assertOk()->assertDownload()->assertHeader('Cache-Control', 'no-store, private');
        $book->disconnectWorksheets();
    }

    public function test_upload_previews_then_imports_as_draft_with_private_source_and_idempotent_confirmation(): void
    {
        [$participant, $category, $radio, $number] = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'dokter']));
        $payload = $this->stage($participant, $category, ['d_'.$radio->id => 'Ya', 'note:d_'.$radio->id => 'Sejak tahun 2020', 'd_'.$number->id => '0']);
        $this->assertSame(1, $payload['stats']['ready']);
        $this->assertNull($participant->fresh()->anamnesa);
        $raw = DB::table('category_result_imports')->where('id', $payload['id'])->value('source');
        $this->assertStringNotContainsString('Sejak tahun 2020', $raw);
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertOk()->assertJsonPath('stats.succeeded', 1);

        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('YA', $exam->dynamic_answers[$radio->id]);
        $this->assertSame('0', $exam->dynamic_answers[$number->id]);
        $this->assertSame('Sejak tahun 2020', $exam->dynamic_notes[$radio->id]);
        $this->assertSame('draft', $exam->status);
        $this->assertSame('draft', $exam->section_status['kategori-'.$category->id]);
        $this->assertSame('proses', $participant->fresh()->status_pemeriksaan);
        $version = $exam->lock_version;
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertOk()->assertJsonPath('stats.succeeded', 1);
        $this->assertSame($version, $exam->fresh()->lock_version);
        $this->get($payload['report_url'])->assertOk()->assertDownload();
    }

    public function test_existing_answers_are_never_overwritten_and_radio_no_ignores_notes(): void
    {
        [$participant, $category, $radio, $number] = $this->participant();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => [], 'dynamic_schema' => $participant->paket->dynamic_schema, 'dynamic_answers' => [$number->id => '99'], 'bmi' => 22.49, 'kategori_bmi' => 'Normal', 'section_status' => ['fisik' => 'selesai']]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = $this->stage($participant, $category, ['d_'.$radio->id => 'TIDAK', 'note:d_'.$radio->id => 'Jangan disimpan', 'd_'.$number->id => '12']);
        $this->assertSame(1, $payload['rows'][0]['retained']);
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertOk();

        $this->assertSame('99', $exam->fresh()->dynamic_answers[$number->id]);
        $this->assertSame('TIDAK', $exam->fresh()->dynamic_answers[$radio->id]);
        $this->assertEmpty($exam->fresh()->dynamic_notes);
        $this->assertSame('22.49', $exam->fresh()->bmi);
        $this->assertSame('selesai', $exam->fresh()->section_status['fisik']);
    }

    public function test_invalid_rows_are_reported_and_nothing_is_written_on_confirmation(): void
    {
        [$participant, $category, $radio, $number] = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = $this->stage($participant, $category, ['d_'.$radio->id => 'Normal', 'd_'.$number->id => 'not-number']);
        $this->assertSame(1, $payload['stats']['failed']);
        $this->assertCount(2, $payload['rows'][0]['errors']);
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertUnprocessable();
        $this->assertNull($participant->fresh()->anamnesa);
        $this->get($payload['report_url'])->assertOk()->assertDownload();
    }

    public function test_stale_preview_cannot_overwrite_a_concurrent_save(): void
    {
        [$participant, $category, $radio] = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = $this->stage($participant, $category, ['d_'.$radio->id => 'YA']);
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => [], 'dynamic_schema' => $participant->paket->dynamic_schema, 'dynamic_answers' => [$radio->id => 'TIDAK'], 'lock_version' => 1]);

        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertUnprocessable()->assertJsonValidationErrors('import_file');

        $this->assertSame('TIDAK', $exam->fresh()->dynamic_answers[$radio->id]);
        $this->assertSame(1, $exam->fresh()->lock_version);
    }

    public function test_final_exams_missing_photos_inactive_categories_and_closed_visits_are_rejected(): void
    {
        [$participant, $category, $radio] = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant->update(['foto' => null]);
        $payload = $this->stage($participant, $category, ['d_'.$radio->id => 'YA']);
        $this->assertSame(1, $payload['stats']['failed']);
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertUnprocessable();
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'dynamic_schema' => $participant->paket->dynamic_schema]);
        $payload = $this->stage($participant, $category, ['d_'.$radio->id => 'YA']);
        $this->assertSame(1, $payload['stats']['failed']);
        $category->update(['import_enabled' => false]);
        $this->get(route('category-import.template', [$participant->rencanaKunjungan, $category]))->assertForbidden();
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertForbidden();
        $category->update(['import_enabled' => true]);
        $participant->rencanaKunjungan->update(['is_closing' => true]);
        $this->getJson(route('category-import.categories', $participant->rencanaKunjungan))->assertForbidden();
        $this->assertNull($participant->fresh()->anamnesa->dynamic_answers);
    }

    public function test_file_formulas_cross_visit_identifiers_and_expired_batches_are_rejected(): void
    {
        [$participant, $category, $radio] = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = $this->stage($participant, $category, ['d_'.$radio->id => '=1+1'], formula: true);
        $this->assertSame(1, $payload['stats']['failed']);
        $other = Karyawan::factory()->create();
        $payload = $this->stage($participant, $category, ['nomor_mcu' => $other->nomor_mcu, 'd_'.$radio->id => 'YA']);
        $this->assertSame(1, $payload['stats']['failed']);
        CategoryResultImport::findOrFail($payload['id'])->update(['expires_at' => now()->subMinute()]);
        $this->get($payload['report_url'])->assertStatus(410);
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertStatus(410);
        $this->assertNull($participant->fresh()->anamnesa);
        $this->assertNull($other->fresh()->anamnesa);
    }

    public function test_builtin_category_settings_are_editable_but_deletion_stays_protected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['anamnesa_riwayat', 'pemeriksaan_fisik'] as $legacyGroup) {
            $category = McuCategory::where('legacy_group', $legacyGroup)->sole();
            $this->get(route('mcu-category.edit', $category))->assertOk()->assertSee('name="label_enabled"', false)->assertSee('name="label_barcode_type"', false)->assertSee('name="import_enabled"', false);
            $this->put(route('mcu-category.update', $category), ['name' => $category->name.' diperbarui', 'is_active' => 0, 'import_enabled' => 1, 'label_enabled' => 1, 'label_barcode_type' => 'qr', 'label_width_mm' => 90, 'label_height_mm' => 50])->assertSessionHasNoErrors();
            $this->assertSame($legacyGroup, $category->fresh()->legacy_group);
            $this->assertFalse($category->fresh()->is_active);
            $this->assertTrue($category->fresh()->import_enabled);
            $this->assertTrue($category->fresh()->label_enabled);
            $this->delete(route('mcu-category.destroy', $category))->assertSessionHasErrors('category');
        }
    }

    public function test_legacy_anamnesis_and_physical_results_can_be_imported_without_rewriting_package_snapshots(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = Karyawan::factory()->withPhoto()->create();
        $historyCategory = McuCategory::where('legacy_group', 'anamnesa_riwayat')->sole();
        $historyCategory->update(['import_enabled' => true]);
        $payload = $this->stage($participant, $historyCategory, ['a_hipertensi' => 'YA', 'note:a_hipertensi' => 'Sejak 2021']);
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertOk();
        $this->assertSame(['answer' => 'Ya', 'note' => 'Sejak 2021'], $participant->fresh()->anamnesa->anamnesa_data['hipertensi']);

        $physical = Karyawan::factory()->withPhoto()->create();
        $physical->paket->update(['active_items' => ['tinggi_badan' => true, 'berat_badan' => true, 'bmi' => true]]);
        $category = McuCategory::where('legacy_group', 'pemeriksaan_fisik')->sole();
        $category->update(['import_enabled' => true]);
        $before = $physical->paket->fresh()->toArray();
        $payload = $this->stage($physical, $category, ['p_tinggi_badan' => '170', 'p_berat_badan' => '65']);
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertOk();
        $this->assertSame('22.49', $physical->fresh()->anamnesa->bmi);
        $this->assertSame('Normal', $physical->fresh()->anamnesa->kategori_bmi);
        $this->assertSame($before, $physical->paket->fresh()->toArray());
    }

    public function test_dynamic_physical_import_uses_snapshot_measurement_keys_for_bmi(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'dokter']));
        $category = McuCategory::where('legacy_group', 'pemeriksaan_fisik')->sole();
        $category->update(['import_enabled' => true]);
        $height = $category->items()->where('legacy_key', 'tinggi_badan')->sole();
        $weight = $category->items()->where('legacy_key', 'berat_badan')->sole();
        $package = Paket::factory()->create(['active_items' => [], 'dynamic_schema' => [['id' => $category->id, 'name' => $category->name, 'order' => 1, 'section' => 'fisik', 'items' => [$height->definition(), $weight->definition()]]]]);
        $participant = Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id]);

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'fisik']))->assertOk()->assertSee('data-category-id="'.$category->id.'"', false);

        $payload = $this->stage($participant, $category, ['d_'.$height->id => '170', 'd_'.$weight->id => '65']);
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertOk();

        $this->assertSame('22.49', $participant->fresh()->anamnesa->bmi);
        $this->assertSame('draft', $participant->fresh()->anamnesa->section_status['fisik']);
    }

    public function test_non_bound_cells_are_marked_not_applicable_and_block_atomic_import_if_filled(): void
    {
        [$participant, $category, $radio, $number] = $this->participant();
        $otherPackage = Paket::factory()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'perusahaan_id' => $participant->perusahaan_id, 'active_items' => [],
            'dynamic_schema' => [['id' => $category->id, 'name' => $category->name, 'order' => 0, 'items' => [$radio->definition()]]]]);
        $other = Karyawan::factory()->withPhoto()->create(['paket_id' => $otherPackage->id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $book = (new CategoryResults)->template($participant->rencanaKunjungan, $category);
        $this->assertContains(CategoryResults::NOT_APPLICABLE, $book->getActiveSheet()->rangeToArray('A4:'.$book->getActiveSheet()->getHighestDataColumn().'4')[0]);
        $book->disconnectWorksheets();
        $payload = $this->stage($participant, $category, ['d_'.$radio->id => 'YA'], additional: [4 => ['d_'.$number->id => '12']]);
        $this->assertSame(1, $payload['stats']['ready']);
        $this->assertSame(1, $payload['stats']['failed']);
        $this->postJson($payload['confirm_url'], ['digest' => $payload['digest'], 'confirmed' => 1])->assertUnprocessable();
        $this->assertNull($participant->fresh()->anamnesa);
        $this->assertNull($other->fresh()->anamnesa);
    }

    public function test_admin_can_create_doctor_users_without_granting_master_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('users.store'), ['name' => 'Dokter import', 'username' => 'dokter.import', 'email' => 'dokter.import@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'dokter'])->assertSessionHasNoErrors();
        $doctor = User::where('username', 'dokter.import')->sole();
        $this->assertTrue($doctor->can('import-category-results'));
        $this->assertFalse($doctor->can('manage-mcu'));
        $this->actingAs($doctor)->get(route('mcu-category.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertRedirect(route('kunjungan-mcu.index'));
    }

    public function test_current_master_names_and_order_are_shared_by_tabs_summary_review_and_report_without_changing_snapshots(): void
    {
        [$participant, $category, $radio] = $this->participant();
        $physical = McuCategory::where('legacy_group', 'pemeriksaan_fisik')->sole();
        $height = $physical->items()->where('legacy_key', 'tinggi_badan')->sole();
        $schema = [...$participant->paket->dynamic_schema, ['id' => $physical->id, 'name' => 'Nama fisik lama', 'order' => 99, 'items' => [$height->definition()]]];
        $participant->paket->update(['dynamic_schema' => $schema]);
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => [], 'dynamic_schema' => $schema]);
        $physical->update(['name' => 'Antropometri terbaru', 'sort_order' => -20]);
        $category->update(['name' => 'Riwayat terbaru', 'sort_order' => -10]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        foreach (['ringkasan', 'review'] as $tab) {
            $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $tab]))->assertOk()
                ->assertViewHas('tabs', function (array $tabs) use ($category, $physical): bool {
                    $keys = array_keys($tabs);

                    return $tabs['fisik'] === 'Antropometri terbaru' && $tabs['kategori-'.$category->id] === 'Riwayat terbaru'
                        && ! isset($tabs['kategori-'.$physical->id]) && array_search('fisik', $keys) < array_search('kategori-'.$category->id, $keys);
                });
        }
        $sections = (new McuReport)->sections($participant->fresh(), $exam);
        $this->assertSame(['Antropometri terbaru', 'Riwayat terbaru'], array_column($sections, 'title'));
        $this->assertSame('Antropometri terbaru', $exam->workflowProgress($participant)['fisik']['label']);
        $this->assertSame($schema, $exam->fresh()->dynamic_schema);
        $this->assertSame($schema, $participant->paket->fresh()->dynamic_schema);
    }

    public function test_import_button_follows_active_category_and_template_ignores_search_and_pagination(): void
    {
        [$participant, $category] = $this->participant();
        $other = Karyawan::factory()->withPhoto()->create(['paket_id' => $participant->paket_id]);
        $this->actingAs(User::factory()->create(['role' => 'dokter']));
        $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertOk()->assertDontSee('data-category-import-open', false);
        $url = route('kunjungan-mcu.show', [$participant->rencanaKunjungan, 'status' => 'kategori-'.$category->id, 'q' => 'no-matches']);
        $this->get($url)->assertOk()->assertDontSee('data-category-import-open', false)->assertDontSee('data-category-import', false);
        $examUrl = route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$category->id]);
        $this->get($examUrl)->assertOk()->assertSee('data-category-id="'.$category->id.'"', false)->assertSee('bukan hanya peserta yang sedang dibuka');
        $book = (new CategoryResults)->template($participant->rencanaKunjungan, $category);
        $sheet = $book->getActiveSheet();
        $this->assertSame(4, $sheet->getHighestDataRow());
        $visibleHeaders = $sheet->rangeToArray('A2:'.$sheet->getHighestDataColumn().'2')[0];
        $this->assertSame(['nomor_mcu', 'nip', 'nama'], array_slice($visibleHeaders, 0, 3));
        $this->assertStringNotContainsString($category->id, implode(' ', $visibleHeaders));
        $this->assertContains('Angka snapshot [diisi angka]', $visibleHeaders);
        $book->disconnectWorksheets();
        $category->update(['import_enabled' => false]);
        $this->get($examUrl)->assertOk()->assertDontSee('data-category-import-open', false);
    }

    public function test_doctor_can_reach_category_import_but_cannot_edit_revise_or_finalize_examinations(): void
    {
        [$participant, $category] = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'dokter']));
        $parameters = [$participant->rencanaKunjungan, $participant];
        $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertOk()->assertSee(route('pemeriksaan.edit', $parameters));
        $this->get(route('pemeriksaan.edit', [...$parameters, 'tab' => 'kategori-'.$category->id]))->assertOk()
            ->assertViewHas('readonly', true)->assertSee('data-category-import-open', false)->assertDontSee('Simpan draft');
        $this->put(route('pemeriksaan.update', $parameters), ['status' => 'draft'])->assertForbidden();
        $this->post(route('pemeriksaan.finalize', $parameters), ['confirmed' => 1])->assertForbidden();
        $this->post(route('pemeriksaan.revise', $parameters), ['reason' => 'Tidak diizinkan'])->assertForbidden();
        $this->get(route('pemeriksaan.edit', [...$parameters, 'tab' => 'identitas']))->assertOk()->assertDontSee('data-print-dropdown', false);
        $other = Karyawan::factory()->create();
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $other]))->assertNotFound();
        $this->assertNull($participant->fresh()->anamnesa);
    }

    public function test_import_trigger_is_only_on_the_enabled_active_exam_tab_and_not_on_system_tabs_or_locked_visits(): void
    {
        [$participant, $category] = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $parameters = [$participant->rencanaKunjungan, $participant];
        foreach (['ringkasan', 'identitas', 'anamnesa', 'fisik', 'kesimpulan', 'review', 'invalid-tab'] as $tab) {
            $this->get(route('pemeriksaan.edit', [...$parameters, 'tab' => $tab]))->assertOk()->assertDontSee('data-category-import-open', false);
        }
        $url = route('pemeriksaan.edit', [...$parameters, 'tab' => 'kategori-'.$category->id]);
        $this->get($url)->assertOk()->assertSee('data-category-import-open', false);
        $category->update(['is_active' => false]);
        $this->get($url)->assertOk()->assertDontSee('data-category-import-open', false);
        $category->update(['is_active' => true]);
        $participant->rencanaKunjungan->update(['status' => 'completed']);
        $this->get($url)->assertOk()->assertDontSee('data-category-import-open', false);
        $participant->rencanaKunjungan->update(['status' => 'ongoing', 'is_closing' => true]);
        $this->get($url)->assertOk()->assertDontSee('data-category-import-open', false);
    }

    /** @return array{Karyawan, McuCategory, McuItem, McuItem} */
    private function participant(): array
    {
        $category = McuCategory::factory()->create(['name' => 'Kategori import dinamis', 'import_enabled' => true]);
        $radio = McuItem::factory()->create(['mcu_category_id' => $category->id, 'input_type' => 'radio', 'label' => 'Riwayat snapshot', 'yes_report' => 'Ada', 'no_report' => 'Tidak ada']);
        $number = McuItem::factory()->create(['mcu_category_id' => $category->id, 'input_type' => 'number', 'label' => 'Angka snapshot']);
        $package = Paket::factory()->create(['active_items' => [], 'dynamic_schema' => [['id' => $category->id, 'name' => $category->name, 'order' => 0, 'items' => [$radio->definition(), $number->definition()]]]]);

        return [Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id]), $category, $radio, $number];
    }

    /** @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function stage(Karyawan $participant, McuCategory $category, array $values, bool $formula = false, array $additional = []): array
    {
        $book = (new CategoryResults)->template($participant->rencanaKunjungan, $category);
        $sheet = $book->getActiveSheet();
        foreach ([3 => $values] + $additional as $rowNumber => $rowValues) {
            foreach ($sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0] as $index => $header) {
                $key = preg_match('/\[([^\]]+)\]$/', $header, $match) ? $match[1] : $header;
                if (array_key_exists($key, $rowValues)) {
                    $sheet->setCellValueExplicit([$index + 1, $rowNumber], $rowValues[$key], $formula && str_starts_with($rowValues[$key], '=') ? DataType::TYPE_FORMULA : DataType::TYPE_STRING);
                }
            }
        }
        $path = Storage::disk('local')->path('result-'.Str::uuid().'.xlsx');
        (new Xlsx($book))->setPreCalculateFormulas(false)->save($path);
        $book->disconnectWorksheets();

        return $this->postJson(route('category-import.store', [$participant->rencanaKunjungan, $category]), ['import_file' => new UploadedFile($path, 'hasil.xlsx', null, null, true)])->assertOk()->json();
    }
}
