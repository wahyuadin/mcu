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
            $category = DB::table('mcu_categories')->where('legacy_group', 'anamnesa_riwayat')->first();
            $categoryId = $category?->id ?? (string) Str::uuid();
            if (! $category) {
                DB::table('mcu_categories')->insert(['id' => $categoryId, 'name' => 'Anamnesis', 'legacy_group' => 'anamnesa_riwayat', 'sort_order' => 0, 'is_active' => true, 'has_responsible_doctor' => false, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('mcu_categories')->where('id', $categoryId)->update(['is_active' => true, 'has_responsible_doctor' => false]);
            }
            $groups = [
                'Keluhan' => ['keluhan_saat_ini' => 'Keluhan sekarang'],
                'Riwayat penyakit dahulu' => ['hepatitis' => 'Hepatitis', 'hipertensi' => 'Hipertensi', 'diabetes_melitus' => 'Diabetes melitus', 'jantung' => 'Penyakit jantung', 'riwayat_haemorrhoid' => 'Haemorrhoid', 'kejang' => 'Kejang', 'riwayat_hernia' => 'Hernia', 'alergi' => 'Alergi', 'tbc' => 'TBC', 'rawat_inap' => 'Rawat inap', 'operasi' => 'Operasi', 'hiv_aids' => 'HIV/AIDS', 'riwayat_obat' => 'Riwayat obat-obatan'],
                'Hazard lingkungan kerja' => ['hazard_bising' => 'Bising', 'hazard_getaran' => 'Getaran', 'hazard_debu' => 'Debu/asap', 'hazard_kimia' => 'Zat kimia', 'hazard_makanan' => 'Mengelola produksi makanan', 'hazard_pc' => 'Penggunaan komputer (jam per hari)', 'hazard_dorong' => 'Mendorong/menarik', 'hazard_beban' => 'Angkat beban tanpa alat >25 kg', 'hazard_kecelakaan' => 'Riwayat kecelakaan kerja'],
                'Riwayat penyakit keluarga' => ['keluarga_hipertensi' => 'Hipertensi', 'keluarga_diabetes' => 'Diabetes melitus', 'keluarga_hepatitis' => 'Hepatitis B', 'keluarga_kanker' => 'Kanker', 'keluarga_jantung' => 'Penyakit jantung', 'keluarga_asma' => 'Asthma bronkhial', 'keluarga_tbc' => 'Tuberculosis'],
                'Riwayat kebiasaan' => ['olahraga' => 'Olahraga (kali per minggu)', 'kebiasaan_merokok' => 'Merokok/rokok elektrik (batang per hari atau rincian penggunaan)', 'kebiasaan_alkohol' => 'Minuman beralkohol', 'kopi' => 'Minum kopi (gelas per hari)', 'obat_rutin' => 'Obat yang rutin diminum', 'kacamata' => 'Menggunakan kacamata'],
            ];
            $text = ['keluhan_saat_ini', 'riwayat_obat', 'olahraga', 'kebiasaan_merokok', 'kopi', 'obat_rutin'];
            $order = 0;
            foreach ($groups as $name => $items) {
                $subcategory = DB::table('mcu_subcategories')->where('mcu_category_id', $categoryId)->where('name', $name)->first();
                $subcategoryId = $subcategory?->id ?? (string) Str::uuid();
                if (! $subcategory) {
                    DB::table('mcu_subcategories')->insert(['id' => $subcategoryId, 'mcu_category_id' => $categoryId, 'name' => $name, 'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ($items as $key => $label) {
                    DB::table('mcu_items')->insertOrIgnore(['id' => (string) Str::uuid(), 'mcu_category_id' => $categoryId, 'mcu_subcategory_id' => $subcategoryId, 'legacy_key' => $key, 'label' => $label, 'input_type' => in_array($key, $text, true) ? 'text' : 'radio', 'yes_report' => 'Ya', 'no_report' => 'Tidak', 'is_active' => true, 'is_required' => true, 'sort_order' => $order++, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    /** Preserve user-managed master data and references when rolling back this data-only migration. */
    public function down(): void {}
};
