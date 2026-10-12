<?php

namespace App\Models;

use Database\Factories\McuCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class McuCategory extends Model implements Auditable
{
    /** @use HasFactory<McuCategoryFactory> */
    use AuditableTrait, HasFactory, HasUuids;

    protected $fillable = ['name', 'sort_order', 'is_active', 'has_responsible_doctor'];

    protected $attributes = ['has_responsible_doctor' => true];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean', 'has_responsible_doctor' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(McuItem::class)->orderBy('sort_order')->orderBy('label');
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(McuSubcategory::class)->orderBy('sort_order')->orderBy('name');
    }
}
