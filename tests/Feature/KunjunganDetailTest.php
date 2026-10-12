<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KunjunganDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_contact_details_appear_below_company_name_in_address_email_phone_order(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $visit = RencanaKunjungan::factory()->create();
        $visit->perusahaan->update(['nama_perusahaan' => 'Perusahaan Kontak MCU', 'alamat' => "Jl. Contoh No. 10\nJakarta <script>alert(1)</script>", 'email' => 'kontak@example.test', 'telp' => '021-12345678']);

        $this->get(route('kunjungan-mcu.show', $visit))->assertOk()
            ->assertSeeInOrder(['Perusahaan Kontak MCU', 'Jl. Contoh No. 10', 'kontak@example.test', '021-12345678'])
            ->assertDontSee('>Alamat<', false)->assertDontSee('>Email<', false)->assertDontSee('>No. telp<', false)
            ->assertSee('Jakarta <script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_missing_company_contact_details_show_clear_placeholders(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $visit = RencanaKunjungan::factory()->create();
        $visit->perusahaan->update(['alamat' => null, 'email' => null, 'telp' => null]);

        $this->get(route('kunjungan-mcu.show', $visit))->assertOk()
            ->assertSeeInOrder(['Belum diisi', 'Belum diisi', 'Belum diisi'])
            ->assertDontSee('>Alamat<', false)->assertDontSee('>Email<', false)->assertDontSee('>No. telp<', false);
    }

    public function test_visit_counts_and_package_totals_stay_global_when_participants_are_filtered(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->create();
        $visit = $package->rencanaKunjungan;
        Karyawan::factory()->create(['paket_id' => $package->id, 'nama_karyawan' => 'Peserta Final', 'status_pemeriksaan' => 'selesai']);
        $pending = Karyawan::factory()->create(['paket_id' => $package->id, 'nama_karyawan' => 'Peserta Pengisian', 'status_pemeriksaan' => 'proses']);
        Karyawan::factory()->create(['paket_id' => $package->id, 'nama_karyawan' => 'Peserta Batal', 'status_pemeriksaan' => 'batal']);
        $this->get(route('kunjungan-mcu.show', [$visit, 'status' => 'proses', 'q' => 'Pengisian']))->assertOk()
            ->assertSee('Progres kunjungan')->assertSee('Informasi kunjungan')->assertSee('Paket pemeriksaan')->assertSee('Perlu ditindaklanjuti')
            ->assertSee('1 dari 3 hasil final')->assertSee('3 peserta ·')
            ->assertViewHas('totalParticipants', 3)->assertViewHas('remainingParticipants', 1)->assertViewHas('finalizedPercent', 33)
            ->assertViewHas('pesertas', fn ($participants): bool => $participants->total() === 1 && $participants->first()->id === $pending->id)
            ->assertViewHas('canComplete', false);
    }

    public function test_participant_progress_matches_the_examination_overview_for_individual_supporting_tests(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $package = Paket::factory()->create(['active_items' => ['hipertensi' => true, 'rontgen' => true, 'ekg' => true]]);
        $participant = Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id, 'status_pemeriksaan' => 'proses']);
        $participant->anamnesa()->create([
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id,
            'anamnesa_data' => ['hipertensi' => ['answer' => 'Tidak']],
            'penunjang' => ['rontgen' => ['skipped' => true, 'reason' => 'Tidak tersedia'], 'ekg' => ['skipped' => true, 'reason' => 'Tidak tersedia']],
            'section_status' => ['anamnesa' => 'selesai', 'penunjang' => 'selesai'],
        ]);
        $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertOk()->assertSee('4/4 bagian')
            ->assertViewHas('participantProgress', fn (array $progress): bool => $progress[$participant->id] === ['completed' => 4, 'total' => 4, 'percent' => 100]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('4 dari 4 bagian');
    }

    public function test_completion_requires_participants_in_terminal_statuses(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create();
        $this->get(route('kunjungan-mcu.show', $visit))->assertOk()->assertViewHas('canComplete', false)
            ->assertSee('Belum ada peserta terdaftar')->assertViewHas('finalizedPercent', 0);
        $package = Paket::factory()->create(['rencana_kunjungan_id' => $visit->id]);
        $participant = Karyawan::factory()->create(['paket_id' => $package->id, 'status_pemeriksaan' => 'siap_finalisasi']);
        $this->get(route('kunjungan-mcu.show', $visit))->assertOk()->assertViewHas('canComplete', false);
        $participant->update(['status_pemeriksaan' => 'tidak_hadir']);
        $this->get(route('kunjungan-mcu.show', $visit))->assertOk()->assertViewHas('canComplete', true)->assertViewHas('remainingParticipants', 0);
        $this->post(route('rencana-kunjungan.status', $visit), ['status' => 'closed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('kunjungan-mcu.show', $visit))->assertOk()->assertSee('Kunjungan telah ditutup.')
            ->assertDontSee('Daftarkan peserta')->assertViewHas('canComplete', false);
    }

    public function test_status_filters_include_cancelled_and_absent_participants_and_preserve_search_when_paginating(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $package = Paket::factory()->create();
        Karyawan::factory()->count(21)->create(['paket_id' => $package->id, 'nama_karyawan' => 'Peserta Batal', 'status_pemeriksaan' => 'batal']);
        Karyawan::factory()->create(['paket_id' => $package->id, 'nama_karyawan' => 'Peserta Absen', 'status_pemeriksaan' => 'tidak_hadir']);
        $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'status' => 'batal', 'q' => 'Batal']))->assertOk()
            ->assertViewHas('pesertas', fn ($participants): bool => $participants->total() === 21 && $participants->count() === 20)
            ->assertSee('status=batal', false)->assertSee('q=Batal', false)->assertDontSee('Peserta Absen');
        $this->get(route('kunjungan-mcu.show', [$package->rencanaKunjungan, 'status' => 'tidak_hadir']))->assertOk()
            ->assertSee('Peserta Absen')->assertViewHas('pesertas', fn ($participants): bool => $participants->total() === 1);
    }

    public function test_patient_names_are_escaped_in_visit_detail(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create(['nama_karyawan' => '<script>alert(1)</script>']);
        $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_visit_header_displays_master_company_logo_and_participant_photo_for_operator(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->withPhoto()->create();
        $company = $participant->perusahaan;
        $company->update(['logo_path' => 'perusahaan/logo.webp']);
        Storage::disk('local')->put($company->logo_path, 'logo');
        $photoUrl = route('pemeriksaan.attachment', [$participant->rencana_kunjungan_id, $participant, 'foto', 'v' => hash('sha256', $participant->foto)]);

        $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertOk()
            ->assertSee(route('perusahaan.logo', $company))->assertSee('alt="Logo '.e($company->nama_perusahaan).'"', false)
            ->assertSee($photoUrl)->assertSee('alt="Foto '.e($participant->nama_karyawan).'"', false)
            ->assertSee(route('pasien.show', $participant));
        $this->get(route('perusahaan.logo', $company))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->put(route('perusahaan.logo.update', $company), [])->assertForbidden();
    }

    public function test_visit_displays_default_icons_when_company_and_patient_have_no_images(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();

        $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertOk()
            ->assertSee('data-company-default-icon', false)->assertSee('data-patient-default-icon', false)
            ->assertDontSee('alt="Foto ', false);
    }

    public function test_patient_profile_photo_is_used_when_current_episode_has_no_photo_without_unlocking_examination(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $older = Karyawan::factory()->withPhoto()->create(['tanggal_mcu' => '2025-01-01']);
        $current = Karyawan::factory()->create(['pasien_id' => $older->pasien_id, 'tanggal_mcu' => '2026-01-01']);

        $this->get(route('kunjungan-mcu.show', $current->rencanaKunjungan))->assertOk()
            ->assertViewHas('participantPhotos', fn (array $photos): bool => $photos[$current->id]->id === $older->id)
            ->assertSee(route('pemeriksaan.attachment', [$older->rencana_kunjungan_id, $older, 'foto', 'v' => hash('sha256', $older->foto)]));
        $this->assertFalse($current->fresh()->hasStoredPhoto());
        $this->get(route('pemeriksaan.edit', [$current->rencanaKunjungan, $current]))->assertOk()->assertViewIs('mcu.pemeriksaan-foto');
    }
}
