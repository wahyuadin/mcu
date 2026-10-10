<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaporanRiwayatTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_laporan_riwayat_index(): void
    {
        $user = User::factory()->create(['role' => 'petugas']);
        $participant = Karyawan::factory()->withPhoto()->create(['status_pemeriksaan' => 'selesai']);

        $this->actingAs($user)
            ->get(route('laporan-riwayat.index'))
            ->assertOk()
            ->assertSee($participant->nama_karyawan)
            ->assertSee('Laporan terbit');
    }

    public function test_user_can_view_laporan_riwayat_show_page(): void
    {
        $user = User::factory()->create(['role' => 'petugas']);
        $participant = Karyawan::factory()->withPhoto()->create(['status_pemeriksaan' => 'selesai']);
        $participant->anamnesa()->create([
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id,
            'status' => 'final',
            'finalized_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('pemeriksaan.laporan-riwayat', [$participant->rencanaKunjungan, $participant]))
            ->assertOk()
            ->assertSee('Laporan MCU berhasil diterbitkan')
            ->assertSee('Laporan versi 1 siap dilihat')
            ->assertSee('Detail penerbitan')
            ->assertSee('Tindakan berikutnya')
            ->assertSee('Riwayat versi laporan')
            ->assertSee('Aktif / terbaru');
    }

    public function test_user_can_view_past_revision_snapshot(): void
    {
        $user = User::factory()->create(['role' => 'petugas']);
        $participant = Karyawan::factory()->withPhoto()->create(['status_pemeriksaan' => 'selesai']);
        $exam = $participant->anamnesa()->create([
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id,
            'status' => 'final',
            'finalized_at' => now(),
            'revisions' => [
                [
                    'reason' => 'Koreksi nilai tensi',
                    'operator' => 'Dokter Test',
                    'at' => now()->subDay()->toIso8601String(),
                    'snapshot' => [
                        'status' => 'final',
                        'fisik' => ['td_sistolik' => '120', 'td_diastolik' => '80'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('pemeriksaan.laporan-riwayat.version', [$participant->rencanaKunjungan, $participant, 1]))
            ->assertOk()
            ->assertSee('Laporan Medical Check Up')
            ->assertSee($participant->nama_karyawan);
    }
}
