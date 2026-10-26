<?php

namespace App\Models;

use Database\Factories\McuItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class McuItem extends Model implements Auditable
{
    /** @use HasFactory<McuItemFactory> */
    use AuditableTrait, HasFactory, HasUuids;

    protected $fillable = ['mcu_category_id', 'mcu_subcategory_id', 'lab_config', 'allow_attachment', 'label', 'input_type', 'yes_report', 'no_report', 'default_answer', 'help_text', 'is_required', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['lab_config' => 'array', 'allow_attachment' => 'boolean', 'is_required' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
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
        if ($this->input_type === 'laboratory') {
            return ($this->lab_config['result_type'] ?? 'text') === 'number' ? 'number' : 'text';
        }

        return in_array($this->input_type, ['existing', 'label'], true) ? 'text' : $this->input_type;
    }

    public function inputLabel(): string
    {
        return $this->input_type === 'label' ? 'Informasi (format lama)' : (self::inputTypes()[$this->editorType()] ?? 'Isian teks');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(McuSubcategory::class, 'mcu_subcategory_id');
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $definition = ['id' => $this->id, 'label' => $this->label, 'type' => $this->input_type === 'label' ? 'label' : $this->editorType(), 'required' => $this->input_type !== 'label' && $this->is_required,
            'yes_report' => $this->yes_report, 'no_report' => $this->no_report, 'help_text' => $this->help_text,
            'allow_attachment' => in_array($this->editorType(), ['text', 'number'], true) && (bool) $this->allow_attachment];
        if ($this->mcu_subcategory_id && $this->subcategory) {
            $definition['subcategory'] = ['id' => $this->subcategory->id, 'name' => $this->subcategory->name, 'order' => $this->subcategory->sort_order];
        }
        if ($this->input_type === 'radio') {
            $definition['default_answer'] = $this->default_answer;
        }
        if ($this->category->isPhysical()) {
            $definition['physical_key'] = $this->legacy_key;
        }
        if ($this->input_type === 'laboratory' || (in_array($this->input_type, ['text', 'number'], true) && $this->lab_config !== null)) {
            $definition['type'] = 'laboratory';
            $definition['lab_config'] = $this->lab_config;
        }

        return $definition;
    }

    /** @param array<string, mixed> $item
     * @return list<mixed>
     */
    public static function answerRules(array $item): array
    {
        return match ($item['physical_key'] ?? null) {
            'tinggi_badan', 'berat_badan', 'lingkar_pinggang' => ['nullable', 'numeric', 'gt:0', 'max:999'],
            'suhu_badan' => ['nullable', 'numeric', 'gt:0', 'max:99'],
            'nadi', 'pernapasan' => ['nullable', 'integer', 'min:1', 'max:300'],
            'tekanan_darah' => ['nullable', 'regex:/^\d{2,3}\/\d{2,3}$/'],
            default => match ($item['type']) {
                'radio' => ['nullable', Rule::in(['YA', 'TIDAK'])],
                'number' => ['nullable', 'numeric', 'between:-999999999,999999999'],
                'laboratory' => ($item['lab_config']['result_type'] ?? 'text') === 'number' ? ['nullable', 'numeric', 'between:-999999999,999999999'] : ['nullable', 'string', 'max:2000'],
                default => ['nullable', 'string', 'max:2000'],
            },
        };
    }
}
