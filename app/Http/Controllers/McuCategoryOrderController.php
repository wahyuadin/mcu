<?php

namespace App\Http\Controllers;

use App\Models\McuCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class McuCategoryOrderController extends Controller
{
    public function show(): JsonResponse
    {
        $categories = McuCategory::orderBy('sort_order')->orderBy('name')->orderBy('id')->get();

        return response()->json([
            'category' => 'Seluruh kategori MCU', 'version' => $this->version($categories),
            'items' => $categories->map(fn (McuCategory $category): array => ['id' => $category->id, 'label' => $category->name, 'type' => 'Kategori', 'is_active' => $category->is_active])->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:10000'],
            'items.*' => ['required', 'uuid', 'distinct'],
        ]);
        $version = DB::transaction(function () use ($data): string {
            $categories = McuCategory::orderBy('sort_order')->orderBy('name')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->version($categories), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Daftar kategori berubah di sesi lain. Tutup dan buka kembali pengaturan urutan.']);
            }
            if (count($data['items']) !== $categories->count() || array_diff($data['items'], $categories->modelKeys()) !== []) {
                throw ValidationException::withMessages(['items' => 'Urutan harus memuat seluruh kategori, tanpa duplikasi atau kategori lain.']);
            }
            $byId = $categories->keyBy('id');
            foreach ($data['items'] as $position => $id) {
                $byId[$id]->update(['sort_order' => $position]);
            }

            return $this->version(McuCategory::orderBy('sort_order')->orderBy('name')->orderBy('id')->get());
        });

        return response()->json(['message' => 'Urutan kategori berhasil disimpan. Paket dan hasil pemeriksaan lama tidak berubah.', 'version' => $version]);
    }

    /** @param Collection<int, McuCategory> $categories */
    private function version(Collection $categories): string
    {
        return hash('sha256', $categories->map(fn (McuCategory $category): array => [$category->id, $category->sort_order, $category->getRawOriginal('updated_at'), $category->name, $category->is_active])->toJson());
    }
}
