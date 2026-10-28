<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\RencanaKunjungan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParticipantReleaseController extends Controller
{
    public function store(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta): RedirectResponse
    {
        abort_unless($peserta->rencana_kunjungan_id === $kunjungan->id, 404);
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'lock_version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($request, $kunjungan, $peserta, $data): void {
            $visit = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            $participant = Karyawan::whereKey($peserta->id)->lockForUpdate()->firstOrFail();
            abort_unless($participant->rencana_kunjungan_id === $visit->id && $participant->perusahaan_id === $visit->perusahaan_id, 404);
            if ($participant->status_pemeriksaan !== 'selesai' || $participant->anamnesa?->status !== 'final') {
                throw ValidationException::withMessages(['release' => 'Hasil harus final sebelum dirilis ke HRD.']);
            }
            if ((int) $data['lock_version'] !== $participant->anamnesa->lock_version) {
                throw ValidationException::withMessages(['release' => 'Versi hasil berubah. Tinjau ulang hasil sebelum dirilis ke HRD.']);
            }
            if (! $participant->is_closing) {
                $participant->update(['is_closing' => true, 'released_at' => now(), 'released_by' => $request->user()->id]);
            }
        });

        return back()->with('success', 'Hasil peserta ditutup dan dirilis ke HRD.');
    }
}
