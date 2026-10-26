<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $category = DB::table('mcu_categories')->where('legacy_group', 'pemeriksaan_fisik')->first();
            $categoryId = $category?->id ?? (string) Str::uuid();
            if (! $category) {
                DB::table('mcu_categories')->insert(['id' => $categoryId, 'name' => 'Pemeriksaan Fisik', 'legacy_group' => 'pemeriksaan_fisik', 'is_active' => true, 'has_responsible_doctor' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            $groups = [
                'Antropometri & tanda vital' => ['tinggi_badan' => 'Tinggi Badan (cm)', 'berat_badan' => 'Berat Badan (kg)', 'tekanan_darah' => 'Tekanan Darah (mmHg)', 'nadi' => 'Nadi (bpm)', 'lingkar_pinggang' => 'Lingkar Pinggang (cm)', 'pernapasan' => 'Pernapasan (x/menit)', 'suhu_badan' => 'Suhu Badan (°C)'],
                'Visus & buta warna' => ['visus_od_tanpa_kacamata' => 'Visus tanpa kacamata — OD', 'visus_os_tanpa_kacamata' => 'Visus tanpa kacamata — OS', 'visus_od_dengan_kacamata' => 'Visus dengan kacamata — OD', 'visus_os_dengan_kacamata' => 'Visus dengan kacamata — OS', 'ishihara_test' => 'Tes Buta Warna (Ishihara)'],
                'Keadaan umum & kepala' => ['kesan_umum' => 'Kesan Umum', 'kulit' => 'Kulit', 'mata' => 'Mata (Konjungtiva / Sklera)', 'kesan_telinga' => 'Kesan Telinga', 'kesan_hidung' => 'Kesan Hidung', 'kesan_tenggorokan' => 'Kesan Tenggorokan', 'kesan_mulut' => 'Kesan Mulut', 'gigi' => 'Pemeriksaan Gigi', 'kesan_leher' => 'Kesan Leher & Kelenjar Getah Bening'],
                'Thorax & auskultasi' => ['kesan_thorax' => 'Kesan Thorax', 'ronchi' => 'Ronchi', 'wheezing' => 'Wheezing', 'gallop' => 'Gallop', 'murmur' => 'Murmur'],
                'Abdomen & urogenitalia' => ['kesan_abdomen' => 'Kesan Abdomen', 'hati_lien' => 'Hati / Lien', 'benjolan_massa' => 'Benjolan Massa', 'nyeri_tekan' => 'Nyeri Tekan', 'hernia' => 'Hernia', 'test_cva' => 'Test CVA', 'rectal' => 'Rectal Toucher', 'haemorrhoid' => 'Haemorrhoid'],
                'Ekstremitas & neurologis' => ['ekstremitas_atas' => 'Ekstremitas Atas', 'ekstremitas_bawah' => 'Ekstremitas Bawah', 'range_of_movement' => 'Range of Movement (ROM)', 'refleks_fisiologis' => 'Refleks Fisiologis', 'refleks_patologis' => 'Refleks Patologis', 'kekuatan_motorik' => 'Kekuatan Motorik', 'kelainan_saraf_pusat' => 'Kelainan Saraf Pusat', 'kelainan_saraf_tepi' => 'Kelainan Saraf Tepi'],
            ];
            $numeric = ['tinggi_badan', 'berat_badan', 'nadi', 'lingkar_pinggang', 'pernapasan', 'suhu_badan'];
            $order = 0;
            foreach ($groups as $name => $items) {
                $subcategoryId = DB::table('mcu_subcategories')->where('mcu_category_id', $categoryId)->where('name', $name)->value('id');
                if (! $subcategoryId) {
                    $subcategoryId = (string) Str::uuid();
                    DB::table('mcu_subcategories')->insert(['id' => $subcategoryId, 'mcu_category_id' => $categoryId, 'name' => $name, 'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ($items as $key => $label) {
                    DB::table('mcu_items')->insertOrIgnore(['id' => (string) Str::uuid(), 'mcu_category_id' => $categoryId, 'mcu_subcategory_id' => $subcategoryId, 'legacy_key' => $key, 'label' => $label, 'input_type' => in_array($key, $numeric, true) ? 'number' : 'text', 'is_required' => true, 'is_active' => true, 'sort_order' => $order++, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    /** Preserve user-managed physical masters and snapshot references. */
    public function down(): void {}
};
