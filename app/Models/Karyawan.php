<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Karyawan extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'karyawans';

    protected $hidden = ['anamnesa_submission'];

    protected $auditExclude = ['anamnesa_submission'];

    protected $fillable = [
        'pasien_id',
        'nomor_mcu',
        'tanggal_mcu',
        'alasan_status',
        'perusahaan_id',
        'rencana_kunjungan_id',
        'paket_id',
        'nik',
        'nip',
        'nama_karyawan',
        'jenis_kelamin',
        'tanggal_lahir',
        'tempat_lahir',
        'bagian',
        'jabatan',
        'telepon',
        'foto',
        'status_pemeriksaan',
    ];

    protected function casts(): array
    {
        return [
            'anamnesa_submission' => 'encrypted:array',
            'tanggal_lahir' => 'date',
            'tanggal_mcu' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Karyawan $episode): void {
            $episode->nomor_mcu ??= 'MCU-'.strtoupper((string) Str::ulid());
            if (! $episode->pasien_id) {
                $episode->pasien_id = Pasien::create([
                    'nama' => $episode->nama_karyawan, 'nik' => $episode->nik,
                    'jenis_kelamin' => $episode->jenis_kelamin ?? 'L', 'tanggal_lahir' => $episode->tanggal_lahir, 'tempat_lahir' => $episode->tempat_lahir,
                    'telepon' => $episode->telepon, 'foto' => $episode->foto,
                ])->id;
            }
        });
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class);
    }

    public function hasStoredPhoto(): bool
    {
        return filled($this->foto) && str_starts_with($this->foto, 'mcu/'.$this->id.'/') && Storage::disk('local')->exists($this->foto);
    }

    /**
     * Hitung Usia Karyawan berdasarkan Tanggal Lahir
     */
    protected function usia(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->tanggal_lahir ? (int) Carbon::parse($this->tanggal_lahir)->diffInYears($this->tanggal_mcu ?? now()) : null,
        );
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
     * Relasi ke Paket MCU
     */
    public function paket(): BelongsTo
    {
        return $this->belongsTo(Paket::class, 'paket_id');
    }

    /**
     * Relasi ke Hasil Anamnesa & Fisik
     */
    public function anamnesa(): HasOne
    {
        return $this->hasOne(Anamnesa::class, 'karyawan_id');
    }
}
