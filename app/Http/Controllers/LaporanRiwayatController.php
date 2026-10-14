<?php

namespace App\Http\Controllers;

use App\McuReport;
use App\Models\Anamnesa;
use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class LaporanRiwayatController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $statusFilter = $request->query('status', 'selesai');

        $participants = Karyawan::with(['rencanaKunjungan', 'perusahaan', 'paket', 'anamnesa.dokter', 'anamnesa.dokterFisik'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('nama_karyawan', 'like', "%{$search}%")
                        ->orWhere('nomor_mcu', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%");
                });
            })
            ->when($statusFilter === 'selesai', function ($query): void {
                $query->where('status_pemeriksaan', 'selesai');
            })
            ->when($statusFilter === 'semua', function ($query): void {
                $query->whereIn('status_pemeriksaan', ['proses', 'siap_finalisasi', 'selesai']);
            })
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('mcu.laporan-riwayat-index', [
            'participants' => $participants,
            'search' => $search,
            'statusFilter' => $statusFilter,
        ]);
    }

    public function show(RencanaKunjungan $kunjungan, Karyawan $peserta): View
    {
        abort_unless($peserta->rencana_kunjungan_id === $kunjungan->id, 404);
        $peserta->load(['rencanaKunjungan', 'perusahaan', 'paket', 'anamnesa.dokter', 'anamnesa.dokterFisik']);

        $exam = $peserta->anamnesa ?? new Anamnesa;
        $revisions = $exam->revisions ?? [];
        $currentVersionNumber = count($revisions) + ($exam->status === 'final' ? 1 : 0);
        if ($currentVersionNumber === 0) {
            $currentVersionNumber = 1;
        }

        return view('mcu.laporan-riwayat-show', [
            'kunjungan' => $kunjungan,
            'peserta' => $peserta,
            'exam' => $exam,
            'revisions' => $revisions,
            'currentVersionNumber' => $currentVersionNumber,
        ]);
    }

    public function showVersion(RencanaKunjungan $kunjungan, Karyawan $peserta, int $version, McuReport $report): View
    {
        abort_unless($peserta->rencana_kunjungan_id === $kunjungan->id, 404);
        $exam = $peserta->anamnesa;
        abort_unless($exam, 404);

        $revisions = $exam->revisions ?? [];
        // version 1 corresponds to $revisions[0] if version < activeVersion
        $index = $version - 1;
        abort_unless(isset($revisions[$index]), 404);

        $revisionData = $revisions[$index];
        $snapshot = $revisionData['snapshot'] ?? [];

        // Build mock/snapshot Anamnesa instance from historical array
        $historicalExam = new Anamnesa;
        $historicalExam->fill($snapshot);
        $historicalExam->dynamic_schema = $snapshot['dynamic_schema'] ?? [];
        $historicalExam->package_items = $snapshot['package_items'] ?? [];
        $historicalExam->status = 'final';
        $historicalExam->finalized_at = isset($revisionData['at']) ? Carbon::parse($revisionData['at']) : null;

        $peserta->load('perusahaan', 'paket');
        $episode = $revisionData['episode'] ?? [];
        $peserta->fill(Arr::only($episode, ['nomor_mcu', 'tanggal_mcu', 'nik', 'nip', 'nama_karyawan', 'tanggal_lahir', 'jenis_kelamin', 'bagian', 'foto']));
        if (isset($episode['perusahaan']['nama_perusahaan'])) {
            $peserta->setRelation('perusahaan', new Perusahaan(['nama_perusahaan' => $episode['perusahaan']['nama_perusahaan']]));
        }
        if (isset($episode['paket']['nama_paket'])) {
            $peserta->setRelation('paket', new Paket(['nama_paket' => $episode['paket']['nama_paket']]));
        }

        return view('mcu.laporan', [
            'peserta' => $peserta,
            'exam' => $historicalExam,
            'sections' => $report->sections($peserta, $historicalExam),
            'doctors' => array_replace(Dokter::withTrashed()->pluck('nama_dokter', 'id')->all(), $revisionData['doctors'] ?? []),
            'historicalVersion' => $version,
            'revisionNote' => $revisionData['reason'] ?? null,
        ]);
    }
}
