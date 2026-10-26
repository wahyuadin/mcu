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
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\McuTemplateMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TemplateMasterSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_only_template_definitions_in_order_with_no_example_clinical_data(): void
    {
        foreach (['HASIL PEMERIKSAAN FISIK' => 'Kulit', 'ANAMNESA PASIEN' => 'Keluhan Sekarang'] as $name => $label) {
            $category = McuCategory::factory()->create(['name' => $name]);
            $subcategory = McuSubcategory::factory()->create(['mcu_category_id' => $category->id]);
            McuItem::factory()->create(['mcu_category_id' => $category->id, 'mcu_subcategory_id' => $subcategory->id, 'label' => $label]);
        }

        $this->seed(McuTemplateMasterSeeder::class);

        $this->assertSame(['LAB', 'HASIL PEMERIKSAAN RONTGEN THORAKS', 'HASIL PEMERIKSAAN EKG'], McuCategory::whereNull('legacy_group')->orderBy('sort_order')->pluck('name')->all());
        $this->assertDatabaseCount('mcu_subcategories', 22);
        $this->assertDatabaseCount('mcu_items', 141);
        $this->assertDatabaseCount('karyawans', 0);
        $this->assertDatabaseCount('anamnesas', 0);
        $this->assertDatabaseCount('dokters', 0);
        $this->assertDatabaseCount('pakets', 0);
        $this->assertSame(2, McuCategory::where('has_responsible_doctor', false)->count());
        $this->assertSame(0, McuItem::whereNotIn('input_type', ['text', 'number', 'radio'])->count());
        $this->assertSame(0, McuItem::whereHas('category', fn ($query) => $query->whereNull('legacy_group'))->where('label', 'like', 'Visus Mata%')->count());
        $this->assertSame(0, McuItem::whereHas('category', fn ($query) => $query->whereNull('legacy_group'))->whereIn('label', ['Tinggi Badan (cm)', 'Hipertensi', 'Keluhan Sekarang', 'Kulit'])->count());
        foreach (McuItem::whereNotNull('lab_config')->get() as $item) {
            $this->assertArrayNotHasKey('default_result', $item->lab_config);
            $this->assertSame('laboratory', $item->definition()['type']);
        }
        $this->assertSame(['Kesan', 'Kesimpulan'], McuItem::where('allow_attachment', true)->orderBy('label')->pluck('label')->all());
        $this->assertSame(1, McuSubcategory::where('name', 'FAAL HATI')->count());
        $this->assertSame(0, McuSubcategory::where('name', 'like', '%LANJUTAN%')->count());
    }

    public function test_lab_mappings_use_template_units_and_ranges_not_sample_results(): void
    {
        $this->seed(McuTemplateMasterSeeder::class);
        $hemoglobin = McuItem::where('label', 'Hemoglobin')->firstOrFail();
        $this->assertSame('g/dL', $hemoglobin->lab_config['unit']);
        $this->assertSame('13.2 - 17.3', $hemoglobin->lab_config['reference']);
        $this->assertSame('Normal', LaboratoryResult::evaluate($hemoglobin->lab_config, 14)['status']);
        $this->assertSame('Tidak normal', LaboratoryResult::evaluate($hemoglobin->lab_config, 18)['status']);
        $glucose = McuItem::where('label', 'Glukosa 2 Jam PP')->firstOrFail();
        $this->assertSame('lt', $glucose->lab_config['rule']);
        $this->assertSame('Tidak normal', LaboratoryResult::evaluate($glucose->lab_config, 140)['status']);
        $kidney = McuItem::where('label', 'eLFG (CKD-EPI)')->firstOrFail();
        $this->assertSame('gte', $kidney->lab_config['rule']);
        $this->assertSame('Normal', LaboratoryResult::evaluate($kidney->lab_config, 90)['status']);
        $serology = McuItem::where('label', 'HBsAg')->firstOrFail();
        $this->assertSame(['Non Reaktif'], $serology->lab_config['normal_values']);
        $this->assertSame('Normal', LaboratoryResult::evaluate($serology->lab_config, 'Non Reaktif')['status']);
        $this->assertSame('Belum diperiksa', LaboratoryResult::evaluate($serology->lab_config, null)['status']);
    }

    public function test_reseeding_removes_old_masters_and_restores_template_without_changing_patient_results_or_packages(): void
    {
        $participant = Karyawan::factory()->create();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'dynamic_answers' => ['custom' => 'Hasil tersimpan']]);
        $custom = McuItem::factory()->create(['label' => 'Item buatan operator']);
        $schema = [['id' => $custom->mcu_category_id, 'name' => $custom->category->name, 'order' => 1, 'items' => [$custom->definition()]]];
        $participant->paket->update(['dynamic_schema' => $schema]);
        $exam->update(['dynamic_schema' => $schema, 'dynamic_answers' => [$custom->id => 'Hasil lama tetap tersimpan']]);
        $packageBefore = $participant->paket->toArray();
        $examBefore = $exam->fresh()->toArray();
        $this->seed(McuTemplateMasterSeeder::class);
        $item = McuItem::where('label', 'Irama')->firstOrFail();
        $item->update(['label' => 'Irama disesuaikan operator', 'is_active' => false]);
        $counts = [McuCategory::count(), McuSubcategory::count(), McuItem::count()];

        $this->seed(McuTemplateMasterSeeder::class);

        $this->assertSame($counts, [McuCategory::count(), McuSubcategory::count(), McuItem::count()]);
        $this->assertSame('Irama', $item->fresh()->label);
        $this->assertTrue($item->fresh()->is_active);
        $this->assertModelMissing($custom);
        $this->assertDatabaseCount('mcu_categories', 5);
        $this->assertDatabaseCount('mcu_subcategories', 22);
        $this->assertDatabaseCount('mcu_items', 141);
        $this->assertSame($packageBefore, $participant->paket->fresh()->toArray());
        $this->assertSame($examBefore, $exam->fresh()->toArray());
    }

    public function test_old_laboratory_categories_are_replaced_by_one_lab_category_and_template_subcategories_only(): void
    {
        foreach (['Drug Test', 'Elektrolit', 'Faal Ginjal', 'Hematologi'] as $name) {
            $category = McuCategory::factory()->create(['name' => 'Laboratorium — '.$name]);
            McuItem::factory()->create(['mcu_category_id' => $category->id, 'label' => 'Item lama '.$name]);
        }

        $this->seed(McuTemplateMasterSeeder::class);
        $this->seed(McuTemplateMasterSeeder::class);

        $this->assertSame(0, McuCategory::where('name', 'like', 'Laboratorium%')->count());
        $this->assertSame(0, McuItem::where('label', 'like', 'Item lama%')->count());
        $lab = McuCategory::where('name', 'LAB')->sole();
        $this->assertSame(['HEMATOLOGI', 'HITUNG JENIS LEUKOSIT', 'GLUKOSA', 'FAAL HATI', 'FAAL GINJAL', 'PROFIL LIPID', 'ELEKTROLIT', 'SEROLOGI', 'URINALISA', 'DRUG TEST', 'OTHERS'], $lab->subcategories->pluck('name')->all());
        $this->assertSame(53, $lab->items()->count());
        $this->assertSame(0, $lab->items()->whereNull('mcu_subcategory_id')->count());
        $this->assertDatabaseCount('mcu_categories', 5);
        $this->assertDatabaseCount('mcu_subcategories', 22);
        $this->assertDatabaseCount('mcu_items', 141);
    }

    public function test_database_seed_updates_admin_password_without_duplicate_users_and_login_works(): void
    {
        config(['mcu.seed_password' => 'password']);
        $admin = User::factory()->create(['username' => 'admin', 'role' => 'admin', 'password' => Hash::make('old-password')]);
        $other = User::factory()->create();
        $otherPassword = $other->password;

        $this->seed(DatabaseSeeder::class);
        $password = $admin->fresh()->password;
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
        $this->assertSame($password, $admin->fresh()->password);
        $this->assertSame($otherPassword, $other->fresh()->password);
        $this->post(route('login'), ['login' => 'admin', 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_new_template_package_renders_actual_results_attachments_and_category_doctor(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->post(route('paket.store'), ['nama_paket' => 'Paket sesuai template', 'items' => ['tinggi_badan', 'keluhan_saat_ini'], 'master_items' => McuItem::whereHas('category', fn ($query) => $query->whereNull('legacy_group'))->orderBy('sort_order')->pluck('id')->all()])->assertSessionHasNoErrors();
        $package = Paket::where('nama_paket', 'Paket sesuai template')->firstOrFail();
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);
        $doctor = Dokter::factory()->create(['is_active' => true]);
        $hemoglobin = McuItem::where('label', 'Hemoglobin')->firstOrFail();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => $package->active_items, 'tinggi_badan' => 170, 'anamnesa_data' => ['keluhan_saat_ini' => ['answer' => 'Keluhan dari form hardcode', 'note' => null]], 'dynamic_schema' => $package->dynamic_schema, 'dynamic_answers' => [$hemoglobin->id => 14.5], 'category_doctors' => [$hemoglobin->mcu_category_id => $doctor->id]]);

        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()
            ->assertSee('HASIL PEMERIKSAAN FISIK')->assertSee('Anamnesa pasien')->assertSee('Keluhan dari form hardcode')->assertSee('HASIL PEMERIKSAAN LABORATORIUM')
            ->assertSee('HASIL PEMERIKSAAN RONTGEN THORAKS')->assertSee('HASIL PEMERIKSAAN EKG')
            ->assertSee('14.5')->assertSee('13.2 - 17.3')->assertSee('Dokter Pemeriksa: '.$doctor->nama_dokter)
            ->assertDontSee('dr. Radiolog Onelab')->assertDontSee('dr. Kardiolog Onelab')->assertDontSee('mcu-contoh-rontgen.jpg')->assertDontSee('mcu-contoh-ekg.jpg');
        $rontgen = McuItem::where('label', 'Kesan')->firstOrFail();
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$rontgen->mcu_category_id]))->assertOk()->assertSee('name="dynamic_files['.$rontgen->id.']"', false);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'anamnesa']))->assertOk()->assertSee('name="anamnesa[keluhan_saat_ini][answer]"', false)->assertSee('Keluhan dari form hardcode');
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'fisik']))->assertOk()->assertSee('name="tinggi_badan"', false);
    }
}
