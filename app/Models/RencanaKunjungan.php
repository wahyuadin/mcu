<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    protected $hidden = ['anamnesa_token', 'anamnesa_token_hash'];

    protected $auditExclude = ['anamnesa_token', 'anamnesa_token_hash'];

    protected $fillable = [
        'perusahaan_id',
        'nomor_kunjungan',
        'periode_kunjungan',
        'tgl_mulai',
        'tgl_selesai',
        'kuota_peserta',
        'jumlah_peserta_direncanakan',
        'is_closing',
        'status',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'anamnesa_token' => 'encrypted',
            'tgl_mulai' => 'date',
            'tgl_selesai' => 'date',
            'kuota_peserta' => 'integer',
            'jumlah_peserta_direncanakan' => 'integer',
            'is_closing' => 'boolean',
        ];
    }

    public function scopeDashboardStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status === 'closed', fn (Builder $query): Builder => $query->where('is_closing', true))
            ->when(in_array($status, ['draft', 'ongoing', 'completed'], true), fn (Builder $query): Builder => $query->where('status', $status)->where('is_closing', false));
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
