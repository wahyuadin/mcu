<?php

namespace App\Http\Controllers;

use App\Models\McuCategory;
use App\Models\McuItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class McuItemController extends Controller
{
    public function index(Request $request): View
    {
        return view('mcu.masters.items', [
            'items' => McuItem::with('category')->when($request->filled('category'), fn ($query) => $query->where('mcu_category_id', $request->query('category')))->orderBy('sort_order')->orderBy('label')->paginate(30)->withQueryString(),
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
            $first = null;
            foreach ($data['questions'] as $question) {
                $question['mcu_category_id'] = $category->id;
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
            $primary = $data['questions'][0];
            $primary['mcu_category_id'] = $data['mcu_category_id'];
            $item->update($primary);
            foreach (array_slice($data['questions'], 1) as $question) {
                $question['mcu_category_id'] = $data['mcu_category_id'];
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
        return view('mcu.masters.item-form', ['item' => $item, 'categories' => McuCategory::orderBy('name')->get(), 'types' => McuItem::inputTypes()]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $base = $request->validate(['mcu_category_id' => ['required', Rule::exists('mcu_categories', 'id')], 'questions' => ['sometimes', 'array', 'list', 'min:1', 'max:50']]);
        $prefixes = $request->has('questions') ? array_map(fn (int $index): string => 'questions.'.$index.'.', array_keys($base['questions'])) : [''];
        $rules = [];
        foreach ($prefixes as $prefix) {
            $rules += [
                $prefix.'label' => ['required', 'string', 'max:255'], $prefix.'input_type' => ['required', Rule::in(array_keys(McuItem::inputTypes()))],
                $prefix.'yes_report' => ['exclude_unless:'.$prefix.'input_type,radio', 'required', 'string', 'max:2000'],
                $prefix.'no_report' => ['exclude_unless:'.$prefix.'input_type,radio', 'required', 'string', 'max:2000'],
                $prefix.'help_text' => ['nullable', 'string', 'max:2000'], $prefix.'is_required' => ['required', 'boolean'],
                $prefix.'is_active' => ['required', 'boolean'], $prefix.'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            ];
        }
        $validated = $request->validate($rules);
        $questions = $request->has('questions') ? $validated['questions'] : [$validated];
        foreach ($questions as &$question) {
            if ($question['input_type'] !== 'radio') {
                $question['yes_report'] = $question['no_report'] = null;
            }
        }
        $base['questions'] = $questions;

        return $base;
    }
}
