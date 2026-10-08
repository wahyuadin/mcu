<?php

namespace App\Http\Controllers;

use App\Models\Paket;
use App\Models\RencanaKunjungan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaketController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('mcu.pakets.index', [
            'pakets' => Paket::with(['perusahaan', 'rencanaKunjungan'])->withCount('karyawans')
                ->when($search !== '', fn ($query) => $query->where('nama_paket', 'like', '%'.$search.'%'))
                ->latest()->paginate(20)->withQueryString(),
            'search' => $search,
            'stats' => [
                'total' => Paket::count(),
                'items' => Paket::get()->sum(fn (Paket $paket): int => count(array_filter($paket->active_items ?? []))),
                'participants' => Paket::withCount('karyawans')->get()->sum('karyawans_count'),
            ],
        ]);
    }

    public function create(): View
    {
        return view('mcu.pakets.form', [
            'paket' => new Paket,
            'kunjungans' => RencanaKunjungan::with('perusahaan')->where('is_closing', false)->latest()->get(),
            'catalog' => Paket::getDefaultItemCatalog(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $kunjungan = RencanaKunjungan::where('is_closing', false)->findOrFail($data['rencana_kunjungan_id']);
        $paket = $kunjungan->pakets()->create([
            'perusahaan_id' => $kunjungan->perusahaan_id,
            'nama_paket' => $data['nama_paket'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'active_items' => array_fill_keys($data['items'], true),
        ]);

        return redirect()->route('paket.show', $paket)->with('success', 'Paket dibuat.');
    }

    public function show(Paket $paket): View
    {
        return view('mcu.pakets.show', [
            'paket' => $paket->load(['perusahaan', 'rencanaKunjungan']),
            'pesertas' => $paket->karyawans()->orderBy('nama_karyawan')->paginate(20),
            'catalog' => Paket::getDefaultItemCatalog(),
        ]);
    }

    public function edit(Paket $paket): View
    {
        return view('mcu.pakets.form', [
            'paket' => $paket,
            'kunjungans' => RencanaKunjungan::with('perusahaan')->where('is_closing', false)->latest()->get(),
            'catalog' => Paket::getDefaultItemCatalog(),
        ]);
    }

    public function update(Request $request, Paket $paket): RedirectResponse
    {
        $data = $this->validated($request);
        $kunjungan = RencanaKunjungan::findOrFail($data['rencana_kunjungan_id']);
        abort_if($kunjungan->is_closing || ($paket->rencana_kunjungan_id !== $kunjungan->id && $paket->karyawans()->exists()), 403);
        $paket->update([
            'perusahaan_id' => $kunjungan->perusahaan_id,
            'rencana_kunjungan_id' => $kunjungan->id,
            'nama_paket' => $data['nama_paket'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'active_items' => array_fill_keys($data['items'], true),
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

        return $request->validate([
            'rencana_kunjungan_id' => ['required', Rule::exists('rencana_kunjungans', 'id')->whereNull('deleted_at')],
            'nama_paket' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'string', Rule::in(array_keys($catalog))],
        ]);
    }
}
