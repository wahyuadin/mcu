<?php

namespace App\Http\Controllers;

use App\Models\McuCategory;
use App\Models\McuSubcategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class McuSubcategoryOrderController extends Controller
{
    public function show(McuCategory $category): JsonResponse
    {
        $items = $category->subcategories()->get();

        return response()->json(['category' => $category->name, 'version' => $this->version($items), 'items' => $items->map(fn (McuSubcategory $item): array => ['id' => $item->id, 'label' => $item->name, 'type' => 'Subkategori', 'is_active' => $item->is_active])->values()])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, McuCategory $category): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'size:64'], 'items' => ['required', 'array', 'list', 'min:1', 'max:10000'], 'items.*' => ['required', 'uuid', 'distinct']]);
        $version = DB::transaction(function () use ($category, $data): string {
            McuCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
            $items = $category->subcategories()->lockForUpdate()->get();
            if (! hash_equals($this->version($items), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Daftar subkategori berubah. Tutup dan buka kembali pengaturan urutan.']);
            }
            if (count($data['items']) !== $items->count() || array_diff($data['items'], $items->modelKeys()) !== []) {
                throw ValidationException::withMessages(['items' => 'Urutan harus memuat seluruh subkategori dalam kategori ini, tanpa duplikasi.']);
            }
            $byId = $items->keyBy('id');
            foreach ($data['items'] as $position => $id) {
                $byId[$id]->update(['sort_order' => $position]);
            }

            return $this->version($category->subcategories()->get());
        });

        return response()->json(['version' => $version, 'message' => 'Urutan subkategori disimpan. Snapshot paket dan hasil lama tidak berubah.']);
    }

    /** @param Collection<int, McuSubcategory> $items */
    private function version(Collection $items): string
    {
        return hash('sha256', $items->map(fn (McuSubcategory $item): array => [$item->id, $item->sort_order, $item->name, $item->is_active, $item->getRawOriginal('updated_at')])->toJson());
    }
}
