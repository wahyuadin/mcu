<?php

namespace App\Models;

use Database\Factories\PatientImportBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class PatientImportBatch extends Model
{
    /** @use HasFactory<PatientImportBatchFactory> */
    use HasFactory, HasUuids, Prunable;

    protected $fillable = ['user_id', 'file_name', 'status', 'headers', 'source_rows', 'mapping', 'results', 'file_error', 'version', 'expires_at'];

    protected $attributes = ['version' => 0];

    protected function casts(): array
    {
        return ['headers' => 'array', 'source_rows' => 'encrypted:array', 'mapping' => 'array', 'results' => 'encrypted:array', 'version' => 'integer', 'expires_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }
}
