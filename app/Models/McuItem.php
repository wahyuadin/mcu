<?php

namespace App\Models;

use Database\Factories\McuItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class McuItem extends Model implements Auditable
{
    /** @use HasFactory<McuItemFactory> */
    use AuditableTrait, HasFactory, HasUuids;

    protected $fillable = ['mcu_category_id', 'label', 'input_type', 'yes_report', 'no_report', 'help_text', 'is_required', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(McuCategory::class, 'mcu_category_id');
    }

    /** @return array<string, string> */
    public static function inputTypes(): array
    {
        return ['radio' => 'Radio YA / TIDAK', 'text' => 'Isian teks', 'number' => 'Isian angka'];
    }

    public function editorType(): string
    {
        return in_array($this->input_type, ['existing', 'label'], true) ? 'text' : $this->input_type;
    }

    public function inputLabel(): string
    {
        return $this->input_type === 'label' ? 'Informasi (format lama)' : (self::inputTypes()[$this->editorType()] ?? 'Isian teks');
    }

    /** @return array{id: string, label: string, type: string, required: bool, yes_report: ?string, no_report: ?string, help_text: ?string} */
    public function definition(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'type' => $this->input_type === 'label' ? 'label' : $this->editorType(), 'required' => $this->input_type !== 'label' && $this->is_required,
            'yes_report' => $this->yes_report, 'no_report' => $this->no_report, 'help_text' => $this->help_text];
    }
}
