<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class VisitParticipantWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_numbers_are_per_visit_and_old_numbers_are_preserved_even_after_deletion(): void
    {
        $package = Paket::factory()->create();
        $old = Karyawan::factory()->create(['paket_id' => $package->id, 'nomor_mcu' => 'MCU-LEGACY']);
        $first = Karyawan::factory()->create(['paket_id' => $package->id]);
        $second = Karyawan::factory()->create(['paket_id' => $package->id]);
        $other = Karyawan::factory()->create();
        $this->assertMatchesRegularExpression('/^NYK-\d+-1$/', $first->nomor_mcu);
        $this->assertSame(substr($first->nomor_mcu, 0, -1).'2', $second->nomor_mcu);
        $this->assertNotSame($first->nomor_mcu, $other->nomor_mcu);
        $this->assertStringEndsWith('-1', $other->nomor_mcu);
        $second->delete();
        $third = Karyawan::factory()->create(['paket_id' => $package->id]);
        $this->assertStringEndsWith('-3', $third->nomor_mcu);
        $this->assertSame('MCU-LEGACY', $old->fresh()->nomor_mcu);
    }

    public function test_registration_filters_only_the_current_episode_photo_and_progress_ignores_search(): void
    {
        $package = Paket::factory()->create();
        $present = Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id]);
        $absent = Karyawan::factory()->create(['paket_id' => $package->id, 'pasien_id' => $present->pasien_id, 'foto' => $present->foto]);
        $package->rencanaKunjungan->update(['jumlah_peserta_direncanakan' => 1300]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'status' => 'registrasi']))->assertOk()
            ->assertViewHas('pesertas', fn ($rows): bool => $rows->total() === 1 && $rows->first()->id === $present->id)
            ->assertViewHas('activeProgress', fn ($card): bool => $card['done'] === 1 && $card['total'] === 1300)
            ->assertSee('Total peserta: <strong>1300</strong>', false)->assertSee('Sisa: <strong>1299</strong>', false)->assertDontSee('Target peserta:');
        $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'status' => 'registrasi', 'completion' => 'pending']))->assertOk()
            ->assertViewHas('pesertas', fn ($rows): bool => $rows->total() === 1 && $rows->first()->id === $present->id);
        $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'q' => 'does not exist']))->assertOk()
            ->assertViewHas('activeProgress', fn ($card): bool => $card['done'] === 2 && $card['total'] === 1300);
        $this->get(route('pasien.show', $absent))->assertOk()->assertDontSee('Kelola pendaftaran episode')->assertSee('Not Present');
    }

    public function test_category_filters_follow_package_and_ignore_the_removed_completion_selector(): void
    {
        $package = Paket::factory()->create(['active_items' => ['hipertensi' => true]]);
        $done = Karyawan::factory()->create(['paket_id' => $package->id]);
        Karyawan::factory()->create(['paket_id' => $package->id]);
        $done->anamnesa()->create(['rencana_kunjungan_id' => $done->rencana_kunjungan_id, 'anamnesa_data' => ['hipertensi' => ['answer' => 'Tidak']], 'section_status' => ['anamnesa' => 'selesai']]);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));

        $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'status' => 'anamnesa']))->assertOk()
            ->assertViewHas('pesertas', fn ($rows): bool => $rows->total() === 1 && $rows->first()->id === $done->id)
            ->assertViewHas('activeProgress', fn ($card): bool => $card['done'] === 1 && $card['total'] === 2)
            ->assertSee('Siap finalisasi')->assertSee('Export Excel')->assertDontSee('name="completion"', false)
            ->assertDontSee('>Sudah lengkap<', false)->assertDontSee('>Belum lengkap<', false);
        $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'status' => 'anamnesa', 'completion' => 'pending']))->assertOk()
            ->assertViewHas('pesertas', fn ($rows): bool => $rows->total() === 1 && $rows->first()->id === $done->id);
    }

    public function test_excel_is_a_real_workbook_scoped_to_search_and_attendance(): void
    {
        $package = Paket::factory()->create();
        $participant = Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id, 'nama_karyawan' => '=SUM(1,2)', 'nip' => '000123']);
        Karyawan::factory()->create(['paket_id' => $package->id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'download' => 'xlsx', 'status' => 'registrasi', 'q' => 'SUM']));
        $response->assertOk()->assertDownload('peserta-mcu-'.$package->rencana_kunjungan_id.'.xlsx');
        $path = tempnam(sys_get_temp_dir(), 'visit-xlsx');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame(2, $sheet->getHighestRow());
            $this->assertSame($participant->nomor_mcu, $sheet->getCell('A2')->getValue());
            $this->assertSame('s', $sheet->getCell('B2')->getDataType());
            $this->assertSame('000123', $sheet->getCell('C2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_hrd_cannot_access_internal_participant_export(): void
    {
        $package = Paket::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $package->perusahaan_id]));
        $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'download' => 'xlsx']))->assertForbidden();
    }

    public function test_category_filters_exclude_unmapped_participants_but_use_the_visit_total_for_progress(): void
    {
        $package = Paket::factory()->create(['active_items' => ['hipertensi' => true]]);
        $otherPackage = Paket::factory()->create(['active_items' => []]);
        $done = Karyawan::factory()->create(['paket_id' => $package->id]);
        $done->anamnesa()->create(['rencana_kunjungan_id' => $done->rencana_kunjungan_id, 'anamnesa_data' => ['hipertensi' => ['answer' => 'Tidak']], 'section_status' => ['anamnesa' => 'selesai']]);
        Karyawan::factory()->create(['paket_id' => $otherPackage->id, 'rencana_kunjungan_id' => $package->rencana_kunjungan_id, 'perusahaan_id' => $package->perusahaan_id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'status' => 'anamnesa', 'completion' => 'pending']))->assertOk()
            ->assertViewHas('activeProgress', fn ($card): bool => $card['done'] === 1 && $card['total'] === 2)
            ->assertViewHas('pesertas', fn ($rows): bool => $rows->total() === 1);
    }

    public function test_participant_cards_show_attendance_beside_the_name_without_category_badges(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create(['status_pemeriksaan' => 'proses']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertOk();
        preg_match('/<article data-participant="'.preg_quote($participant->id, '/').'".*?<\/article>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches);
        $card = $matches[0];
        $this->assertStringContainsString($participant->nomor_mcu, $card);
        $this->assertStringContainsString('bagian</span>', $card);
        $this->assertStringContainsString('Dalam pemeriksaan', $card);
        $this->assertStringContainsString('Buka pemeriksaan', $card);
        $this->assertMatchesRegularExpression('/'.preg_quote(e($participant->nama_karyawan), '/').'<\/a><span data-participant-attendance[^>]*>Present<\/span>/', $card);
        $this->assertStringNotContainsString('✓', $card);
        $this->assertStringNotContainsString('○', $card);
    }

    public function test_participant_badges_show_not_present_without_a_current_episode_photo(): void
    {
        $package = Paket::factory()->create();
        $previous = Karyawan::factory()->withPhoto()->create();
        $withoutPhoto = Karyawan::factory()->create(['paket_id' => $package->id, 'foto' => null]);
        $inheritedPhoto = Karyawan::factory()->create(['paket_id' => $package->id, 'pasien_id' => $previous->pasien_id, 'foto' => $previous->foto]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->get(route('kunjungan-mcu.show', $package->rencanaKunjungan))->assertOk();
        foreach ([$withoutPhoto, $inheritedPhoto] as $participant) {
            preg_match('/<article data-participant="'.preg_quote($participant->id, '/').'".*?<\/article>/s', $response->getContent(), $matches);
            $this->assertNotEmpty($matches);
            $this->assertMatchesRegularExpression('/'.preg_quote(e($participant->nama_karyawan), '/').'<\/a><span data-participant-attendance[^>]*>Not Present<\/span>/', $matches[0]);
        }
    }

    public function test_configured_total_is_used_for_final_progress_and_search_does_not_change_remaining(): void
    {
        $participant = Karyawan::factory()->create(['status_pemeriksaan' => 'selesai']);
        $participant->rencanaKunjungan->update(['jumlah_peserta_direncanakan' => 1300]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('kunjungan-mcu.show', [$participant->rencanaKunjungan, 'status' => 'selesai', 'q' => 'no matches']))->assertOk()
            ->assertViewHas('activeProgress', fn ($card): bool => $card['done'] === 1 && $card['total'] === 1300)
            ->assertViewHas('pesertas', fn ($rows): bool => $rows->total() === 0)
            ->assertSee('Total peserta: <strong>1300</strong>', false)
            ->assertSee('Sudah Final: <strong>1</strong>', false)
            ->assertSee('Sisa: <strong>1299</strong>', false);
    }

    public function test_excel_includes_all_matches_beyond_the_first_page_and_excludes_another_visit(): void
    {
        $package = Paket::factory()->create();
        Karyawan::factory()->count(21)->create(['paket_id' => $package->id]);
        Karyawan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('kunjungan-mcu.show', $package->rencanaKunjungan))->assertOk()
            ->assertViewHas('pesertas', fn ($rows): bool => $rows->count() === 20 && $rows->total() === 21);
        $response = $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'download' => 'xlsx', 'page' => 2]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'visit-xlsx');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $this->assertSame(22, $book->getActiveSheet()->getHighestRow());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }
}
