<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Perusahaan extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'perusahaans';

    protected $fillable = [
        'nama_perusahaan',
        'alamat',
        'telp',
        'email',
        'pic_nama',
        'pic_kontak',
    ];

    /**
     * Relasi ke Rencana Kunjungan
     */
    public function rencanaKunjungans(): HasMany
    {
        return $this->hasMany(RencanaKunjungan::class, 'perusahaan_id');
    }

    /**
     * Relasi ke Paket
     */
    public function pakets(): HasMany
    {
        return $this->hasMany(Paket::class, 'perusahaan_id');
    }

    /**
     * Relasi ke Peserta Karyawan
     */
    public function karyawans(): HasMany
    {
        return $this->hasMany(Karyawan::class, 'perusahaan_id');
    }
}
