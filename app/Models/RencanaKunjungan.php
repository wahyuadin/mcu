<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class RencanaKunjungan extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'rencana_kunjungans';

    protected $fillable = [
        'perusahaan_id',
        'nomor_kunjungan',
        'periode_kunjungan',
        'tgl_mulai',
        'tgl_selesai',
        'kuota_peserta',
        'is_closing',
        'status',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'tgl_mulai' => 'date',
            'tgl_selesai' => 'date',
            'kuota_peserta' => 'integer',
            'is_closing' => 'boolean',
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
     * Relasi ke Paket-Paket yang tersedia di Kunjungan ini
     */
    public function pakets(): BelongsToMany
    {
        return $this->belongsToMany(Paket::class, 'paket_rencana_kunjungan', 'rencana_kunjungan_id', 'paket_id')->withTimestamps();
    }

    /**
     * Relasi ke Peserta Karyawan pada Kunjungan ini
     */
    public function karyawans(): HasMany
    {
        return $this->hasMany(Karyawan::class, 'rencana_kunjungan_id');
    }

    /**
     * Relasi ke Anamnesa pada Kunjungan ini
     */
    public function anamnesas(): HasMany
    {
        return $this->hasMany(Anamnesa::class, 'rencana_kunjungan_id');
    }
}
