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
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Karyawan extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'karyawans';

    protected $fillable = [
        'perusahaan_id',
        'rencana_kunjungan_id',
        'paket_id',
        'nik',
        'nip',
        'nama_karyawan',
        'jenis_kelamin',
        'tanggal_lahir',
        'bagian',
        'jabatan',
        'telepon',
        'foto',
        'status_pemeriksaan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
        ];
    }

    /**
     * Hitung Usia Karyawan berdasarkan Tanggal Lahir
     */
    protected function usia(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->tanggal_lahir ? Carbon::parse($this->tanggal_lahir)->age : null,
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
