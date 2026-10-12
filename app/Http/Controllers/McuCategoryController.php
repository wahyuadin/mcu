<?php

namespace App\Http\Controllers;

use App\Models\McuCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        return view('mcu.masters.category-form', ['category' => new McuCategory(['is_active' => true, 'sort_order' => 0])]);
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
        return view('mcu.masters.category-form', ['category' => $category]);
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

    /** @return array{name: string, sort_order?: int, is_active: bool, has_responsible_doctor?: bool} */
    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'], 'is_active' => ['required', 'boolean'], 'has_responsible_doctor' => ['sometimes', 'required', 'boolean']]);
    }
}
