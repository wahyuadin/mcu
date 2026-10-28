<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Pasien;
use App\Models\RencanaKunjungan;
use App\PatientPhoto;
use App\PatientProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PatientIdentityController extends Controller
{
    public function show(Pasien $identity, PatientProfile $profiles): Response
    {
        return response()->view('mcu.pasiens.profile', [...$profiles->data($identity), 'profileModal' => true])->header('Cache-Control', 'no-store, private');
    }

    public function photo(Pasien $identity, PatientProfile $profiles): StreamedResponse
    {
        $episodes = $identity->episodes()->whereNotNull('foto')->orderByRaw('COALESCE(tanggal_mcu, created_at) DESC')->orderByDesc('id')->get(['id', 'foto']);
        $path = $profiles->photoPath($identity, $episodes);
        abort_unless($path, 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function edit(Request $request, Pasien $identity, PatientProfile $profiles): View|Response
    {
        $data = $profiles->data($identity);
        abort_unless($profiles->editable($data['currentEmployment']->exists ? $data['currentEmployment'] : null), 403, 'Data hasil final atau kunjungan ditutup tidak dapat diedit.');
        if ($request->boolean('modal')) {
            return response()->view('mcu.pasiens.edit-modal', $data)->header('Cache-Control', 'no-store, private');
        }

        return view('mcu.pasiens.identity-form', ['identity' => $identity]);
    }

    public function update(Request $request, Pasien $identity, PatientProfile $profiles, PatientPhoto $photos): RedirectResponse|JsonResponse
    {
        $workspace = $request->boolean('patient_workspace');
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'nik' => ['nullable', 'digits:16'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'tempat_lahir' => ['nullable', 'string', 'max:255'],
            'tanggal_lahir' => ['required', 'date', 'before:today'],
            'telepon' => ['nullable', 'string', 'max:255'],
            'foto_pasien' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'episode_id' => ['nullable', 'uuid'],
            'nip' => [Rule::requiredIf($workspace && $identity->episodes()->exists()), 'nullable', 'string', 'max:50'],
            'bagian' => [Rule::requiredIf($workspace && $identity->episodes()->exists()), 'nullable', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
        ]);
        $path = null;
        try {
            DB::transaction(function () use ($data, $workspace, $identity, $profiles, $photos, $request, &$path): void {
                $episode = $identity->episodes()->orderByRaw('COALESCE(tanggal_mcu, created_at) DESC')->orderByDesc('id')->first();
                if ($episode) {
                    RencanaKunjungan::whereKey($episode->rencana_kunjungan_id)->lockForUpdate()->firstOrFail();
                }
                $patient = Pasien::whereKey($identity->id)->lockForUpdate()->firstOrFail();
                $latest = $patient->episodes()->orderByRaw('COALESCE(tanggal_mcu, created_at) DESC')->orderByDesc('id')->first();
                if ($latest?->id !== $episode?->id || ($workspace && ($data['episode_id'] ?? null) !== $latest?->id)) {
                    throw ValidationException::withMessages(['episode_id' => 'Kunjungan terbaru berubah. Tutup dan buka kembali modal edit.']);
                }
                if ($episode) {
                    $episode = Karyawan::whereKey($episode->id)->lockForUpdate()->firstOrFail();
                    $episode->load('rencanaKunjungan');
                    $episode->setRelation('anamnesa', $episode->anamnesa()->lockForUpdate()->first());
                }
                abort_unless($profiles->editable($episode), 403, 'Data hasil final atau kunjungan ditutup tidak dapat diedit.');
                $identityData = Arr::only($data, ['nama', 'nik', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'telepon']);
                if ($request->hasFile('foto_pasien')) {
                    $path = $photos->store($request->file('foto_pasien'), $episode ?? $patient);
                    $identityData['foto'] = $episode ? null : $path;
                }
                $patient->update($identityData);
                if ($episode && $workspace) {
                    $episodeData = ['nama_karyawan' => $data['nama'], ...Arr::except($identityData, ['nama', 'foto']), ...Arr::only($data, ['nip', 'bagian', 'jabatan'])];
                    if ($path) {
                        $episodeData['foto'] = $path;
                        $episodeData['tanggal_mcu'] = $episode->tanggal_mcu ?? now()->toDateString();
                    }
                    $episode->update($episodeData);
                    if ($episode->anamnesa) {
                        $episode->anamnesa->increment('lock_version');
                        $episode->update(['status_pemeriksaan' => $episode->anamnesa->finalizationErrors($episode) === [] ? 'siap_finalisasi' : 'proses']);
                    }
                } elseif ($episode && $path) {
                    $episode->update(['foto' => $path, 'tanggal_mcu' => $episode->tanggal_mcu ?? now()->toDateString()]);
                    $episode->anamnesa?->increment('lock_version');
                }
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Data dan foto pasien berhasil diperbarui.']);
        }

        return redirect()->route('pasien.index')->with('success', 'Identitas pasien diperbarui. Data episode MCU lama tidak diubah.');
    }

    public function destroy(Request $request, Pasien $identity): RedirectResponse|JsonResponse
    {
        DB::transaction(function () use ($identity): void {
            $patient = Pasien::whereKey($identity->id)->lockForUpdate()->firstOrFail();
            if ($patient->episodes()->withTrashed()->exists()) {
                throw ValidationException::withMessages(['pasien' => 'Pasien sudah memiliki kunjungan.']);
            }
            $patient->delete();
        });

        return $request->expectsJson() ? response()->json(['message' => 'Pasien dihapus.']) : redirect()->route('pasien.index')->with('success', 'Pasien dihapus.');
    }
}
