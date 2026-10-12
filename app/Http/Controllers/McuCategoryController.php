<?php

namespace App\Http\Controllers;

use App\LabelBarcode;
use App\Models\McuCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class McuCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $search = trim($data['q'] ?? '');

        return view('mcu.masters.categories', [
            'categories' => McuCategory::withCount('items')->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))->orderBy('sort_order')->orderBy('name')->paginate(30)->withQueryString(),
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        return $this->form(new McuCategory(['is_active' => true, 'sort_order' => 0]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] ??= (int) McuCategory::max('sort_order') + 1;
        McuCategory::create($data);

        return redirect()->route('mcu-category.index')->with('success', 'Kategori disimpan. Tambahkan item untuk memetakan form.');
    }

    public function edit(McuCategory $category): View
    {
        return $this->form($category);
    }

    public function update(Request $request, McuCategory $category): RedirectResponse
    {
        $category->update($this->validated($request));

        return redirect()->route('mcu-category.index')->with('success', 'Kategori diperbarui. Konfigurasi paket tersimpan tidak berubah.');
    }

    public function destroy(McuCategory $category): RedirectResponse
    {
        DB::transaction(function () use ($category): void {
            $category = McuCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
            if ($category->subcategories()->exists()) {
                throw ValidationException::withMessages(['category' => 'Kategori masih memiliki subkategori. Hapus atau pindahkan subkategori terlebih dahulu.']);
            }
            if ($category->items()->exists()) {
                throw ValidationException::withMessages(['category' => 'Kategori masih memiliki item. Hapus atau pindahkan item terlebih dahulu.']);
            }
            $category->delete();
        });

        return redirect()->route('mcu-category.index')->with('success', 'Kategori dihapus. Paket dan hasil pemeriksaan tersimpan tidak berubah.');
    }

    private function form(McuCategory $category): View
    {
        $sampleUrl = route('pemeriksaan.edit', ['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002', 'tab' => 'kategori-00000000-0000-4000-8000-000000000003', 'subcategory' => '00000000-0000-4000-8000-000000000004']);

        return view('mcu.masters.category-form', ['category' => $category->load('subcategories'), 'labelBarcodes' => (new LabelBarcode)->images($sampleUrl)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'], 'is_active' => ['required', 'boolean'], 'has_responsible_doctor' => ['sometimes', 'required', 'boolean'],
            'label_enabled' => ['sometimes', 'required', 'boolean'],
            'label_barcode_type' => ['required_if:label_enabled,1', Rule::in(['qr', 'code128'])],
            'label_width_mm' => ['required_if:label_enabled,1', 'integer', 'min:40', 'max:300'],
            'label_height_mm' => ['required_if:label_enabled,1', 'integer', 'min:30', 'max:200'],
        ]);
    }
}
