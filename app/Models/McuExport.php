<?php

namespace App\Models;

use Database\Factories\McuExportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class McuExport extends Model implements Auditable
{
    /** @use HasFactory<McuExportFactory> */
    use AuditableTrait, HasFactory, HasUuids, Prunable;

    protected $fillable = ['user_id', 'perusahaan_id', 'status', 'manifest', 'total', 'processed', 'path', 'expires_at'];

    protected $hidden = ['manifest', 'path'];

    protected $auditExclude = ['manifest', 'path'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'expires_at' => 'datetime', 'total' => 'integer', 'processed' => 'integer'];
    }

    public function accessibleBy(User $user): bool
    {
        return $this->user_id === $user->id && ($user->role === 'admin' || ($user->role === 'hrd' && $user->perusahaan_id === $this->perusahaan_id && $user->perusahaan()->exists()));
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }

    protected function pruning(): void
    {
        Storage::disk('local')->deleteDirectory('mcu-exports/'.$this->id);
    }
}
