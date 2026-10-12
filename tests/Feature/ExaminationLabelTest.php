<?php

namespace Tests\Feature;

use App\LabelBarcode;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\McuSubcategory;
use App\Models\Paket;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExaminationLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_label_settings_can_be_saved_and_previewed_without_changing_package_snapshots(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        [$participant, $category] = $this->participant();
        $snapshot = $participant->paket->dynamic_schema;
        $this->get(route('mcu-category.edit', $category))->assertOk()->assertSee('Pengaturan label')->assertSee('Cetak contoh')->assertSee('Data contoh — bukan data pasien');
        $this->put(route('mcu-category.update', $category), ['name' => 'LAB', 'is_active' => 1, 'label_enabled' => 1, 'label_barcode_type' => 'code128', 'label_width_mm' => 100, 'label_height_mm' => 50])
            ->assertSessionHasNoErrors()->assertRedirect(route('mcu-category.index'));
        $this->assertSame(['label_enabled' => true, 'label_barcode_type' => 'code128', 'label_width_mm' => 100, 'label_height_mm' => 50], $category->fresh()->only(['label_enabled', 'label_barcode_type', 'label_width_mm', 'label_height_mm']));
        $this->assertSame($snapshot, $participant->paket->fresh()->dynamic_schema);
        $this->get(route('mcu-category.create'))->assertOk()->assertSee('Subkategori contoh');
    }

    public function test_invalid_settings_cannot_be_saved(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        $this->post(route('mcu-category.store'), ['name' => 'Label', 'is_active' => 1, 'label_enabled' => 1])->assertSessionHasErrors(['label_barcode_type', 'label_width_mm', 'label_height_mm']);
        $this->put(route('mcu-category.update', $category), ['name' => 'Label', 'is_active' => 1, 'label_enabled' => 1, 'label_barcode_type' => 'invalid', 'label_width_mm' => '40; color:red', 'label_height_mm' => 201])->assertSessionHasErrors(['label_barcode_type', 'label_width_mm', 'label_height_mm']);
        $this->assertFalse($category->fresh()->label_enabled);
    }

    public function test_identity_has_one_print_dropdown_and_only_package_subcategories(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        [$participant, $category, $subcategory] = $this->participant();
        McuSubcategory::factory()->create(['mcu_category_id' => $category->id, 'name' => 'Tidak termasuk paket']);
        $this->get(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'identitas']))->assertOk()
            ->assertSee('data-print-dropdown', false)->assertSee('Cetak PDF')->assertSee('Cetak label')
            ->assertSee('data-label-modal', false)->assertSee($subcategory->name)->assertDontSee('Tidak termasuk paket');
    }

    public function test_labels_use_snapshot_names_saved_identity_server_time_master_size_and_subcategory_examination_urls(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        [$participant, $category, $subcategory, $second] = $this->participant();
        $participant->update(['nama_karyawan' => '<script>Budi</script>', 'nip' => '001234']);
        $category->update(['name' => 'Master renamed', 'label_barcode_type' => 'code128', 'label_width_mm' => 120, 'label_height_mm' => 45]);
        $subcategory->update(['name' => 'Subcategory renamed']);
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(14, 30, 45));
        $response = $this->get(route('pemeriksaan.labels', [$participant->rencana_kunjungan_id, $participant, 'category' => $category->id, 'subcategories' => [$subcategory->id, $second->id]]));
        $response->assertOk()->assertSee('Hematologi - LAB')->assertSee('Elektrolit - LAB')
            ->assertSee('<script>Budi</script> - 001234')->assertDontSee('<script>Budi</script>', false)
            ->assertSee('12:10:2026 14:30:45')->assertSee('size: 120mm 45mm', false)
            ->assertDontSee('Master renamed')->assertDontSee('Subcategory renamed')->assertDontSee('sidebar');
        $this->assertSame(2, substr_count($response->getContent(), '<article class="mcu-label"'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $url = route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'kategori-'.$category->id, 'subcategory' => $subcategory->id]);
        $barcodes = (new LabelBarcode)->images($url);
        $response->assertSee($barcodes['code128'], false)->assertSee('data-label-layout="vertical"', false);
        $secondBarcodes = (new LabelBarcode)->images(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'kategori-'.$category->id, 'subcategory' => $second->id]));
        $response->assertSee($secondBarcodes['code128'], false);
        $this->assertNotSame($barcodes['code128'], $secondBarcodes['code128']);
        $identity = $this->get(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'identitas']))->assertOk();
        $this->assertSame($barcodes, $identity->viewData('labelBarcodes')[$category->id][$subcategory->id]);
        $category->update(['label_barcode_type' => 'qr']);
        $this->get(route('pemeriksaan.labels', [$participant->rencana_kunjungan_id, $participant, 'category' => $category->id, 'subcategories' => [$subcategory->id]]))->assertOk()->assertSee($barcodes['qr'], false)->assertSee('data-label-layout="horizontal"', false);
        $category->update(['label_width_mm' => 40, 'label_height_mm' => 80]);
        $this->get(route('pemeriksaan.labels', [$participant->rencana_kunjungan_id, $participant, 'category' => $category->id, 'subcategories' => [$subcategory->id]]))->assertOk()->assertSee('data-label-layout="vertical"', false);
    }

    public function test_print_rejects_foreign_subcategories_disabled_or_deleted_masters_and_mismatched_visits(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        [$participant, $category, $subcategory] = $this->participant();
        $foreign = McuSubcategory::factory()->create();
        $url = route('pemeriksaan.labels', [$participant->rencana_kunjungan_id, $participant]);
        foreach ([[], ['category' => $category->id, 'subcategories' => []], ['category' => $category->id, 'subcategories' => [$foreign->id]], ['category' => $category->id, 'subcategories' => [$subcategory->id, $subcategory->id]]] as $query) {
            $this->getJson($url.'?'.http_build_query($query))->assertUnprocessable();
        }
        $query = ['category' => $category->id, 'subcategories' => [$subcategory->id]];
        $category->update(['label_enabled' => false]);
        $this->getJson($url.'?'.http_build_query($query))->assertUnprocessable();
        $category->items()->delete();
        $category->subcategories()->delete();
        $category->delete();
        $this->getJson($url.'?'.http_build_query($query))->assertUnprocessable();
        $this->get(route('pemeriksaan.labels', [RencanaKunjungan::factory()->create(), $participant] + $query))->assertNotFound();
    }

    public function test_label_routes_require_login_and_examination_permission(): void
    {
        Storage::fake('local');
        [$participant, $category, $subcategory] = $this->participant();
        $url = route('pemeriksaan.labels', [$participant->rencana_kunjungan_id, $participant, 'category' => $category->id, 'subcategories' => [$subcategory->id]]);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'dokter']))->get($url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->get($url)->assertForbidden();
    }

    public function test_code128_encodes_start_b_data_weighted_checksum_stop_and_quiet_zones(): void
    {
        $barcode = new LabelBarcode;
        $this->assertSame([104, 33, 34, 35, 1, 106], $barcode->code128Symbols('ABC'));
        $images = $barcode->images('ABC');
        $this->assertSame(88, $images['modules']);
        $svg = base64_decode(substr($images['code128'], strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('viewBox="0 0 88 50"', $svg);
        $this->assertStringContainsString('<rect x="10" y="0" width="2" height="50"/>', $svg);
        $this->assertStringContainsString('shape-rendering="crispEdges"', $svg);
    }

    public function test_label_printing_keeps_photo_first_gate_and_final_results_read_only(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        [$participant, $category, $subcategory] = $this->participant();
        $participant->update(['foto' => null]);
        $url = route('pemeriksaan.labels', [$participant->rencana_kunjungan_id, $participant, 'category' => $category->id, 'subcategories' => [$subcategory->id]]);
        $this->get($url)->assertRedirect(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'identitas']));
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'dynamic_schema' => $participant->paket->dynamic_schema]);
        $before = $exam->fresh()->toArray();
        $this->get($url)->assertOk()->assertSee('Hematologi - LAB');
        $this->assertSame($before, $exam->fresh()->toArray());
    }

    public function test_labels_follow_examination_snapshot_when_package_is_changed_and_support_ungrouped_items(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        [$participant, $category] = $this->participant();
        $schema = $participant->paket->dynamic_schema;
        foreach ($schema[0]['items'] as &$item) {
            unset($item['subcategory']);
        }
        unset($item);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'dynamic_schema' => $schema]);
        $participant->paket->update(['dynamic_schema' => []]);

        $this->get(route('pemeriksaan.labels', [$participant->rencana_kunjungan_id, $participant, 'category' => $category->id, 'subcategories' => [$category->id]]))
            ->assertOk()->assertSee('Tanpa subkategori - LAB');
    }

    public function test_scanned_subcategory_link_survives_login_and_renders_matching_category_snapshot(): void
    {
        Storage::fake('local');
        [$participant, $category, $subcategory] = $this->participant();
        $subcategory->update(['name' => 'Master renamed']);
        $category->update(['name' => 'Master renamed category']);
        $url = route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'kategori-'.$category->id, 'subcategory' => $subcategory->id]);
        $operator = User::factory()->create(['role' => 'petugas', 'username' => 'operator-label']);
        $intendedUrl = route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'subcategory' => $subcategory->id, 'tab' => 'kategori-'.$category->id]);
        $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $intendedUrl);
        $this->post(route('login.store'), ['login' => 'operator-label', 'password' => 'password'])->assertRedirect($intendedUrl);
        $this->get($url)->assertOk()->assertViewHas('tab', 'kategori-'.$category->id)
            ->assertSee('data-exam-category="'.$category->id.'"', false)
            ->assertSee('data-exam-subcategory="'.$subcategory->id.'"', false)
            ->assertSee('Hematologi')->assertDontSee('Master renamed');
        $this->assertNull($participant->fresh()->anamnesa);
    }

    public function test_scan_target_is_retained_through_required_photo_and_invalid_target_cannot_redirect(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        [$participant, $category, $subcategory] = $this->participant();
        $participant->update(['foto' => null]);
        $url = route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'kategori-'.$category->id, 'subcategory' => $subcategory->id]);
        $this->get($url)->assertOk()->assertViewIs('mcu.pemeriksaan-foto')
            ->assertSee('name="return_tab" value="kategori-'.$category->id.'"', false)
            ->assertSee('name="return_subcategory" value="'.$subcategory->id.'"', false);
        $this->post(route('pemeriksaan.photo', [$participant->rencana_kunjungan_id, $participant]), [
            'foto_pasien' => UploadedFile::fake()->image('foto.jpg', 100, 100), 'return_tab' => 'kategori-'.$category->id, 'return_subcategory' => $subcategory->id,
        ])->assertSessionHasNoErrors()->assertRedirect($url);
        $this->get($url)->assertOk()->assertViewIs('mcu.pemeriksaan');
        $this->assertNull($participant->fresh()->anamnesa);
        $this->post(route('pemeriksaan.photo', [$participant->rencana_kunjungan_id, $participant]), [
            'foto_pasien' => UploadedFile::fake()->image('foto.jpg', 100, 100), 'return_tab' => 'https://invalid.example', 'return_subcategory' => $subcategory->id,
        ])->assertRedirect(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'identitas']));
    }

    /** @return array{Karyawan, McuCategory, McuSubcategory, McuSubcategory} */
    private function participant(): array
    {
        $category = McuCategory::factory()->create(['name' => 'LAB', 'label_enabled' => true]);
        $subcategory = McuSubcategory::factory()->create(['mcu_category_id' => $category->id, 'name' => 'Hematologi', 'sort_order' => 1]);
        $second = McuSubcategory::factory()->create(['mcu_category_id' => $category->id, 'name' => 'Elektrolit', 'sort_order' => 2]);
        $items = [$subcategory, $second];
        $definitions = array_map(fn (McuSubcategory $row): array => McuItem::factory()->create(['mcu_category_id' => $category->id, 'mcu_subcategory_id' => $row->id])->definition(), $items);
        $schema = [['id' => $category->id, 'name' => 'LAB', 'order' => 1, 'items' => $definitions]];
        $package = Paket::factory()->create(['dynamic_schema' => $schema]);
        $participant = Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id]);

        return [$participant, $category, $subcategory, $second];
    }
}
