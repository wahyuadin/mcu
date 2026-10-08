<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasienController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('mcu.pasiens.index', [
            'pasiens' => Karyawan::with(['perusahaan', 'rencanaKunjungan', 'paket'])
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('nama_karyawan', 'like', '%'.$search.'%')->orWhere('nik', 'like', '%'.$search.'%')->orWhere('nip', 'like', '%'.$search.'%');
                }))
                ->when($request->filled('perusahaan'), fn ($query) => $query->where('perusahaan_id', $request->query('perusahaan')))
                ->when($request->filled('kunjungan'), fn ($query) => $query->where('rencana_kunjungan_id', $request->query('kunjungan')))
                ->when(in_array($request->query('status'), ['belum', 'proses', 'selesai'], true), fn ($query) => $query->where('status_pemeriksaan', $request->query('status')))
                ->latest()->paginate(20)->withQueryString(),
            'perusahaans' => Perusahaan::orderBy('nama_perusahaan')->get(),
            'stats' => [
                'total' => Karyawan::count(),
                'process' => Karyawan::where('status_pemeriksaan', 'proses')->count(),
                'completed' => Karyawan::where('status_pemeriksaan', 'selesai')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('mcu.pasiens.form', [
            'pasien' => new Karyawan,
            'kunjungans' => RencanaKunjungan::with('perusahaan')->where('is_closing', false)->latest()->get(),
            'pakets' => Paket::with('rencanaKunjungan')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $pasien = DB::transaction(function () use ($data): Karyawan {
            $kunjungan = RencanaKunjungan::whereKey($data['rencana_kunjungan_id'])->lockForUpdate()->firstOrFail();
            if ($kunjungan->is_closing || $kunjungan->karyawans()->count() >= $kunjungan->kuota_peserta) {
                throw ValidationException::withMessages(['rencana_kunjungan_id' => 'Kunjungan ditutup atau kuota peserta sudah penuh.']);
            }

            return $kunjungan->karyawans()->create([...$data, 'perusahaan_id' => $kunjungan->perusahaan_id]);
        });

        return redirect()->route('pasien.show', $pasien)->with('success', 'Pasien didaftarkan.');
    }

    public function show(Karyawan $pasien): View
    {
        return view('mcu.pasiens.show', ['pasien' => $pasien->load(['perusahaan', 'rencanaKunjungan', 'paket'])]);
    }

    public function edit(Karyawan $pasien): View
    {
        return view('mcu.pasiens.form', [
            'pasien' => $pasien,
            'kunjungans' => RencanaKunjungan::with('perusahaan')->latest()->get(),
            'pakets' => Paket::with('rencanaKunjungan')->get(),
        ]);
    }

    public function update(Request $request, Karyawan $pasien): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $pasien): void {
            $kunjungan = RencanaKunjungan::whereKey($data['rencana_kunjungan_id'])->lockForUpdate()->firstOrFail();
            if ($pasien->rencana_kunjungan_id !== $kunjungan->id && ($kunjungan->is_closing || $kunjungan->karyawans()->count() >= $kunjungan->kuota_peserta)) {
                throw ValidationException::withMessages(['rencana_kunjungan_id' => 'Kunjungan ditutup atau kuota peserta sudah penuh.']);
            }
            if ($pasien->rencana_kunjungan_id !== $kunjungan->id && $pasien->anamnesa()->exists()) {
                throw ValidationException::withMessages(['rencana_kunjungan_id' => 'Pasien dengan pemeriksaan tidak dapat dipindah kunjungan.']);
            }
            $pasien->update([...$data, 'perusahaan_id' => $kunjungan->perusahaan_id]);
        });

        return redirect()->route('pasien.show', $pasien)->with('success', 'Pasien diperbarui.');
    }

    public function destroy(Karyawan $pasien): RedirectResponse
    {
        if ($pasien->anamnesa()->exists()) {
            return back()->withErrors(['pasien' => 'Pasien dengan hasil pemeriksaan tidak dapat dihapus.']);
        }
        $pasien->delete();

        return redirect()->route('pasien.index')->with('success', 'Pasien dihapus.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'rencana_kunjungan_id' => ['required', Rule::exists('rencana_kunjungans', 'id')->whereNull('deleted_at')],
            'paket_id' => ['required', Rule::exists('pakets', 'id')->where('rencana_kunjungan_id', $request->input('rencana_kunjungan_id'))->whereNull('deleted_at')],
            'nama_karyawan' => ['required', 'string', 'max:255'],
            'nik' => ['nullable', 'digits:16'],
            'nip' => ['nullable', 'string', 'max:50'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'bagian' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
