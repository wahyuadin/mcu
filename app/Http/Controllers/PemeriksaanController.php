<?php

namespace App\Http\Controllers;

use App\Models\Anamnesa;
use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\RencanaKunjungan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PemeriksaanController extends Controller
{
    private const HISTORY = ['hipertensi', 'diabetes_melitus', 'jantung', 'asma', 'tbc', 'ginjal', 'operasi', 'kebiasaan_merokok', 'kebiasaan_alkohol'];

    private const GROUPED = [
        'bmi' => ['tinggi_badan', 'berat_badan'],
        'visus_tanpa_kacamata' => ['visus_od_tanpa_kacamata', 'visus_os_tanpa_kacamata'],
        'visus_dengan_kacamata' => ['visus_od_dengan_kacamata', 'visus_os_dengan_kacamata'],
    ];

    public function edit(RencanaKunjungan $kunjungan, Karyawan $peserta): View
    {
        $this->assertParticipant($kunjungan, $peserta);

        return view('mcu.pemeriksaan', [
            'kunjungan' => $kunjungan,
            'peserta' => $peserta->load('paket', 'anamnesa'),
            'catalog' => Paket::getDefaultItemCatalog(),
            'historyKeys' => self::HISTORY,
            'grouped' => self::GROUPED,
        ]);
    }

    public function update(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta): RedirectResponse
    {
        $this->assertParticipant($kunjungan, $peserta);
        abort_if($kunjungan->is_closing, 403);
        $paket = $peserta->paket;
        $rules = [
            'status' => ['required', Rule::in(['draft', 'final'])],
            'catatan_dokter' => ['nullable', 'string', 'max:5000'],
            'anjuran_saran' => ['nullable', 'string', 'max:5000'],
            'status_kelaikan' => ['nullable', Rule::in(['Fit to Work', 'Fit with Note', 'Temporarily Unfit', 'Unfit'])],
        ];

        foreach (Paket::getDefaultItemCatalog() as $items) {
            foreach ($items as $key => $label) {
                if (! $paket->isItemActive($key)) {
                    continue;
                }
                if (in_array($key, self::HISTORY, true)) {
                    $rules['history.'.$key] = ['nullable', 'boolean'];
                } elseif ($key === 'bmi') {
                    continue;
                } else {
                    foreach (self::GROUPED[$key] ?? [$key] as $field) {
                        $rules[$field] = match ($field) {
                            'tinggi_badan', 'berat_badan', 'lingkar_pinggang', 'suhu_badan' => ['nullable', 'numeric', 'gt:0', 'max:999'],
                            'nadi', 'pernapasan' => ['nullable', 'integer', 'min:1', 'max:300'],
                            default => ['nullable', 'string', 'max:255'],
                        };
                    }
                }
            }
        }
        $data = $request->validate($rules);
        $history = $data['history'] ?? [];
        unset($data['history']);
        $data['riwayat_penyakit_dahulu'] = $history;
        if ($paket->isItemActive('bmi') && isset($data['tinggi_badan'], $data['berat_badan'])) {
            $calculation = Anamnesa::calculateBmi((float) $data['tinggi_badan'], (float) $data['berat_badan']);
            $data['bmi'] = $calculation['bmi'];
            $data['kategori_bmi'] = $calculation['kategori'];
        }
        foreach (Paket::getDefaultItemCatalog() as $items) {
            foreach (array_keys($items) as $key) {
                if ($paket->isItemActive($key)) {
                    continue;
                }
                if (in_array($key, self::HISTORY, true)) {
                    continue;
                }
                if ($key === 'bmi') {
                    $data['bmi'] = null;
                    $data['kategori_bmi'] = null;

                    continue;
                }
                foreach (self::GROUPED[$key] ?? [$key] as $field) {
                    $data[$field] = null;
                }
            }
        }
        $data['rencana_kunjungan_id'] = $kunjungan->id;
        $peserta->anamnesa()->updateOrCreate([], $data);
        $peserta->update(['status_pemeriksaan' => $data['status'] === 'final' ? 'selesai' : 'proses']);

        return back()->with('success', 'Pemeriksaan tersimpan.');
    }

    private function assertParticipant(RencanaKunjungan $kunjungan, Karyawan $peserta): void
    {
        abort_unless($peserta->rencana_kunjungan_id === $kunjungan->id, 404);
        abort_unless($peserta->paket()->where('rencana_kunjungan_id', $kunjungan->id)->exists(), 404);
    }
}
