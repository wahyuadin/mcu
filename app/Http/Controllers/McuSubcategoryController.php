<?php

namespace App\Http\Controllers;

use App\Models\McuCategory;
use App\Models\McuSubcategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class McuSubcategoryController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['category' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:255']]);

        return view('mcu.masters.subcategories', [
            'subcategories' => McuSubcategory::with('category')->withCount('items')->when($data['category'] ?? null, fn ($query, $id) => $query->where('mcu_category_id', $id))->when($data['q'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))->orderBy('sort_order')->orderBy('name')->paginate(30)->withQueryString(),
            'categories' => McuCategory::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form(new McuSubcategory(['mcu_category_id' => $request->query('category'), 'is_active' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data): void {
            McuCategory::whereKey($data['mcu_category_id'])->lockForUpdate()->firstOrFail();
            $data['sort_order'] = (int) McuSubcategory::where('mcu_category_id', $data['mcu_category_id'])->max('sort_order') + 1;
            McuSubcategory::create($data);
        });

        return redirect()->route('mcu-subcategory.index')->with('success', 'Subkategori disimpan. Tambahkan item pemeriksaan untuk memilihnya dalam paket.');
    }

    public function edit(McuSubcategory $subcategory): View
    {
        return $this->form($subcategory);
    }

    public function update(Request $request, McuSubcategory $subcategory): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $subcategory): void {
            McuCategory::whereIn('id', [$data['mcu_category_id'], $subcategory->mcu_category_id])->orderBy('id')->lockForUpdate()->get();
            $subcategory = McuSubcategory::whereKey($subcategory->id)->lockForUpdate()->firstOrFail();
            if ($data['mcu_category_id'] !== $subcategory->mcu_category_id && $subcategory->items()->exists()) {
                throw ValidationException::withMessages(['mcu_category_id' => 'Subkategori dengan item tidak dapat dipindahkan. Pindahkan item terlebih dahulu.']);
            }
            $subcategory->update($data);
        });

        return redirect()->route('mcu-subcategory.index')->with('success', 'Subkategori diperbarui. Snapshot paket dan hasil lama tidak berubah.');
    }

    public function destroy(McuSubcategory $subcategory): RedirectResponse
    {
        DB::transaction(function () use ($subcategory): void {
            McuCategory::whereKey($subcategory->mcu_category_id)->lockForUpdate()->firstOrFail();
            $subcategory = McuSubcategory::whereKey($subcategory->id)->lockForUpdate()->firstOrFail();
            if ($subcategory->items()->exists()) {
                throw ValidationException::withMessages(['subcategory' => 'Subkategori masih memiliki item. Hapus atau pindahkan item terlebih dahulu.']);
            }
            $subcategory->delete();
        });

        return redirect()->route('mcu-subcategory.index')->with('success', 'Subkategori dihapus. Paket dan hasil lama tetap tersimpan.');
    }

    private function form(McuSubcategory $subcategory): View
    {
        return view('mcu.masters.subcategory-form', ['subcategory' => $subcategory, 'categories' => McuCategory::orderBy('name')->get()]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate(['mcu_category_id' => ['required', 'uuid', Rule::exists('mcu_categories', 'id')], 'name' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']]);
    }
}
