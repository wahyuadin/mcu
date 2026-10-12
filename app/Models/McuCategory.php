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

    protected $fillable = ['name', 'sort_order', 'is_active', 'has_responsible_doctor', 'label_enabled', 'label_barcode_type', 'label_width_mm', 'label_height_mm'];

    protected $attributes = ['has_responsible_doctor' => true, 'label_enabled' => false, 'label_barcode_type' => 'qr', 'label_width_mm' => 80, 'label_height_mm' => 40];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean', 'has_responsible_doctor' => 'boolean', 'label_enabled' => 'boolean', 'label_width_mm' => 'integer', 'label_height_mm' => 'integer'];
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
