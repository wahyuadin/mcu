<?php

namespace App\Models;

use App\McuCategoryPresentation;
use Database\Factories\McuCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class McuCategory extends Model implements Auditable
{
    /** @use HasFactory<McuCategoryFactory> */
    use AuditableTrait, HasFactory, HasUuids;

    protected $fillable = ['name', 'sort_order', 'is_active', 'has_responsible_doctor', 'label_enabled', 'label_barcode_type', 'label_width_mm', 'label_height_mm', 'import_enabled'];

    protected $attributes = ['has_responsible_doctor' => true, 'label_enabled' => false, 'label_barcode_type' => 'qr', 'label_width_mm' => 80, 'label_height_mm' => 40];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean', 'has_responsible_doctor' => 'boolean', 'label_enabled' => 'boolean', 'label_width_mm' => 'integer', 'label_height_mm' => 'integer', 'import_enabled' => 'boolean'];
    }

    public function isAnamnesis(): bool
    {
        return $this->legacy_group === 'anamnesa_riwayat';
    }

    public function isPhysical(): bool
    {
        return $this->legacy_group === 'pemeriksaan_fisik';
    }

    public function isProtected(): bool
    {
        return $this->isAnamnesis() || $this->isPhysical();
    }

    protected static function booted(): void
    {
        static::saved(fn () => app(McuCategoryPresentation::class)->refresh());
        static::deleted(fn () => app(McuCategoryPresentation::class)->refresh());
        static::deleting(function (McuCategory $category): void {
            if ($category->isProtected()) {
                throw ValidationException::withMessages(['category' => 'Kategori '.$category->name.' adalah kategori bawaan dan tidak dapat dihapus.']);
            }
        });
        static::updating(function (McuCategory $category): void {
            if (in_array($category->getRawOriginal('legacy_group'), ['anamnesa_riwayat', 'pemeriksaan_fisik'], true) && $category->isDirty('legacy_group')) {
                throw ValidationException::withMessages(['category' => 'Identitas internal kategori bawaan tidak dapat diubah.']);
            }
        });
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
