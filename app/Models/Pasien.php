<?php

namespace App\Models;

use Database\Factories\PasienFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Pasien extends Model implements Auditable
{
    /** @use HasFactory<PasienFactory> */
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['nama', 'nik', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'telepon', 'foto'];

    protected function casts(): array
    {
        return ['tanggal_lahir' => 'date'];
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Karyawan::class);
    }
}
