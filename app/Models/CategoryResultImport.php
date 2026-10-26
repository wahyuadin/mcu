<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class CategoryResultImport extends Model
{
    use HasUuids, Prunable;

    protected $fillable = ['user_id', 'rencana_kunjungan_id', 'mcu_category_id', 'file_name', 'source', 'digest', 'status', 'results', 'expires_at'];

    protected $hidden = ['source', 'results'];

    protected function casts(): array
    {
        return ['source' => 'encrypted:array', 'results' => 'encrypted:array', 'expires_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }
}
