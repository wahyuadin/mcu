<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Anamnesa extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'anamnesas';

    protected $fillable = [
        'karyawan_id',
        'rencana_kunjungan_id',
        'dokter_id',
        'header_form',
        'sub_header',
        'keluhan_saat_ini',
        'riwayat_penyakit_dahulu',
        'riwayat_penyakit_keluarga',
        'riwayat_kebiasaan',
        'tinggi_badan',
        'berat_badan',
        'bmi',
        'kategori_bmi',
        'tekanan_darah',
        'nadi',
        'lingkar_pinggang',
        'pernapasan',
        'suhu_badan',
        'visus_od_tanpa_kacamata',
        'visus_os_tanpa_kacamata',
        'visus_od_dengan_kacamata',
        'visus_os_dengan_kacamata',
        'ishihara_test',
        'kesan_umum',
        'kulit',
        'mata',
        'kesan_telinga',
        'kesan_hidung',
        'kesan_tenggorokan',
        'kesan_mulut',
        'gigi',
        'kesan_leher',
        'kesan_thorax',
        'ronchi',
        'wheezing',
        'gallop',
        'murmur',
        'kesan_abdomen',
        'hati_lien',
        'benjolan_massa',
        'nyeri_tekan',
        'hernia',
        'test_cva',
        'rectal',
        'haemorrhoid',
        'ekstremitas_atas',
        'ekstremitas_bawah',
        'range_of_movement',
        'refleks_fisiologis',
        'refleks_patologis',
        'kekuatan_motorik',
        'kelainan_saraf_pusat',
        'kelainan_saraf_tepi',
        'catatan_dokter',
        'anjuran_saran',
        'status_kelaikan',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'riwayat_penyakit_dahulu' => 'array',
            'riwayat_penyakit_keluarga' => 'array',
            'riwayat_kebiasaan' => 'array',
            'tinggi_badan' => 'decimal:2',
            'berat_badan' => 'decimal:2',
            'bmi' => 'decimal:2',
            'lingkar_pinggang' => 'decimal:2',
            'suhu_badan' => 'decimal:2',
            'nadi' => 'integer',
            'pernapasan' => 'integer',
        ];
    }

    /**
     * Hitung Nilai BMI & Kategori BMI otomatis dari Tinggi (cm) dan Berat (kg)
     */
    public static function calculateBmi(?float $tinggiCm, ?float $beratKg): array
    {
        if (! $tinggiCm || ! $beratKg || $tinggiCm <= 0 || $beratKg <= 0) {
            return [
                'bmi' => null,
                'kategori' => null,
            ];
        }

        $tinggiMeter = $tinggiCm / 100;
        $bmi = round($beratKg / ($tinggiMeter * $tinggiMeter), 2);

        $kategori = match (true) {
            $bmi < 18.5 => 'Berat Badan Kurang (Underweight)',
            $bmi >= 18.5 && $bmi <= 22.9 => 'Normal',
            $bmi >= 23.0 && $bmi <= 24.9 => 'Kelebihan Berat Badan (Overweight)',
            $bmi >= 25.0 && $bmi <= 29.9 => 'Obesitas I',
            default => 'Obesitas II',
        };

        return [
            'bmi' => $bmi,
            'kategori' => $kategori,
        ];
    }

    /**
     * Relasi ke Karyawan
     */
    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_id');
    }

    /**
     * Relasi ke Rencana Kunjungan
     */
    public function rencanaKunjungan(): BelongsTo
    {
        return $this->belongsTo(RencanaKunjungan::class, 'rencana_kunjungan_id');
    }

    /**
     * Relasi ke Dokter Pemeriksa
     */
    public function dokter(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_id');
    }
}
