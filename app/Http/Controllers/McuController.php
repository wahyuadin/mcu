<?php

namespace App\Http\Controllers;

use App\CompanyLogo;
use App\Models\Anamnesa;
use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use OwenIt\Auditing\Models\Audit;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class McuController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->role === 'hrd') {
            return redirect()->route('hrd.index');
        }

        return view('mcu.index', [
            'kunjungans' => RencanaKunjungan::with('perusahaan')->withCount(['pakets', 'karyawans'])->latest()->limit(5)->get(),
            'stats' => [
                'perusahaan' => Perusahaan::count(),
                'kunjungan_aktif' => RencanaKunjungan::where('is_closing', false)->count(),
                'peserta' => Karyawan::count(),
                'selesai' => Karyawan::where('status_pemeriksaan', 'selesai')->count(),
            ],
        ]);
    }

    public function perusahaans(): View
    {
        return view('mcu.perusahaans', [
            'perusahaans' => Perusahaan::withCount(['rencanaKunjungans', 'karyawans'])
                ->orderBy('nama_perusahaan')
                ->paginate(20),
        ]);
    }

    public function kunjungans(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        return view('mcu.kunjungans', [
            'perusahaans' => Perusahaan::orderBy('nama_perusahaan')->get(),
            'kunjungans' => RencanaKunjungan::with('perusahaan')
                ->withCount(['pakets', 'karyawans'])
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('nomor_kunjungan', 'like', '%'.$search.'%')->orWhere('periode_kunjungan', 'like', '%'.$search.'%')
                        ->orWhereHas('perusahaan', fn ($query) => $query->where('nama_perusahaan', 'like', '%'.$search.'%'));
                }))
                ->when($request->filled('perusahaan'), fn ($query) => $query->where('perusahaan_id', $request->query('perusahaan')))
                ->when(in_array($status, ['draft', 'ongoing', 'completed'], true), fn ($query) => $query->where('status', $status)->where('is_closing', false))
                ->when($status === 'closed', fn ($query) => $query->where('is_closing', true))
                ->latest()
                ->paginate(20)->withQueryString(),
            'stats' => [
                'total' => RencanaKunjungan::count(),
                'draft' => RencanaKunjungan::where('status', 'draft')->where('is_closing', false)->count(),
                'ongoing' => RencanaKunjungan::where('status', 'ongoing')->where('is_closing', false)->count(),
                'completed' => RencanaKunjungan::where('status', 'completed')->where('is_closing', false)->count(),
                'closed' => RencanaKunjungan::where('is_closing', true)->count(),
            ],
        ]);
    }

    public function kunjunganMcu(Request $request): View
    {
        $status = $request->query('status');

        return view('mcu.kunjungan-mcu', [
            'kunjungans' => RencanaKunjungan::with('perusahaan')
                ->withCount(['pakets', 'karyawans', 'karyawans as finalized_count' => fn ($query) => $query->where('status_pemeriksaan', 'selesai')])
                ->when(in_array($status, ['draft', 'ongoing', 'completed'], true), fn ($query) => $query->where('status', $status))
                ->latest('tgl_mulai')
                ->paginate(12)
                ->withQueryString(),
            'stats' => [
                'active' => RencanaKunjungan::where('status', 'ongoing')->where('is_closing', false)->count(),
                'participants' => Karyawan::count(),
                'completed' => Karyawan::where('status_pemeriksaan', 'selesai')->count(),
            ],
        ]);
    }

    public function storePerusahaan(Request $request, CompanyLogo $logos): RedirectResponse
    {
        $data = $request->validate([
            'nama_perusahaan' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'telp' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        unset($data['logo']);
        $path = $request->hasFile('logo') ? $logos->store($request->file('logo')) : null;
        $data['logo_path'] = $path;
        try {
            Perusahaan::create($data);
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('success', 'Perusahaan ditambahkan.');
    }

    public function editPerusahaan(Perusahaan $perusahaan): View
    {
        return view('mcu.perusahaan-form', ['perusahaan' => $perusahaan]);
    }

    public function updatePerusahaan(Request $request, Perusahaan $perusahaan): RedirectResponse
    {
        $data = $request->validate([
            'nama_perusahaan' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'telp' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        DB::transaction(function () use ($perusahaan, $data): void {
            Perusahaan::whereKey($perusahaan->id)->lockForUpdate()->firstOrFail()->update($data);
        });

        return redirect()->route('perusahaan.index')->with('success', 'Perusahaan diperbarui.');
    }

    public function destroyPerusahaan(Perusahaan $perusahaan): RedirectResponse
    {
        DB::transaction(function () use ($perusahaan): void {
            $company = Perusahaan::whereKey($perusahaan->id)->lockForUpdate()->firstOrFail();
            if ($company->rencanaKunjungans()->withTrashed()->exists() || $company->karyawans()->withTrashed()->exists() || $company->pakets()->withTrashed()->exists()) {
                throw ValidationException::withMessages(['perusahaan' => 'Perusahaan tidak dapat dihapus karena sudah memiliki kunjungan, peserta, atau paket MCU.']);
            }
            $company->delete();
        });

        return redirect()->route('perusahaan.index')->with('success', 'Perusahaan dihapus.');
    }

    public function updatePerusahaanLogo(Request $request, Perusahaan $perusahaan, CompanyLogo $logos): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $path = $logos->store($request->file('logo'));
        try {
            $oldPath = DB::transaction(function () use ($perusahaan, $path): ?string {
                $company = Perusahaan::whereKey($perusahaan->id)->lockForUpdate()->firstOrFail();
                $oldPath = $company->logo_path;
                $company->update(['logo_path' => $path]);

                return $oldPath;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return back()->with('success', 'Logo perusahaan diperbarui.');
    }

    public function perusahaanLogo(Perusahaan $perusahaan): StreamedResponse
    {
        abort_unless($perusahaan->logo_path && Storage::disk('local')->exists($perusahaan->logo_path), 404);

        return Storage::disk('local')->response($perusahaan->logo_path, null, [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeKunjungan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'perusahaan_id' => ['required', Rule::exists('perusahaans', 'id')->whereNull('deleted_at')],
            'nomor_kunjungan' => ['required', 'string', 'max:255', 'unique:rencana_kunjungans,nomor_kunjungan'],
            'periode_kunjungan' => ['required', 'string', 'max:255'],
            'tgl_mulai' => ['nullable', 'date'],
            'tgl_selesai' => ['nullable', 'date', 'after_or_equal:tgl_mulai'],
            'jumlah_peserta_direncanakan' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $kunjungan = RencanaKunjungan::create($data);

        return redirect()->route('rencana-kunjungan.show', $kunjungan)->with('success', 'Kunjungan dibuat.');
    }

    public function show(Request $request, RencanaKunjungan $kunjungan): View
    {
        $kunjungan->load('perusahaan', 'pakets');
        $kunjungan->pakets->loadCount(['karyawans' => fn ($query) => $query->where('rencana_kunjungan_id', $kunjungan->id)]);
        $counts = $kunjungan->karyawans()->selectRaw('status_pemeriksaan, count(*) as total')->groupBy('status_pemeriksaan')->pluck('total', 'status_pemeriksaan');
        $total = (int) $counts->sum();
        $remaining = $total - (int) ($counts['selesai'] ?? 0) - (int) ($counts['batal'] ?? 0) - (int) ($counts['tidak_hadir'] ?? 0);
        $pesertas = $kunjungan->karyawans()->with('paket', 'anamnesa')
            ->when($request->filled('q'), fn ($query) => $query->where(function ($query) use ($request): void {
                $query->where('nama_karyawan', 'like', '%'.$request->query('q').'%')->orWhere('nomor_mcu', 'like', '%'.$request->query('q').'%')->orWhere('nip', 'like', '%'.$request->query('q').'%');
            }))
            ->when(in_array($request->query('status'), ['belum', 'proses', 'siap_finalisasi', 'selesai', 'batal', 'tidak_hadir'], true), fn ($query) => $query->where('status_pemeriksaan', $request->query('status')))
            ->orderBy('nama_karyawan')->paginate(20)->withQueryString();
        $participantProgress = [];
        $photoEpisodes = Karyawan::whereIn('pasien_id', $pesertas->pluck('pasien_id')->filter()->all())
            ->whereNotNull('foto')->orderByRaw('COALESCE(tanggal_mcu, created_at) DESC')->orderByDesc('id')
            ->get(['id', 'pasien_id', 'rencana_kunjungan_id', 'foto'])
            ->filter(fn (Karyawan $episode): bool => $episode->hasStoredPhoto())->unique('pasien_id')->keyBy('pasien_id');
        $participantPhotos = [];
        foreach ($pesertas as $participant) {
            $participantProgress[$participant->id] = ($participant->anamnesa ?? new Anamnesa)->examinationCompletion($participant);
            $participantPhotos[$participant->id] = $participant->hasStoredPhoto() ? $participant : $photoEpisodes->get($participant->pasien_id);
        }

        return view('mcu.show', [
            'kunjungan' => $kunjungan, 'pesertas' => $pesertas, 'counts' => $counts,
            'participantProgress' => $participantProgress, 'totalParticipants' => $total, 'remainingParticipants' => $remaining,
            'participantPhotos' => $participantPhotos,
            'finalizedPercent' => $total > 0 ? (int) round((int) ($counts['selesai'] ?? 0) / $total * 100) : 0,
            'canComplete' => ! $kunjungan->is_closing && $total > 0 && $remaining === 0,
        ]);
    }

    public function planningShow(RencanaKunjungan $kunjungan): View
    {
        return view('mcu.rencana-detail', [
            'kunjungan' => $kunjungan->load('perusahaan', 'pakets'),
            'availablePackages' => Paket::whereDoesntHave('rencanaKunjungans', fn ($query) => $query->whereKey($kunjungan->id))->orderBy('nama_paket')->get(),
        ]);
    }

    public function updatePlanning(Request $request, RencanaKunjungan $kunjungan): RedirectResponse
    {
        $data = $request->validate(['jumlah_peserta_direncanakan' => ['required', 'integer', 'min:1', 'max:1000000']]);
        DB::transaction(function () use ($data, $kunjungan): void {
            $visit = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            abort_if($visit->is_closing, 403);
            if ($data['jumlah_peserta_direncanakan'] < $visit->karyawans()->count()) {
                throw ValidationException::withMessages(['jumlah_peserta_direncanakan' => 'Target tidak boleh lebih kecil dari peserta terdaftar.']);
            }
            $visit->update($data);
        });

        return back()->with('success', 'Jumlah peserta direncanakan diperbarui. Kuota pendaftaran tidak berubah.');
    }

    public function bindPackage(Request $request, RencanaKunjungan $kunjungan): RedirectResponse
    {
        $data = $request->validate(['paket_id' => ['required', Rule::exists('pakets', 'id')->whereNull('deleted_at')]]);
        DB::transaction(function () use ($data, $kunjungan): void {
            $visit = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            abort_if($visit->is_closing, 403);
            $package = Paket::whereKey($data['paket_id'])->lockForUpdate()->firstOrFail();
            $visit->pakets()->syncWithoutDetaching([$package->id]);
        });

        return back()->with('success', 'Paket master ditambahkan ke rencana kunjungan.');
    }

    public function unbindPackage(RencanaKunjungan $kunjungan, Paket $paket): RedirectResponse
    {
        DB::transaction(function () use ($kunjungan, $paket): void {
            $visit = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            abort_if($visit->is_closing, 403);
            abort_unless($visit->pakets()->whereKey($paket->id)->exists(), 404);
            if ($visit->karyawans()->withTrashed()->where('paket_id', $paket->id)->exists()) {
                throw ValidationException::withMessages(['paket_id' => 'Paket sudah digunakan oleh peserta kunjungan ini dan tidak dapat dilepas.']);
            }
            $visit->pakets()->detach($paket->id);
        });

        return back()->with('success', 'Paket dilepas dari rencana ini. Master paket tetap tersedia.');
    }

    public function updateStatus(Request $request, RencanaKunjungan $kunjungan): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ongoing', 'completed', 'closed'])]]);
        DB::transaction(function () use ($data, $kunjungan, $request): void {
            $visit = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            abort_if($visit->is_closing, 403);
            if ($data['status'] !== 'ongoing' && ($visit->karyawans()->doesntExist() || $visit->karyawans()->whereNotIn('status_pemeriksaan', ['selesai', 'batal', 'tidak_hadir'])->exists())) {
                throw ValidationException::withMessages(['status' => 'Semua peserta harus final, batal, atau tidak hadir sebelum kegiatan diselesaikan/ditutup.']);
            }
            $visit->update(['status' => $data['status'] === 'closed' ? 'completed' : $data['status'], 'is_closing' => $data['status'] === 'closed']);
            if ($data['status'] === 'closed') {
                foreach ($visit->karyawans()->where('status_pemeriksaan', 'selesai')->whereHas('anamnesa', fn ($query) => $query->where('status', 'final'))->where('is_closing', false)->get() as $participant) {
                    $participant->update(['is_closing' => true, 'released_at' => now(), 'released_by' => $request->user()->id]);
                }
            }
        });

        return back()->with('success', 'Status kunjungan diperbarui.');
    }

    public function participantStatus(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta): RedirectResponse
    {
        abort_unless($peserta->rencana_kunjungan_id === $kunjungan->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['batal', 'tidak_hadir', 'belum'])], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        DB::transaction(function () use ($data, $kunjungan, $peserta): void {
            $visit = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            $peserta->refresh();
            abort_if($visit->is_closing || $peserta->is_closing || $peserta->anamnesa?->status === 'final', 403);
            $peserta->update(['status_pemeriksaan' => $data['status'] === 'belum' && $peserta->anamnesa ? 'proses' : $data['status'], 'alasan_status' => $data['reason']]);
        });

        return back()->with('success', 'Status peserta diperbarui. Hasil draft tetap disimpan.');
    }

    public function audits(): View
    {
        return view('mcu.audits', [
            'audits' => Audit::with('user')->latest()->paginate(25),
        ]);
    }

    public function storePeserta(Request $request, RencanaKunjungan $kunjungan): RedirectResponse
    {
        abort_if($kunjungan->is_closing, 403);
        $data = $request->validate([
            'paket_id' => ['required', Rule::exists('pakets', 'id')->whereNull('deleted_at')],
            'nama_karyawan' => ['required', 'string', 'max:255'],
            'nik' => ['nullable', 'digits:16'],
            'nip' => ['nullable', 'string', 'max:50'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'bagian' => ['nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($kunjungan, $data): void {
            $locked = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_closing || ($locked->kuota_peserta > 0 && $locked->karyawans()->count() >= $locked->kuota_peserta)) {
                throw ValidationException::withMessages(['nama_karyawan' => 'Kunjungan ditutup atau kuota peserta sudah penuh.']);
            }
            $package = Paket::whereKey($data['paket_id'])->lockForUpdate()->firstOrFail();
            $locked->pakets()->syncWithoutDetaching([$package->id]);
            $locked->karyawans()->create([...$data, 'perusahaan_id' => $locked->perusahaan_id]);
        });

        return back()->with('success', 'Peserta didaftarkan.');
    }
}
