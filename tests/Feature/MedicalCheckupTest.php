<?php

namespace Tests\Feature;

use App\Models\Anamnesa;
use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MedicalCheckupTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_use_valid_uuids(): void
    {
        $perusahaan = Perusahaan::create([
            'nama_perusahaan' => 'PT Test Corp',
        ]);

        $this->assertTrue(Str::isUuid($perusahaan->id));

        $kunjungan = RencanaKunjungan::create([
            'perusahaan_id' => $perusahaan->id,
            'nomor_kunjungan' => 'MCU-TEST-001',
            'periode_kunjungan' => 'Oktober 2026',
        ]);

        $this->assertTrue(Str::isUuid($kunjungan->id));

        $paket = Paket::create([
            'perusahaan_id' => $perusahaan->id,
            'rencana_kunjungan_id' => $kunjungan->id,
            'nama_paket' => 'Paket Test',
            'active_items' => ['tbc' => false, 'hipertensi' => true],
        ]);

        $this->assertTrue(Str::isUuid($paket->id));

        $karyawan = Karyawan::create([
            'perusahaan_id' => $perusahaan->id,
            'rencana_kunjungan_id' => $kunjungan->id,
            'paket_id' => $paket->id,
            'nama_karyawan' => 'Budi Sudarsono',
        ]);

        $this->assertTrue(Str::isUuid($karyawan->id));
    }

    public function test_soft_deletes_work_properly(): void
    {
        $perusahaan = Perusahaan::create([
            'nama_perusahaan' => 'PT Soft Delete Test',
        ]);

        $id = $perusahaan->id;
        $perusahaan->delete();

        $this->assertSoftDeleted('perusahaans', ['id' => $id]);
        $this->assertNull(Perusahaan::find($id));
        $this->assertNotNull(Perusahaan::withTrashed()->find($id));
    }

    public function test_laravel_auditing_records_events(): void
    {
        $user = User::create([
            'name' => 'Audit Tester',
            'email' => 'auditor@example.com',
            'password' => 'secret123',
        ]);

        $this->assertDatabaseHas('audits', [
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'event' => 'created',
        ]);
    }

    public function test_dynamic_package_item_checking(): void
    {
        $perusahaan = Perusahaan::create(['nama_perusahaan' => 'PT XYZ']);
        $kunjungan = RencanaKunjungan::create([
            'perusahaan_id' => $perusahaan->id,
            'nomor_kunjungan' => 'MCU-XYZ-01',
            'periode_kunjungan' => 'Oktober 2026',
        ]);

        // Paket 1: Custom without TBC
        $paketCustom = Paket::create([
            'perusahaan_id' => $perusahaan->id,
            'rencana_kunjungan_id' => $kunjungan->id,
            'nama_paket' => 'Paket 1 XYZ Custom',
            'active_items' => [
                'tbc' => false,
                'hipertensi' => true,
                'tinggi_badan' => true,
            ],
        ]);

        // Paket 2: Full
        $paketFull = Paket::create([
            'perusahaan_id' => $perusahaan->id,
            'rencana_kunjungan_id' => $kunjungan->id,
            'nama_paket' => 'Paket 2 XYZ Full',
            'active_items' => [
                'tbc' => true,
                'hipertensi' => true,
                'tinggi_badan' => true,
            ],
        ]);

        $this->assertFalse($paketCustom->isItemActive('tbc'));
        $this->assertTrue($paketCustom->isItemActive('hipertensi'));

        $this->assertTrue($paketFull->isItemActive('tbc'));
        $this->assertTrue($paketFull->isItemActive('hipertensi'));
    }

    public function test_bmi_and_category_calculation(): void
    {
        // Case: TB = 172 cm, BB = 84 kg -> BMI = 28.39 (28.4), Obesitas I
        $calc = Anamnesa::calculateBmi(172, 84);
        $this->assertEquals(28.39, $calc['bmi']);
        $this->assertEquals('Obesitas I', $calc['kategori']);

        // Case: TB = 170 cm, BB = 60 kg -> BMI = 20.76, Normal
        $calcNormal = Anamnesa::calculateBmi(170, 60);
        $this->assertEquals(20.76, $calcNormal['bmi']);
        $this->assertEquals('Normal', $calcNormal['kategori']);

        // Case: Underweight
        $calcUnder = Anamnesa::calculateBmi(170, 45);
        $this->assertEquals('Berat Badan Kurang (Underweight)', $calcUnder['kategori']);
    }

    public function test_anamnesa_44_parameters_storage(): void
    {
        $perusahaan = Perusahaan::create(['nama_perusahaan' => 'PT XYZ']);
        $kunjungan = RencanaKunjungan::create([
            'perusahaan_id' => $perusahaan->id,
            'nomor_kunjungan' => 'MCU-XYZ-01',
            'periode_kunjungan' => 'Oktober 2026',
        ]);
        $dokter = Dokter::create([
            'nama_dokter' => 'dr. Sarah Wijaya',
            'no_str' => 'STR-12345',
        ]);
        $paket = Paket::create([
            'perusahaan_id' => $perusahaan->id,
            'rencana_kunjungan_id' => $kunjungan->id,
            'nama_paket' => 'Paket 1',
        ]);
        $karyawan = Karyawan::create([
            'perusahaan_id' => $perusahaan->id,
            'rencana_kunjungan_id' => $kunjungan->id,
            'paket_id' => $paket->id,
            'nama_karyawan' => 'Ahmad Fauzi',
        ]);

        $anamnesa = Anamnesa::create([
            'karyawan_id' => $karyawan->id,
            'rencana_kunjungan_id' => $kunjungan->id,
            'dokter_id' => $dokter->id,
            'tinggi_badan' => 172,
            'berat_badan' => 84,
            'bmi' => 28.4,
            'kategori_bmi' => 'Obesitas',
            'tekanan_darah' => '138/88',
            'nadi' => 82,
            'lingkar_pinggang' => 96,
            'pernapasan' => 18,
            'suhu_badan' => 36.6,
            'visus_od_tanpa_kacamata' => '6/12',
            'visus_os_tanpa_kacamata' => '6/9',
            'visus_od_dengan_kacamata' => '6/6',
            'visus_os_dengan_kacamata' => '6/6',
            'ishihara_test' => 'Normal',
            'kesan_umum' => 'Baik, compos mentis',
            'kulit' => 'Normal',
            'mata' => 'Konjungtiva tidak anemis, sklera tidak ikterik',
            'kesan_telinga' => 'Normal',
            'kesan_hidung' => 'Septum deviasi ringan',
            'kesan_tenggorokan' => 'Normal',
            'kesan_mulut' => 'Normal',
            'gigi' => 'Caries pada gigi geraham kanan bawah',
            'kesan_leher' => 'Normal, tidak ada pembesaran KGB',
            'kesan_thorax' => 'Simetris, tidak ada retraksi',
            'ronchi' => 'Negatif',
            'wheezing' => 'Negatif',
            'gallop' => 'Negatif',
            'murmur' => 'Negatif',
            'kesan_abdomen' => 'Supel, bising usus normal',
            'hati_lien' => 'Tidak teraba membesar',
            'benjolan_massa' => 'Tidak ada',
            'nyeri_tekan' => 'Tidak ada',
            'hernia' => 'Tidak ada',
            'test_cva' => 'Negatif',
            'rectal' => 'Tidak diperiksa',
            'haemorrhoid' => 'Tidak ada',
            'ekstremitas_atas' => 'Normal',
            'ekstremitas_bawah' => 'Normal',
            'range_of_movement' => 'Full ROM',
            'refleks_fisiologis' => 'Normal',
            'refleks_patologis' => 'Negatif',
            'kekuatan_motorik' => '5/5/5/5',
            'kelainan_saraf_pusat' => 'Tidak ada',
            'kelainan_saraf_tepi' => 'Tidak ada',
            'status_kelaikan' => 'Fit with Note',
            'status' => 'final',
        ]);

        $this->assertTrue(Str::isUuid($anamnesa->id));
        $this->assertEquals('138/88', $anamnesa->tekanan_darah);
        $this->assertEquals('Fit with Note', $anamnesa->status_kelaikan);
    }
}
