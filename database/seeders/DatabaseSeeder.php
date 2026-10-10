<?php

namespace Database\Seeders;

use App\Models\Anamnesa;
use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(McuFormMasterSeeder::class);
        // 1. Akun Admin & Dokter
        $admin = User::create([
            'name' => 'Administrator Nayaka MCU',
            'username' => 'admin',
            'email' => 'admin@nayaka.co.id',
            'password' => Hash::make(config('mcu.seed_password')),
            'role' => 'admin',
        ]);

        //     $dokterUser = User::create([
        //         'name' => 'dr. Sarah Wijaya, Sp.Ok',
        //         'username' => 'drsarah',
        //         'email' => 'sarah.wijaya@nayaka.co.id',
        //         'password' => Hash::make(config('mcu.seed_password')),
        //         'role' => 'dokter',
        //     ]);

        //     // 2. Data Dokter
        //     $dokter1 = Dokter::create([
        //         'user_id' => $dokterUser->id,
        //         'nama_dokter' => 'dr. Sarah Wijaya, Sp.Ok',
        //         'no_str' => 'STR-3171-88921-2024',
        //         'spesialisasi' => 'Spesialis Kedokteran Okupasi (MCU)',
        //         'is_active' => true,
        //     ]);

        //     $dokter2 = Dokter::create([
        //         'nama_dokter' => 'dr. Budi Santoso',
        //         'no_str' => 'STR-3172-10293-2023',
        //         'spesialisasi' => 'Dokter Umum MCU',
        //         'is_active' => true,
        //     ]);

        //     // 3. Perusahaan
        //     $perusahaan = Perusahaan::create([
        //         'nama_perusahaan' => 'PT XYZ Solusi Teknologi',
        //         'alamat' => 'Jl. Jenderal Sudirman Kav. 45, Jakarta Selatan',
        //         'telp' => '021-5558899',
        //         'email' => 'hrd@xyzsolusi.com',
        //         'pic_nama' => 'Bambang Hartono',
        //         'pic_kontak' => '081298765432',
        //     ]);

        //     $perusahaan2 = Perusahaan::create([
        //         'nama_perusahaan' => 'PT Mitra Sejahtera Abadi',
        //         'alamat' => 'Kawasan Industri MM2100, Cikarang Barat, Bekasi',
        //         'telp' => '021-8980123',
        //         'email' => 'hc@mitrasejahtera.co.id',
        //         'pic_nama' => 'Dewi Anggraini',
        //         'pic_kontak' => '081122334455',
        //     ]);

        //     // 4. Rencana Kunjungan
        //     $kunjungan = RencanaKunjungan::create([
        //         'perusahaan_id' => $perusahaan->id,
        //         'nomor_kunjungan' => 'MCU-202610-XYZ',
        //         'periode_kunjungan' => 'Oktober 2026',
        //         'tgl_mulai' => now()->toDateString(),
        //         'tgl_selesai' => now()->addDays(7)->toDateString(),
        //         'kuota_peserta' => 1000,
        //         'is_closing' => false,
        //         'status' => 'ongoing',
        //         'catatan' => 'Pelaksanaan On-Site MCU Tahunan PT XYZ Solusi Teknologi (1.000 Peserta)',
        //     ]);

        //     // 5. Paket-Paket Dinamis Sesuai Kebutuhan Case User
        //     // Katalog item default
        //     $catalog = Paket::getDefaultItemCatalog();
        //     $allItemKeys = [];
        //     foreach ($catalog as $group => $items) {
        //         foreach ($items as $k => $v) {
        //             $allItemKeys[$k] = true;
        //         }
        //     }

        //     // Paket 1: Paket 1 XYZ Custom (TBC dinonaktifkan: false)
        //     $itemsPaket1 = $allItemKeys;
        //     $itemsPaket1['tbc'] = false; // TBC tidak termasuk sesuai permintaan user

        //     $paket1 = Paket::create([
        //         'perusahaan_id' => $perusahaan->id,
        //         'rencana_kunjungan_id' => $kunjungan->id,
        //         'nama_paket' => 'Paket 1 XYZ Custom',
        //         'deskripsi' => 'Paket MCU Standar PT XYZ (Anamnesa & Pemeriksaan Fisik Lengkap tanpa Riwayat TBC)',
        //         'active_items' => $itemsPaket1,
        //     ]);

        //     // Paket 2: Paket 2 XYZ Full (Semua item aktif termasuk TBC)
        //     $itemsPaket2 = $allItemKeys;

        //     $paket2 = Paket::create([
        //         'perusahaan_id' => $perusahaan->id,
        //         'rencana_kunjungan_id' => $kunjungan->id,
        //         'nama_paket' => 'Paket 2 XYZ Full',
        //         'deskripsi' => 'Paket MCU Eksekutif PT XYZ (Seluruh item anamnesa, riwayat TBC & 44 parameter fisik)',
        //         'active_items' => $itemsPaket2,
        //     ]);

        //     // 6. Contoh Karyawan Peserta
        //     // Peserta di Paket 1 Custom
        //     $karyawan1 = Karyawan::create([
        //         'perusahaan_id' => $perusahaan->id,
        //         'rencana_kunjungan_id' => $kunjungan->id,
        //         'paket_id' => $paket1->id,
        //         'nik' => '3171012304900001',
        //         'nip' => 'XYZ-1001',
        //         'nama_karyawan' => 'Ahmad Fauzi',
        //         'jenis_kelamin' => 'L',
        //         'tanggal_lahir' => '1990-04-23',
        //         'bagian' => 'IT Development',
        //         'jabatan' => 'Senior Backend Engineer',
        //         'telepon' => '081312345678',
        //         'status_pemeriksaan' => 'selesai',
        //     ]);

        //     $karyawan2 = Karyawan::create([
        //         'perusahaan_id' => $perusahaan->id,
        //         'rencana_kunjungan_id' => $kunjungan->id,
        //         'paket_id' => $paket1->id,
        //         'nik' => '3171012304920002',
        //         'nip' => 'XYZ-1002',
        //         'nama_karyawan' => 'Rina Nuraini',
        //         'jenis_kelamin' => 'P',
        //         'tanggal_lahir' => '1992-08-15',
        //         'bagian' => 'Finance & Accounting',
        //         'jabatan' => 'Finance Supervisor',
        //         'telepon' => '081398765432',
        //         'status_pemeriksaan' => 'belum',
        //     ]);

        //     // Peserta di Paket 2 Full
        //     $karyawan3 = Karyawan::create([
        //         'perusahaan_id' => $perusahaan->id,
        //         'rencana_kunjungan_id' => $kunjungan->id,
        //         'paket_id' => $paket2->id,
        //         'nik' => '3171012304850003',
        //         'nip' => 'XYZ-0005',
        //         'nama_karyawan' => 'Hendra Gunawan',
        //         'jenis_kelamin' => 'L',
        //         'tanggal_lahir' => '1985-11-10',
        //         'bagian' => 'Direksi',
        //         'jabatan' => 'VP of Engineering',
        //         'telepon' => '081233445566',
        //         'status_pemeriksaan' => 'belum',
        //     ]);

        //     // 7. Contoh Anamnesa & Pemeriksaan Fisik Sesuai Data Contoh User
        //     $bmiCalc = Anamnesa::calculateBmi(172, 84); // 28.4, Obesitas I

        //     Anamnesa::create([
        //         'karyawan_id' => $karyawan1->id,
        //         'rencana_kunjungan_id' => $kunjungan->id,
        //         'dokter_id' => $dokter1->id,
        //         'header_form' => 'PEMERIKSAAN FISIK & ANAMNESA',
        //         'sub_header' => 'FORMULIR MCU NAYAKA',
        //         'keluhan_saat_ini' => 'Tidak ada keluhan berarti, kadang pegal pada leher saat lembur.',
        //         'riwayat_penyakit_dahulu' => [
        //             'hipertensi' => false,
        //             'diabetes_melitus' => false,
        //             'jantung' => false,
        //             'asma' => false,
        //         ],
        //         'riwayat_penyakit_keluarga' => [
        //             'hipertensi' => true,
        //             'diabetes_melitus' => false,
        //         ],
        //         'riwayat_kebiasaan' => [
        //             'merokok' => false,
        //             'alkohol' => false,
        //             'olahraga' => 'Jarang (1x seminggu)',
        //         ],
        //         // 44 parameter fisik
        //         'tinggi_badan' => 172.00,
        //         'berat_badan' => 84.00,
        //         'bmi' => $bmiCalc['bmi'],
        //         'kategori_bmi' => 'Obesitas',
        //         'tekanan_darah' => '138/88',
        //         'nadi' => 82,
        //         'lingkar_pinggang' => 96.00,
        //         'pernapasan' => 18,
        //         'suhu_badan' => 36.60,
        //         'visus_od_tanpa_kacamata' => '6/12',
        //         'visus_os_tanpa_kacamata' => '6/9',
        //         'visus_od_dengan_kacamata' => '6/6',
        //         'visus_os_dengan_kacamata' => '6/6',
        //         'ishihara_test' => 'Normal',
        //         'kesan_umum' => 'Baik, compos mentis',
        //         'kulit' => 'Normal',
        //         'mata' => 'Konjungtiva tidak anemis, sklera tidak ikterik',
        //         'kesan_telinga' => 'Normal',
        //         'kesan_hidung' => 'Septum deviasi ringan',
        //         'kesan_tenggorokan' => 'Normal',
        //         'kesan_mulut' => 'Normal',
        //         'gigi' => 'Caries pada gigi geraham kanan bawah',
        //         'kesan_leher' => 'Normal, tidak ada pembesaran KGB',
        //         'kesan_thorax' => 'Simetris, tidak ada retraksi',
        //         'ronchi' => 'Negatif',
        //         'wheezing' => 'Negatif',
        //         'gallop' => 'Negatif',
        //         'murmur' => 'Negatif',
        //         'kesan_abdomen' => 'Supel, bising usus normal',
        //         'hati_lien' => 'Tidak teraba membesar',
        //         'benjolan_massa' => 'Tidak ada',
        //         'nyeri_tekan' => 'Tidak ada',
        //         'hernia' => 'Tidak ada',
        //         'test_cva' => 'Negatif',
        //         'rectal' => 'Tidak diperiksa',
        //         'haemorrhoid' => 'Tidak ada',
        //         'ekstremitas_atas' => 'Normal',
        //         'ekstremitas_bawah' => 'Normal',
        //         'range_of_movement' => 'Full ROM',
        //         'refleks_fisiologis' => 'Normal',
        //         'refleks_patologis' => 'Negatif',
        //         'kekuatan_motorik' => '5/5/5/5',
        //         'kelainan_saraf_pusat' => 'Tidak ada',
        //         'kelainan_saraf_tepi' => 'Tidak ada',
        //         'catatan_dokter' => 'Ditemukan pre-hipertensi (138/88 mmHg), status gizi obesitas tingkat 1, visus mata terkoreksi kacamata baik, dan karies gigi.',
        //         'anjuran_saran' => '1. Diet rendah kalori dan lemak jenuh.\n2. Tingkatkan aktivitas fisik aerobik 30 menit 3-5x/minggu.\n3. Konsultasi ke dokter gigi untuk penambalan karies gigi.\n4. Evaluasi ulang tensi darah berkala.',
        //         'status_kelaikan' => 'Fit with Note',
        //         'status' => 'final',
        //     ]);
    }
}
