<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use OwenIt\Auditing\Models\Audit;

class McuController extends Controller
{
    public function index(): View
    {
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

    public function kunjungans(): View
    {
        return view('mcu.kunjungans', [
            'perusahaans' => Perusahaan::orderBy('nama_perusahaan')->get(),
            'kunjungans' => RencanaKunjungan::with('perusahaan')
                ->withCount(['pakets', 'karyawans'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function kunjunganMcu(Request $request): View
    {
        $status = $request->query('status');

        return view('mcu.kunjungan-mcu', [
            'kunjungans' => RencanaKunjungan::with('perusahaan')
                ->withCount(['pakets', 'karyawans', 'anamnesas'])
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

    public function storePerusahaan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nama_perusahaan' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'telp' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        Perusahaan::create($data);

        return back()->with('success', 'Perusahaan ditambahkan.');
    }

    public function storeKunjungan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'perusahaan_id' => ['required', Rule::exists('perusahaans', 'id')->whereNull('deleted_at')],
            'nomor_kunjungan' => ['required', 'string', 'max:255', 'unique:rencana_kunjungans,nomor_kunjungan'],
            'periode_kunjungan' => ['required', 'string', 'max:255'],
            'tgl_mulai' => ['nullable', 'date'],
            'tgl_selesai' => ['nullable', 'date', 'after_or_equal:tgl_mulai'],
            'kuota_peserta' => ['required', 'integer', 'min:1'],
        ]);
        $kunjungan = RencanaKunjungan::create($data);

        return redirect()->route('rencana-kunjungan.show', $kunjungan)->with('success', 'Kunjungan dibuat.');
    }

    public function show(RencanaKunjungan $kunjungan): View
    {
        return view('mcu.show', [
            'kunjungan' => $kunjungan->load('perusahaan', 'pakets'),
            'pesertas' => $kunjungan->karyawans()->with('paket')->orderBy('nama_karyawan')->paginate(20),
        ]);
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
            'paket_id' => ['required', Rule::exists('pakets', 'id')->where('rencana_kunjungan_id', $kunjungan->id)->whereNull('deleted_at')],
            'nama_karyawan' => ['required', 'string', 'max:255'],
            'nik' => ['nullable', 'digits:16'],
            'nip' => ['nullable', 'string', 'max:50'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'bagian' => ['nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($kunjungan, $data): void {
            $locked = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_closing || $locked->karyawans()->count() >= $locked->kuota_peserta) {
                throw ValidationException::withMessages(['nama_karyawan' => 'Kunjungan ditutup atau kuota peserta sudah penuh.']);
            }
            $locked->karyawans()->create([...$data, 'perusahaan_id' => $locked->perusahaan_id]);
        });

        return back()->with('success', 'Peserta didaftarkan.');
    }
}
