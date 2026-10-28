<?php

namespace App;

use App\Models\Karyawan;
use App\Models\Pasien;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PatientProfile
{
    /** @return array{identity: Pasien, pasien: Karyawan, currentEmployment: Karyawan, episodes: Collection, patientAge: ?int, patientPhotoUrl: ?string} */
    public function data(Pasien $identity, ?Karyawan $selected = null): array
    {
        $episodes = $identity->episodes()->with('perusahaan', 'paket', 'rencanaKunjungan', 'anamnesa')
            ->orderByRaw('COALESCE(tanggal_mcu, created_at) DESC')->orderByDesc('id')->get();
        $path = $this->photoPath($identity, $episodes);

        return [
            'identity' => $identity,
            'pasien' => $selected ?? $episodes->first() ?? new Karyawan(['pasien_id' => $identity->id]),
            'currentEmployment' => $episodes->first() ?? new Karyawan,
            'episodes' => $episodes,
            'patientAge' => $identity->tanggal_lahir?->age,
            'patientPhotoUrl' => $path ? route('pasien.identity.photo', [$identity, 'v' => hash('sha256', $path)]) : null,
        ];
    }

    /** @param Collection<int, Karyawan> $episodes */
    public function photoPath(Pasien $identity, Collection $episodes): ?string
    {
        $episodePhoto = $episodes->first(fn (Karyawan $episode): bool => ! str_contains($episode->foto ?? '', '..') && $episode->hasStoredPhoto())?->foto;
        if ($episodePhoto) {
            return $episodePhoto;
        }
        if ($identity->foto && str_starts_with($identity->foto, 'patients/'.$identity->id.'/') && ! str_contains($identity->foto, '..') && Storage::disk('local')->exists($identity->foto)) {
            return $identity->foto;
        }

        return null;
    }

    public function editable(?Karyawan $episode): bool
    {
        return ! $episode || (! $episode->is_closing && ! $episode->rencanaKunjungan?->is_closing && $episode->anamnesa?->status !== 'final' && ! in_array($episode->status_pemeriksaan, ['batal', 'tidak_hadir'], true));
    }
}
