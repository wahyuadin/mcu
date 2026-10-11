<?php

namespace App\Http\Controllers;

use App\Models\McuCategory;
use App\Models\McuItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class McuItemOrderController extends Controller
{
    public function show(McuCategory $category): JsonResponse
    {
        $items = $category->items()->get();

        return response()->json([
            'category' => $category->name,
            'version' => $this->version($items),
            'items' => $items->map(fn (McuItem $item): array => ['id' => $item->id, 'label' => $item->label, 'type' => $item->inputLabel(), 'is_active' => $item->is_active])->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, McuCategory $category): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:10000'],
            'items.*' => ['required', 'uuid', 'distinct'],
        ]);
        $version = DB::transaction(function () use ($category, $data): string {
            McuCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
            $items = $category->items()->lockForUpdate()->get();
            if (! hash_equals($this->version($items), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Daftar item berubah di sesi lain. Tutup dan buka kembali pengaturan urutan.']);
            }
            if (count($data['items']) !== $items->count() || array_diff($data['items'], $items->modelKeys()) !== []) {
                throw ValidationException::withMessages(['items' => 'Urutan harus memuat seluruh item kategori ini, tanpa item dari kategori lain.']);
            }
            $byId = $items->keyBy('id');
            foreach ($data['items'] as $position => $id) {
                $byId[$id]->update(['sort_order' => $position]);
            }

            return $this->version($category->items()->get());
        });

        return response()->json(['message' => 'Urutan item berhasil disimpan. Paket dan hasil pemeriksaan lama tidak berubah.', 'version' => $version]);
    }

    /** @param Collection<int, McuItem> $items */
    private function version(Collection $items): string
    {
        return hash('sha256', $items->map(fn (McuItem $item): array => [$item->id, $item->sort_order, $item->getRawOriginal('updated_at'), $item->label, $item->input_type, $item->is_active])->toJson());
    }
}
