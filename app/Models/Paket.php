<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Paket extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'pakets';

    protected $fillable = [
        'perusahaan_id',
        'rencana_kunjungan_id',
        'nama_paket',
        'deskripsi',
        'active_items',
    ];

    protected function casts(): array
    {
        return [
            'active_items' => 'array',
        ];
    }

    /**
     * Relasi ke Perusahaan
     */
    public function perusahaan(): BelongsTo
    {
        return $this->belongsTo(Perusahaan::class, 'perusahaan_id');
    }

    /**
     * Relasi ke Rencana Kunjungan
     */
    public function rencanaKunjungan(): BelongsTo
    {
        return $this->belongsTo(RencanaKunjungan::class, 'rencana_kunjungan_id');
    }

    /**
     * Relasi ke Peserta Karyawan dengan Paket ini
     */
    public function karyawans(): HasMany
    {
        return $this->hasMany(Karyawan::class, 'paket_id');
    }

    /**
     * Default list of all 44 clinical + anamnesa items available
     */
    public static function getDefaultItemCatalog(): array
    {
        return [
            'anamnesa_riwayat' => [
                'keluhan_saat_ini' => 'Keluhan Saat Ini',
                'hipertensi' => 'Riwayat Hipertensi',
                'diabetes_melitus' => 'Riwayat Diabetes Melitus',
                'jantung' => 'Riwayat Penyakit Jantung',
                'asma' => 'Riwayat Asma / Alergi',
                'tbc' => 'Riwayat TBC / Paru',
                'ginjal' => 'Riwayat Penyakit Ginjal',
                'operasi' => 'Riwayat Operasi / Rawat Inap',
                'kebiasaan_merokok' => 'Riwayat Merokok',
                'kebiasaan_alkohol' => 'Riwayat Konsumsi Alkohol',
            ],
            'antropometri_vital' => [
                'tinggi_badan' => 'Tinggi Badan (cm)',
                'berat_badan' => 'Berat Badan (kg)',
                'bmi' => 'BMI & Kategori BMI',
                'tekanan_darah' => 'Tekanan Darah (mmHg)',
                'nadi' => 'Nadi (bpm)',
                'lingkar_pinggang' => 'Lingkar Pinggang (cm)',
                'pernapasan' => 'Pernapasan (x/menit)',
                'suhu_badan' => 'Suhu Badan (°C)',
            ],
            'visus_buta_warna' => [
                'visus_tanpa_kacamata' => 'Visus Tanpa Kacamata (OD/OS)',
                'visus_dengan_kacamata' => 'Visus Dengan Kacamata (OD/OS)',
                'ishihara_test' => 'Tes Buta Warna (Ishihara)',
            ],
            'keadaan_umum_kepala' => [
                'kesan_umum' => 'Kesan Umum',
                'kulit' => 'Kulit',
                'mata' => 'Mata (Konjungtiva / Sklera)',
                'kesan_telinga' => 'Kesan Telinga',
                'kesan_hidung' => 'Kesan Hidung',
                'kesan_tenggorokan' => 'Kesan Tenggorokan',
                'kesan_mulut' => 'Kesan Mulut',
                'gigi' => 'Pemeriksaan Gigi',
                'kesan_leher' => 'Kesan Leher & Kelenjar Getah Bening',
            ],
            'thorax_auskultasi' => [
                'kesan_thorax' => 'Kesan Thorax',
                'ronchi' => 'Ronchi',
                'wheezing' => 'Wheezing',
                'gallop' => 'Gallop',
                'murmur' => 'Murmur',
            ],
            'abdomen_urogenital' => [
                'kesan_abdomen' => 'Kesan Abdomen',
                'hati_lien' => 'Hati / Lien',
                'benjolan_massa' => 'Benjolan Massa',
                'nyeri_tekan' => 'Nyeri Tekan',
                'hernia' => 'Hernia',
                'test_cva' => 'Test CVA',
                'rectal' => 'Rectal Toucher',
                'haemorrhoid' => 'Haemorrhoid',
            ],
            'ekstremitas_neurologis' => [
                'ekstremitas_atas' => 'Ekstremitas Atas',
                'ekstremitas_bawah' => 'Ekstremitas Bawah',
                'range_of_movement' => 'Range of Movement (ROM)',
                'refleks_fisiologis' => 'Refleks Fisiologis',
                'refleks_patologis' => 'Refleks Patologis',
                'kekuatan_motorik' => 'Kekuatan Motorik',
                'kelainan_saraf_pusat' => 'Kelainan Saraf Pusat',
                'kelainan_saraf_tepi' => 'Kelainan Saraf Tepi',
            ],
        ];
    }

    /**
     * Mengecek apakah sebuah item aktif dalam paket ini
     */
    public function isItemActive(string $itemKey): bool
    {
        return ($this->active_items[$itemKey] ?? false) === true;
    }
}
