<?php

namespace App\Http\Controllers;

use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\RencanaKunjungan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaketController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('mcu.pakets.index', [
            'pakets' => Paket::with('rencanaKunjungans.perusahaan')->withCount('karyawans')
                ->when($search !== '', fn ($query) => $query->where('nama_paket', 'like', '%'.$search.'%'))
                ->latest()->paginate(20)->withQueryString(),
            'search' => $search,
            'stats' => [
                'total' => Paket::count(),
                'items' => Paket::get()->sum(fn (Paket $paket): int => $paket->itemCount()),
                'participants' => Paket::withCount('karyawans')->get()->sum('karyawans_count'),
            ],
        ]);
    }

    public function create(): View
    {
        return view('mcu.pakets.form', [
            'paket' => new Paket,
            'catalog' => Paket::getDefaultItemCatalog(),
            'categories' => $this->categories(),
            'templatePackages' => Paket::orderBy('nama_paket')->get(['id', 'nama_paket']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $paket = DB::transaction(function () use ($data): Paket {
            $kunjungan = ! empty($data['rencana_kunjungan_id'])
                ? RencanaKunjungan::whereKey($data['rencana_kunjungan_id'])->lockForUpdate()->firstOrFail() : null;
            abort_if($kunjungan?->is_closing, 403);
            $paket = Paket::create([
                'nama_paket' => $data['nama_paket'],
                'deskripsi' => $data['deskripsi'] ?? null,
                'active_items' => array_fill_keys($data['items'], true),
                'dynamic_schema' => $data['dynamic_schema'],
            ]);
            if ($kunjungan) {
                $kunjungan->pakets()->syncWithoutDetaching([$paket->id]);
            }

            return $paket;
        });

        return redirect()->route('paket.show', $paket)->with('success', 'Paket dibuat.');
    }

    public function template(Paket $paket): JsonResponse
    {
        $available = $this->categories()->pluck('items')->flatten(1);
        $snapshotItems = collect($paket->dynamic_schema ?? [])->pluck('items')->flatten(1);
        $legacyKeys = array_keys(array_filter($paket->active_items ?? []));
        $selected = $available->filter(fn (McuItem $item): bool => $snapshotItems->contains('id', $item->id) || ($item->legacy_key && in_array($item->legacy_key, $legacyKeys, true)));
        $unavailable = $snapshotItems->reject(fn (array $item): bool => $available->contains('id', $item['id']))->pluck('label');
        foreach (array_diff($legacyKeys, ['bmi'], $selected->pluck('legacy_key')->filter()->all()) as $key) {
            $unavailable->push(Arr::collapse(array_values(Paket::getDefaultItemCatalog()))[$key] ?? $key);
        }

        return response()->json([
            'nama_paket' => mb_substr('Salinan '.$paket->nama_paket, 0, 255),
            'deskripsi' => $paket->deskripsi,
            'master_items' => $selected->pluck('id')->values(),
            'items' => in_array('bmi', $legacyKeys, true) ? ['bmi'] : [],
            'unavailable' => $unavailable->unique()->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Paket $paket): View
    {
        return view('mcu.pakets.show', [
            'paket' => $paket->load('rencanaKunjungans.perusahaan'),
            'pesertas' => $paket->karyawans()->orderBy('nama_karyawan')->paginate(20),
            'catalog' => Paket::getDefaultItemCatalog(),
        ]);
    }

    public function edit(Paket $paket): View
    {
        return view('mcu.pakets.form', [
            'paket' => $paket,
            'catalog' => Paket::getDefaultItemCatalog(),
            'categories' => $this->categories(),
        ]);
    }

    public function update(Request $request, Paket $paket): RedirectResponse
    {
        $data = $this->validated($request);
        if ($paket->karyawans()->whereHas('anamnesa')->exists()) {
            return back()->withErrors(['paket' => 'Paket sudah digunakan dalam pemeriksaan. Buat paket baru agar cakupan hasil lama tidak berubah.']);
        }
        $paket->update([
            'nama_paket' => $data['nama_paket'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'active_items' => array_fill_keys($data['items'], true),
            'dynamic_schema' => $data['dynamic_schema'],
        ]);

        return redirect()->route('paket.show', $paket)->with('success', 'Paket diperbarui.');
    }

    public function destroy(Paket $paket): RedirectResponse
    {
        if ($paket->karyawans()->exists()) {
            return back()->withErrors(['paket' => 'Paket dengan peserta tidak dapat dihapus.']);
        }

        $paket->delete();

        return redirect()->route('paket.index')->with('success', 'Paket dihapus.');
    }

    private function validated(Request $request): array
    {
        $catalog = Arr::collapse(array_values(Paket::getDefaultItemCatalog()));
        if ($request->routeIs('paket.store')) {
            $catalog = Arr::collapse(array_values(Arr::only(Paket::getDefaultItemCatalog(), ['anamnesa_riwayat', 'antropometri_vital'])));
        }

        $data = $request->validate([
            'rencana_kunjungan_id' => ['nullable', Rule::exists('rencana_kunjungans', 'id')->whereNull('deleted_at')],
            'nama_paket' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*' => ['required', 'string', Rule::in(array_keys($catalog))],
            'master_items' => ['sometimes', 'array'],
            'master_items.*' => ['required', 'uuid', 'distinct', Rule::exists('mcu_items', 'id')->where('is_active', true)->whereNot('input_type', 'label')],
        ]);
        $data['items'] ??= [];
        $legacyMasters = McuItem::with('category', 'subcategory')->whereIn('legacy_key', $data['items'])->get();
        foreach ($legacyMasters as $legacyMaster) {
            if ($legacyMaster->category->isProtected() && (! $legacyMaster->category->is_active || ! $legacyMaster->is_active || ($legacyMaster->subcategory && ! $legacyMaster->subcategory->is_active)) && ! ($request->route('paket') instanceof Paket && $request->route('paket')->isItemActive($legacyMaster->legacy_key))) {
                throw ValidationException::withMessages(['items' => 'Item Anamnesis sudah nonaktif. Pilih item aktif dari master.']);
            }
        }
        $data['dynamic_schema'] = [];
        $selected = McuItem::with('category', 'subcategory')->whereIn('id', $data['master_items'] ?? [])->orderBy('sort_order')->orderBy('label')->get();
        foreach ($selected as $item) {
            if (! $item->category->is_active || ($item->subcategory && (! $item->subcategory->is_active || $item->subcategory->mcu_category_id !== $item->mcu_category_id))) {
                throw ValidationException::withMessages(['master_items' => 'Kategori item sudah nonaktif. Muat ulang daftar paket.']);
            }
            $categoryId = $item->mcu_category_id;
            $data['dynamic_schema'][$categoryId] ??= ['id' => $categoryId, 'name' => $item->category->name, 'order' => $item->category->sort_order, 'has_responsible_doctor' => $item->category->has_responsible_doctor, 'items' => []];
            if ($item->category->isAnamnesis()) {
                $data['dynamic_schema'][$categoryId]['section'] = 'anamnesa';
            }
            if ($item->category->isPhysical()) {
                $data['dynamic_schema'][$categoryId]['section'] = 'fisik';
            }
            $data['dynamic_schema'][$categoryId]['items'][] = $item->definition();
        }
        $data['items'] = array_values(array_diff($data['items'], $selected->filter(fn (McuItem $item): bool => $item->category->isProtected())->pluck('legacy_key')->filter()->all()));
        $data['dynamic_schema'] = array_values(collect($data['dynamic_schema'])->sortBy('order')->all());
        if ($data['items'] === [] && $data['dynamic_schema'] === []) {
            throw ValidationException::withMessages(['items' => 'Pilih minimal satu item pemeriksaan.']);
        }
        if (in_array('bmi', $data['items'], true)) {
            $dependencies = array_diff(['tinggi_badan', 'berat_badan'], $selected->filter(fn (McuItem $item): bool => $item->category->isPhysical())->pluck('legacy_key')->all());
            foreach (McuItem::with('category', 'subcategory')->whereHas('category', fn ($query) => $query->where('legacy_group', 'pemeriksaan_fisik'))->whereIn('legacy_key', $dependencies)->get() as $dependency) {
                $retained = $request->route('paket') instanceof Paket && $request->route('paket')->isItemActive($dependency->legacy_key);
                if (! $retained && (! $dependency->category->is_active || ! $dependency->is_active || ($dependency->subcategory && ! $dependency->subcategory->is_active))) {
                    throw ValidationException::withMessages(['items' => 'BMI memerlukan item tinggi dan berat badan yang aktif.']);
                }
            }
            $data['items'] = array_values(array_unique([...$data['items'], ...$dependencies]));
        }

        return $data;
    }

    private function categories(): Collection
    {
        return McuCategory::where('is_active', true)->with(['items' => fn ($query) => $query->with('subcategory')->where('is_active', true)->whereNot('input_type', 'label')->where(fn ($query) => $query->whereNull('mcu_subcategory_id')->orWhereHas('subcategory', fn ($query) => $query->where('is_active', true)))])->orderBy('sort_order')->orderBy('name')->get();
    }
}
