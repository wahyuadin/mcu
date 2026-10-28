<?php

namespace App\Models;

use Database\Factories\HrdDashboardPreferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class HrdDashboardPreference extends Model implements Auditable
{
    /** @use HasFactory<HrdDashboardPreferenceFactory> */
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['user_id', 'perusahaan_id', 'widgets', 'deleted_at'];

    public function perusahaan(): BelongsTo
    {
        return $this->belongsTo(Perusahaan::class);
    }

    protected function casts(): array
    {
        return ['widgets' => 'array'];
    }
}
