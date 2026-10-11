<?php

namespace App\Models;

use Database\Factories\McuSubcategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class McuSubcategory extends Model implements Auditable
{
    /** @use HasFactory<McuSubcategoryFactory> */
    use AuditableTrait, HasFactory, HasUuids;

    protected $fillable = ['mcu_category_id', 'name', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(McuCategory::class, 'mcu_category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(McuItem::class)->orderBy('sort_order')->orderBy('label');
    }
}
