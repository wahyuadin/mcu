<?php

namespace App\Http\Controllers;

use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\McuSubcategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class McuItemController extends Controller
{
    public function index(Request $request): View
    {
        return view('mcu.masters.items', [
            'items' => McuItem::with('category', 'subcategory')->when($request->filled('category'), fn ($query) => $query->where('mcu_category_id', $request->query('category')))->orderBy('sort_order')->orderBy('label')->paginate(30)->withQueryString(),
            'categories' => McuCategory::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form(new McuItem(['mcu_category_id' => $request->query('category'), 'input_type' => 'radio', 'is_active' => true, 'is_required' => true, 'sort_order' => 0]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $item = DB::transaction(function () use ($data): McuItem {
            $category = McuCategory::whereKey($data['mcu_category_id'])->lockForUpdate()->firstOrFail();
            $this->assertSubcategory($data);
            $first = null;
            foreach ($data['questions'] as $question) {
                $question['mcu_category_id'] = $category->id;
                $question['mcu_subcategory_id'] = $data['mcu_subcategory_id'] ?? null;
                $question['sort_order'] ??= (int) $category->items()->max('sort_order') + 1;
                $created = McuItem::create($question);
                $first ??= $created;
            }

            return $first;
        });

        return redirect()->route('mcu-item.index', ['category' => $item->mcu_category_id])->with('success', 'Item dan mapping laporan disimpan. Item tersedia saat menyusun paket.');
    }

    public function edit(McuItem $item): View
    {
        return $this->form($item);
    }

    public function update(Request $request, McuItem $item): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $item): void {
            McuCategory::whereIn('id', array_unique([$item->mcu_category_id, $data['mcu_category_id']]))->orderBy('id')->lockForUpdate()->get();
            $this->assertSubcategory($data);
            $primary = $data['questions'][0];
            $primary['mcu_category_id'] = $data['mcu_category_id'];
            $primary['mcu_subcategory_id'] = $data['mcu_subcategory_id'] ?? null;
            $item->update($primary);
            foreach (array_slice($data['questions'], 1) as $question) {
                $question['mcu_category_id'] = $data['mcu_category_id'];
                $question['mcu_subcategory_id'] = $data['mcu_subcategory_id'] ?? null;
                $question['sort_order'] = (int) McuItem::where('mcu_category_id', $data['mcu_category_id'])->max('sort_order') + 1;
                McuItem::create($question);
            }
        });

        return redirect()->route('mcu-item.index', ['category' => $item->mcu_category_id])->with('success', 'Item diperbarui. Mapping paket dan pemeriksaan lama tetap dipertahankan.');
    }

    public function destroy(McuItem $item): RedirectResponse
    {
        $categoryId = $item->mcu_category_id;
        $item->delete();

        return redirect()->route('mcu-item.index', ['category' => $categoryId])->with('success', 'Item dihapus. Mapping paket dan hasil pemeriksaan tersimpan tidak berubah.');
    }

    private function form(McuItem $item): View
    {
        return view('mcu.masters.item-form', ['item' => $item, 'subcategories' => McuSubcategory::with('category')->orderBy('sort_order')->orderBy('name')->get(), 'categories' => McuCategory::orderBy('name')->get(), 'types' => McuItem::inputTypes()]);
    }

    /** @param array<string, mixed> $data */
    private function assertSubcategory(array $data): void
    {
        if (! empty($data['mcu_subcategory_id']) && ! McuSubcategory::whereKey($data['mcu_subcategory_id'])->where('mcu_category_id', $data['mcu_category_id'])->exists()) {
            throw ValidationException::withMessages(['mcu_subcategory_id' => 'Subkategori telah dihapus atau dipindahkan. Muat ulang pilihan kategori.']);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $base = $request->validate(['mcu_category_id' => ['required', Rule::exists('mcu_categories', 'id')], 'mcu_subcategory_id' => ['nullable', 'uuid', Rule::exists('mcu_subcategories', 'id')->where('mcu_category_id', $request->input('mcu_category_id'))], 'questions' => ['sometimes', 'array', 'list', 'min:1', 'max:50']]);
        $prefixes = $request->has('questions') ? array_map(fn (int $index): string => 'questions.'.$index.'.', array_keys($base['questions'])) : [''];
        $rules = [];
        foreach ($prefixes as $prefix) {
            $rules += [
                $prefix.'label' => ['required', 'string', 'max:255'], $prefix.'input_type' => ['required', Rule::in(array_keys(McuItem::inputTypes()))],
                $prefix.'yes_report' => ['exclude_unless:'.$prefix.'input_type,radio', 'required', 'string', 'max:2000'],
                $prefix.'no_report' => ['exclude_unless:'.$prefix.'input_type,radio', 'required', 'string', 'max:2000'],
                $prefix.'help_text' => ['nullable', 'string', 'max:2000'], $prefix.'is_required' => ['required', 'boolean'],
                $prefix.'is_active' => ['required', 'boolean'], $prefix.'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
                $prefix.'allow_attachment' => ['sometimes', 'boolean'],
            ];
            $inputType = $request->input($prefix.'input_type');
            $configured = in_array($inputType, ['text', 'number'], true) && ($request->filled($prefix.'lab_reference') || $request->filled($prefix.'lab_default_result'));
            $lab = $configured ? [] : ['exclude'];
            $rules += [
                $prefix.'lab_default_result' => [...$lab, 'nullable', ...($inputType === 'number' ? ['numeric', 'between:-999999999,999999999'] : ['string', 'max:2000'])],
                $prefix.'lab_rule' => [...$lab, 'required', Rule::in(['range', 'lt', 'lte', 'gt', 'gte', 'text'])],
                $prefix.'lab_min' => [...$lab, 'nullable', 'numeric', 'between:-999999999,999999999'],
                $prefix.'lab_max' => [...$lab, 'nullable', 'numeric', 'between:-999999999,999999999'],
                $prefix.'lab_normal_values' => [...$lab, 'nullable', 'string', 'max:2000'],
                $prefix.'lab_unit' => [...$lab, 'nullable', 'string', 'max:100'],
                $prefix.'lab_reference' => [...$lab, 'required', 'string', 'max:500'],
                $prefix.'lab_normal_report' => [...$lab, 'nullable', 'string', 'max:2000'],
                $prefix.'lab_abnormal_report' => [...$lab, 'nullable', 'string', 'max:2000'],
            ];
        }
        $validated = $request->validate($rules);
        $questions = $request->has('questions') ? $validated['questions'] : [$validated];
        foreach ($questions as $index => &$question) {
            $question['allow_attachment'] = in_array($question['input_type'], ['text', 'number'], true) && (bool) ($question['allow_attachment'] ?? false);
            if ($question['input_type'] !== 'radio') {
                $question['yes_report'] = $question['no_report'] = null;
            }
            $question['lab_config'] = null;
            if (in_array($question['input_type'], ['text', 'number'], true) && filled($question['lab_reference'] ?? null)) {
                $errorKey = ($request->has('questions') ? 'questions.'.$index.'.' : '').'lab_rule';
                $rule = $question['lab_rule'];
                $resultType = $question['input_type'];
                $numeric = $resultType === 'number';
                $min = $question['lab_min'] ?? null;
                $max = $question['lab_max'] ?? null;
                $values = array_values(array_filter(array_map('trim', preg_split('/\R/u', $question['lab_normal_values'] ?? '') ?: []), fn (string $value): bool => $value !== ''));
                if (($numeric && $rule === 'text') || (! $numeric && $rule !== 'text') || (! $numeric && $values === [])
                    || ($numeric && in_array($rule, ['range', 'gt', 'gte'], true) && $min === null)
                    || ($numeric && in_array($rule, ['range', 'lt', 'lte'], true) && $max === null)
                    || ($rule === 'range' && (float) $min > (float) $max)) {
                    throw ValidationException::withMessages([$errorKey => 'Aturan normal tidak sesuai tipe hasil, batas belum lengkap, atau minimum melebihi maksimum. Isi hasil teks normal satu per baris.']);
                }
                $question['lab_config'] = ['result_type' => $resultType, 'rule' => $rule, 'min' => $numeric && $min !== null ? (float) $min : null,
                    'max' => $numeric && $max !== null ? (float) $max : null, 'normal_values' => $numeric ? [] : $values,
                    'unit' => $question['lab_unit'] ?? '', 'reference' => $question['lab_reference'], 'normal_report' => $question['lab_normal_report'] ?? '', 'abnormal_report' => $question['lab_abnormal_report'] ?? '',
                    'default_result' => $question['lab_default_result'] ?? null];
            }
            foreach (array_keys($question) as $key) {
                if (str_starts_with($key, 'lab_') && $key !== 'lab_config') {
                    unset($question[$key]);
                }
            }
        }
        $base['questions'] = $questions;

        return $base;
    }
}
