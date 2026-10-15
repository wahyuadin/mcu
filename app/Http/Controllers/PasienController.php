<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Pasien;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        $episodeFilter = function (Builder $query) use ($request): void {
            $query->when($request->filled('perusahaan'), fn ($query) => $query->where('perusahaan_id', $request->query('perusahaan')))
                ->when($request->filled('kunjungan'), fn ($query) => $query->where('rencana_kunjungan_id', $request->query('kunjungan')))
                ->when(in_array($request->query('status'), ['belum', 'proses', 'siap_finalisasi', 'selesai', 'batal', 'tidak_hadir'], true), fn ($query) => $query->where('status_pemeriksaan', $request->query('status')));
        };

        return view('mcu.pasiens.index', [
            'pasiens' => Pasien::with(['episodes' => function (HasMany $query) use ($episodeFilter): void {
                $episodeFilter($query->getQuery());
                $query->with('perusahaan', 'paket')->latest()->orderByDesc('id')->limit(1);
            }])->withCount('episodes')->whereHas('episodes', $episodeFilter)
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('nama', 'like', '%'.$search.'%')->orWhere('nik', 'like', '%'.$search.'%')
                        ->orWhereHas('episodes', fn ($query) => $query->where('nip', 'like', '%'.$search.'%')->orWhere('nomor_mcu', 'like', '%'.$search.'%'));
                }))
                ->latest()->paginate(20)->withQueryString(),
            'perusahaans' => Perusahaan::orderBy('nama_perusahaan')->get(),
            'stats' => [
                'total' => Pasien::whereHas('episodes')->count(),
                'process' => Karyawan::whereIn('status_pemeriksaan', ['proses', 'siap_finalisasi'])->count(),
                'completed' => Karyawan::where('status_pemeriksaan', 'selesai')->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $selectedId = $request->old('pasien_id', $request->query('pasien_id'));
        $selectedPatient = $selectedId ? Pasien::findOrFail($selectedId) : null;
        $episode = new Karyawan;
        if ($selectedPatient) {
            $episode->fill([
                'nama_karyawan' => $selectedPatient->nama, 'nik' => $selectedPatient->nik,
                'jenis_kelamin' => $selectedPatient->jenis_kelamin, 'tanggal_lahir' => $selectedPatient->tanggal_lahir,
                'telepon' => $selectedPatient->telepon, 'tempat_lahir' => $selectedPatient->tempat_lahir,
            ]);
        }

        return view('mcu.pasiens.form', [
            'pasien' => $episode,
            'kunjungans' => RencanaKunjungan::with('perusahaan')->where('is_closing', false)->latest()->get(),
            'pakets' => Paket::orderBy('nama_paket')->get(),
            'existingPatients' => Pasien::when($request->filled('q'), fn ($query) => $query->where(function ($query) use ($request): void {
                $query->where('nama', 'like', '%'.$request->query('q').'%')->orWhere('nik', 'like', '%'.$request->query('q').'%');
            }))->orderBy('nama')->limit(30)->get(),
            'selectedPatient' => $selectedPatient,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $pasien = DB::transaction(function () use ($data): Karyawan {
            $kunjungan = RencanaKunjungan::whereKey($data['rencana_kunjungan_id'])->lockForUpdate()->firstOrFail();
            if ($kunjungan->is_closing || ($kunjungan->kuota_peserta > 0 && $kunjungan->karyawans()->count() >= $kunjungan->kuota_peserta)) {
                throw ValidationException::withMessages(['rencana_kunjungan_id' => 'Kunjungan ditutup atau kuota peserta sudah penuh.']);
            }
            if (isset($data['pasien_id']) && $kunjungan->karyawans()->where('pasien_id', $data['pasien_id'])->exists()) {
                throw ValidationException::withMessages(['pasien_id' => 'Pasien sudah terdaftar dalam kegiatan ini. Buka episode yang ada.']);
            }
            $package = Paket::whereKey($data['paket_id'])->lockForUpdate()->firstOrFail();
            $kunjungan->pakets()->syncWithoutDetaching([$package->id]);

            return $kunjungan->karyawans()->create([...$data, 'perusahaan_id' => $kunjungan->perusahaan_id]);
        });

        return redirect()->route('pasien.show', $pasien)->with('success', 'Pasien didaftarkan.');
    }

    public function show(Karyawan $pasien): View
    {
        $pasien->load(['pasien', 'perusahaan', 'rencanaKunjungan', 'paket', 'anamnesa']);
        $episodes = Karyawan::with('perusahaan', 'paket', 'rencanaKunjungan', 'anamnesa')
            ->where('pasien_id', $pasien->pasien_id)->orderByRaw('COALESCE(tanggal_mcu, created_at) DESC')->orderByDesc('id')->get();
        $currentEmployment = $episodes->first() ?? $pasien;

        return view('mcu.pasiens.show', [
            'pasien' => $pasien, 'identity' => $pasien->pasien, 'episodes' => $episodes,
            'currentEmployment' => $currentEmployment,
            'patientAge' => ($pasien->pasien?->tanggal_lahir ?? $pasien->tanggal_lahir)?->age,
        ]);
    }

    public function edit(Karyawan $pasien): View
    {
        abort_if($pasien->rencanaKunjungan->is_closing || $pasien->is_closing || $pasien->anamnesa?->status === 'final', 403);

        return view('mcu.pasiens.form', [
            'pasien' => $pasien,
            'kunjungans' => RencanaKunjungan::with('perusahaan')->latest()->get(),
            'pakets' => Paket::orderBy('nama_paket')->get(),
        ]);
    }

    public function update(Request $request, Karyawan $pasien): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $pasien): void {
            $originalVisit = RencanaKunjungan::whereKey($pasien->rencana_kunjungan_id)->lockForUpdate()->firstOrFail();
            $pasien->refresh();
            abort_if($originalVisit->is_closing || $pasien->is_closing || $pasien->anamnesa?->status === 'final', 403);
            $kunjungan = RencanaKunjungan::whereKey($data['rencana_kunjungan_id'])->lockForUpdate()->firstOrFail();
            if ($pasien->rencana_kunjungan_id !== $kunjungan->id && ($kunjungan->is_closing || ($kunjungan->kuota_peserta > 0 && $kunjungan->karyawans()->count() >= $kunjungan->kuota_peserta))) {
                throw ValidationException::withMessages(['rencana_kunjungan_id' => 'Kunjungan ditutup atau kuota peserta sudah penuh.']);
            }
            if ($pasien->rencana_kunjungan_id !== $kunjungan->id && $pasien->anamnesa()->exists()) {
                throw ValidationException::withMessages(['rencana_kunjungan_id' => 'Pasien dengan pemeriksaan tidak dapat dipindah kunjungan.']);
            }
            if ($pasien->anamnesa()->exists() && $pasien->paket_id !== $data['paket_id']) {
                throw ValidationException::withMessages(['paket_id' => 'Paket tidak dapat diganti setelah pengisian pemeriksaan dimulai.']);
            }
            $package = Paket::whereKey($data['paket_id'])->lockForUpdate()->firstOrFail();
            $kunjungan->pakets()->syncWithoutDetaching([$package->id]);
            unset($data['pasien_id']);
            $pasien->update([...$data, 'perusahaan_id' => $kunjungan->perusahaan_id]);
            if ($pasien->anamnesa) {
                $pasien->anamnesa->update(['lock_version' => $pasien->anamnesa->lock_version + 1]);
                $pasien->update(['status_pemeriksaan' => $pasien->anamnesa->finalizationErrors($pasien) === [] ? 'siap_finalisasi' : 'proses']);
            }
            $pasien->pasien?->update([
                'nama' => $pasien->nama_karyawan, 'nik' => $pasien->nik, 'jenis_kelamin' => $pasien->jenis_kelamin,
                'tanggal_lahir' => $pasien->tanggal_lahir, 'telepon' => $pasien->telepon, 'tempat_lahir' => $pasien->tempat_lahir,
            ]);
        });

        return redirect()->route('pasien.show', $pasien)->with('success', 'Pasien diperbarui.');
    }

    public function destroy(Karyawan $pasien): RedirectResponse
    {
        abort_if($pasien->rencanaKunjungan->is_closing || $pasien->is_closing, 403);
        if ($pasien->anamnesa()->exists()) {
            return back()->withErrors(['pasien' => 'Pasien dengan hasil pemeriksaan tidak dapat dihapus.']);
        }
        $pasien->delete();

        return redirect()->route('pasien.index')->with('success', 'Pasien dihapus.');
    }

    private function validated(Request $request): array
    {
        $usingExisting = $request->filled('pasien_id') && $request->routeIs('pasien.store');
        $data = $request->validate([
            'pasien_id' => ['nullable', Rule::exists('pasiens', 'id')->whereNull('deleted_at')],
            'rencana_kunjungan_id' => ['required', Rule::exists('rencana_kunjungans', 'id')->whereNull('deleted_at')],
            'paket_id' => ['required', Rule::exists('pakets', 'id')->whereNull('deleted_at')],
            'nama_karyawan' => [Rule::requiredIf(! $usingExisting), 'nullable', 'string', 'max:255'],
            'nik' => ['nullable', 'digits:16'],
            'nip' => ['nullable', 'string', 'max:50'],
            'jenis_kelamin' => [Rule::requiredIf(! $usingExisting), 'nullable', Rule::in(['L', 'P'])],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'tempat_lahir' => ['nullable', 'string', 'max:255'],
            'bagian' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:255'],
            'tanggal_mcu' => ['nullable', 'date'],
        ]);
        if ($usingExisting) {
            $identity = Pasien::findOrFail($data['pasien_id']);
            $data = array_replace($data, [
                'nama_karyawan' => $identity->nama, 'nik' => $identity->nik, 'jenis_kelamin' => $identity->jenis_kelamin,
                'tanggal_lahir' => $identity->tanggal_lahir, 'telepon' => $identity->telepon, 'foto' => $identity->foto, 'tempat_lahir' => $identity->tempat_lahir,
            ]);
        } elseif ($request->routeIs('pasien.store')) {
            unset($data['pasien_id']);
        }

        return $data;
    }
}
