<?php

namespace App\Http\Controllers;

use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class MasterBulkDeleteController extends Controller
{
    public function packages(Request $request): RedirectResponse
    {
        return $this->deleteSelected($request, Paket::class, 'paket.index', ['q', 'page']);
    }

    public function categories(Request $request): RedirectResponse
    {
        return $this->deleteSelected($request, McuCategory::class, 'mcu-category.index', ['q', 'page']);
    }

    public function items(Request $request): RedirectResponse
    {
        return $this->deleteSelected($request, McuItem::class, 'mcu-item.index', ['category', 'page']);
    }

    /**
     * @param  class-string<Paket|McuCategory|McuItem>  $modelClass
     * @param  list<string>  $filters
     */
    private function deleteSelected(Request $request, string $modelClass, string $indexRoute, array $filters): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'ids.*' => ['required', 'uuid', 'distinct'],
            'q' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $results = [];
        foreach ($data['ids'] as $id) {
            try {
                $results[] = DB::transaction(function () use ($modelClass, $id): array {
                    $record = $modelClass::whereKey($id)->lockForUpdate()->first();
                    if (! $record) {
                        return ['name' => $id, 'deleted' => false, 'reason' => 'Data sudah dihapus atau tidak ditemukan.'];
                    }
                    $name = match (true) {
                        $record instanceof Paket => $record->nama_paket,
                        $record instanceof McuCategory => $record->name,
                        default => $record->label,
                    };
                    if ($record instanceof Paket && $record->karyawans()->exists()) {
                        return ['name' => $name, 'deleted' => false, 'reason' => 'Paket dengan peserta tidak dapat dihapus.'];
                    }
                    if ($record instanceof McuCategory && $record->subcategories()->exists()) {
                        return ['name' => $name, 'deleted' => false, 'reason' => 'Kategori masih memiliki subkategori. Hapus atau pindahkan subkategori terlebih dahulu.'];
                    }
                    if ($record instanceof McuCategory && $record->items()->exists()) {
                        return ['name' => $name, 'deleted' => false, 'reason' => 'Kategori masih memiliki item. Hapus atau pindahkan item terlebih dahulu.'];
                    }
                    if (! $record->delete()) {
                        return ['name' => $name, 'deleted' => false, 'reason' => 'Penghapusan ditolak. Data tidak diubah.'];
                    }

                    return ['name' => $name, 'deleted' => true, 'reason' => 'Berhasil dihapus.'];
                });
            } catch (Throwable $exception) {
                report($exception);
                $results[] = ['name' => $id, 'deleted' => false, 'reason' => 'Penghapusan gagal. Data ini tidak diubah; silakan coba kembali.'];
            }
        }
        $deleted = count(array_filter($results, fn (array $result): bool => $result['deleted']));
        $query = array_filter(array_intersect_key($data, array_flip($filters)), fn ($value): bool => $value !== null && $value !== '');

        return redirect()->route($indexRoute, $query)
            ->with('success', $deleted.' data dihapus, '.(count($results) - $deleted).' dilewati. Hasil pemeriksaan tersimpan tidak berubah.')
            ->with('bulkDeleteResults', $results);
    }
}
