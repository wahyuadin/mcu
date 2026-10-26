<?php

namespace App;

use App\Models\Anamnesa;
use App\Models\Karyawan;
use App\Models\RencanaKunjungan;
use Illuminate\Http\Request;

class VisitParticipants
{
    /** @return array<string, mixed> */
    public function data(RencanaKunjungan $visit, Request $request): array
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'string', 'max:100']]);
        $cards = ['' => ['label' => 'Total peserta', 'done' => 0, 'total' => 0], 'registrasi' => ['label' => 'Registrasi', 'done' => 0, 'total' => 0]];
        foreach ($visit->pakets as $package) {
            $example = new Karyawan;
            $example->setRelation('paket', $package);
            foreach ((new Anamnesa)->workflowProgress($example) as $key => $section) {
                if ($section['included']) {
                    $cards[$key] ??= ['label' => $section['label'], 'done' => 0, 'total' => 0];
                }
            }
        }
        $progress = [];
        $attendance = [];
        $matching = [];
        $counts = [];
        $filter = (string) $request->query('status', '');
        $search = mb_strtolower(trim((string) $request->query('q', '')));
        foreach ($visit->karyawans()->with('paket', 'anamnesa')->orderBy('nama_karyawan')->lazy(100) as $participant) {
            $counts[$participant->status_pemeriksaan] = ($counts[$participant->status_pemeriksaan] ?? 0) + 1;
            $exam = $participant->anamnesa ?? new Anamnesa;
            $attendance[$participant->id] = $participant->hasStoredPhoto();
            $sections = array_filter($exam->workflowProgress($participant), fn (array $section): bool => $section['included']);
            $progress[$participant->id] = array_map(fn (array $section): array => ['label' => $section['label'], 'status' => $section['status']], $sections);
            $states = ['registrasi' => $attendance[$participant->id], 'siap_finalisasi' => $participant->status_pemeriksaan === 'siap_finalisasi', 'selesai' => $participant->status_pemeriksaan === 'selesai'];
            foreach ($sections as $key => $section) {
                $cards[$key] ??= ['label' => $section['label'], 'done' => 0, 'total' => 0];
                $states[$key] = $section['status'] === 'Selesai';
            }
            $cards['']['done']++;
            foreach ($states as $key => $done) {
                $cards[$key] ??= ['label' => $key === 'selesai' ? 'Final' : 'Siap finalisasi', 'done' => 0, 'total' => 0];
                $cards[$key]['total']++;
                $cards[$key]['done'] += (int) $done;
            }
            $matches = $filter === '' || (array_key_exists($filter, $states) ? $states[$filter] : $participant->status_pemeriksaan === $filter);
            if ($matches && ($search === '' || str_contains(mb_strtolower($participant->nama_karyawan.' '.$participant->nomor_mcu.' '.$participant->nip), $search))) {
                $matching[] = $participant->id;
            }
        }
        foreach (['siap_finalisasi' => 'Siap finalisasi', 'selesai' => 'Final'] as $key => $label) {
            $card = $cards[$key] ?? ['label' => $label, 'done' => 0, 'total' => count($attendance)];
            unset($cards[$key]);
            $cards[$key] = $card;
        }
        $visitParticipantTotal = $visit->jumlah_peserta_direncanakan ?? count($attendance);
        foreach ($cards as &$card) {
            $card['total'] = $visitParticipantTotal;
        }
        unset($card);
        $clinical = array_diff_key($cards, array_flip(['', 'registrasi', 'siap_finalisasi', 'selesai']));
        $cards = array_intersect_key($cards, array_flip(['', 'registrasi'])) + app(McuCategoryPresentation::class)->arrange($clinical) + array_intersect_key($cards, array_flip(['siap_finalisasi', 'selesai']));
        $active = $cards[$filter] ?? ['label' => $filter, 'done' => $counts[$filter] ?? 0, 'total' => $visitParticipantTotal];

        return ['filterCards' => $cards, 'activeProgress' => $active, 'categoryProgress' => $progress, 'attendance' => $attendance, 'matchingIds' => $matching, 'visitParticipantTotal' => $visitParticipantTotal];
    }
}
