<?php

namespace App\Http\Controllers;

use App\Models\Dokter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DokterController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('mcu.dokters.index', [
            'dokters' => Dokter::when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('nama_dokter', 'like', '%'.$search.'%')->orWhere('no_str', 'like', '%'.$search.'%')->orWhere('spesialisasi', 'like', '%'.$search.'%');
            }))->when(in_array($request->query('status'), ['aktif', 'nonaktif'], true), fn ($query) => $query->where('is_active', $request->query('status') === 'aktif'))
                ->orderBy('nama_dokter')->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('mcu.dokters.form', ['dokter' => new Dokter(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Dokter::create($this->validated($request));

        return back()->with('success', 'Dokter ditambahkan.');
    }

    public function edit(Dokter $dokter): View
    {
        return view('mcu.dokters.form', ['dokter' => $dokter]);
    }

    public function update(Request $request, Dokter $dokter): RedirectResponse
    {
        $dokter->update($this->validated($request));

        return redirect()->route('dokter.index')->with('success', 'Data dokter diperbarui. Riwayat pemeriksaan tetap tersimpan.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $rules = [
            'nama_dokter' => ['required', 'string', 'max:255'],
            'no_str' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];

        return $request->validate($rules);
    }
}
