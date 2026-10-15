<?php

namespace App\Models;

use Database\Factories\DashboardPreferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class DashboardPreference extends Model implements Auditable
{
    /** @use HasFactory<DashboardPreferenceFactory> */
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['user_id', 'role', 'widgets', 'deleted_at'];

    protected function casts(): array
    {
        return ['widgets' => 'array'];
    }
}
