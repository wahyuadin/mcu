<?php

namespace Tests\Feature;

use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationAndDokterTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_visits_and_results_stay_under_kunjungan_mcu(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = Karyawan::factory()->withPhoto()->create();
        $visit = $participant->rencanaKunjungan;
        $this->get('/kunjungan-mcu')->assertOk()->assertSee('/kunjungan-mcu/'.$visit->id, false)->assertDontSee('LIVE');
        $this->get('/kunjungan-mcu/'.$visit->id)->assertOk()->assertSee($participant->nama_karyawan)
            ->assertSee('/kunjungan-mcu/'.$visit->id.'/peserta/'.$participant->id.'/pemeriksaan', false);
        $this->get(route('pemeriksaan.edit', [$visit, $participant]))->assertOk()
            ->assertSee('href="'.route('kunjungan-mcu.show', $visit).'"', false);
        $this->get(route('rencana-kunjungan.show', $visit))->assertOk()->assertSee('Registrasi PKS')
            ->assertSee(route('paket.create', ['rencana_kunjungan_id' => $visit->id]), false)->assertDontSee('Isi hasil');
        $this->get(route('rencana-kunjungan.index'))->assertOk();
    }

    public function test_petugas_can_open_operational_visit_but_not_planning_or_doctor_master(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();
        $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertOk();
        $this->get(route('rencana-kunjungan.show', $participant->rencanaKunjungan))->assertForbidden();
        $this->get(route('dokter.index'))->assertForbidden();
        $this->post(route('dokter.store'), ['nama_dokter' => 'Ditolak', 'spesialisasi' => 'Dokter MCU'])->assertForbidden();
        $doctor = Dokter::factory()->create();
        $this->put(route('dokter.update', $doctor), ['nama_dokter' => 'Ditolak', 'spesialisasi' => 'Dokter MCU'])->assertForbidden();
        $this->assertNotSame('Ditolak', $doctor->fresh()->nama_dokter);
    }

    public function test_admin_can_create_edit_search_and_deactivate_doctors_without_losing_results(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('dokter.create'))->assertOk()->assertDontSee('name="spesialisasi"', false);
        $this->from(route('dokter.create'))->post(route('dokter.store'), [
            'nama_dokter' => 'dr. Rani', 'no_str' => 'STR-123', 'is_active' => 1,
        ])->assertRedirect(route('dokter.create'))->assertSessionHasNoErrors();
        $doctor = Dokter::where('no_str', 'STR-123')->firstOrFail();
        $this->assertSame('Dokter MCU', $doctor->spesialisasi);
        $doctor->update(['spesialisasi' => 'Radiologi']);
        $participant = Karyawan::factory()->create();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'dokter_id' => $doctor->id, 'status' => 'final', 'catatan_dokter' => 'Hasil sebelumnya']);
        $this->get(route('dokter.edit', $doctor))->assertOk()->assertSee('dr. Rani')
            ->assertDontSee('name="spesialisasi"', false);
        $this->put(route('dokter.update', $doctor), [
            'nama_dokter' => 'dr. Rani Updated', 'no_str' => 'STR-123', 'is_active' => 0,
        ])->assertRedirect(route('dokter.index'))->assertSessionHasNoErrors();
        $this->assertFalse($doctor->fresh()->is_active);
        $this->assertSame('Radiologi', $doctor->fresh()->spesialisasi);
        $this->assertSame($doctor->id, $exam->fresh()->dokter_id);
        $this->assertSame('Hasil sebelumnya', $exam->fresh()->catatan_dokter);
        $this->get(route('dokter.index', ['q' => 'STR-123', 'status' => 'nonaktif']))->assertOk()->assertSee('dr. Rani Updated');
        $this->get(route('dokter.index', ['q' => 'STR-123', 'status' => 'aktif']))->assertOk()->assertDontSee('dr. Rani Updated');
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('dr. Rani Updated');
    }

    public function test_doctor_input_requires_valid_fields_and_does_not_assign_user_accounts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('dokter.store'), [])->assertSessionHasErrors(['nama_dokter']);
        $this->post(route('dokter.store'), ['nama_dokter' => 'Dokter', 'spesialisasi' => 'MCU', 'is_active' => 'invalid'])->assertSessionHasErrors('is_active');
        $this->post(route('dokter.store'), ['nama_dokter' => 'Dokter', 'spesialisasi' => 'MCU', 'user_id' => auth()->id()])->assertSessionHasNoErrors();
        $this->assertNull(Dokter::firstOrFail()->user_id);
        $this->assertSame('Dokter MCU', Dokter::firstOrFail()->spesialisasi);
    }

    public function test_inactive_doctor_is_not_available_for_new_results_but_existing_selection_is_preserved(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $active = Dokter::factory()->create(['nama_dokter' => 'Dokter Aktif']);
        $inactive = Dokter::factory()->create(['nama_dokter' => 'Dokter Nonaktif', 'is_active' => false]);
        $participant = Karyawan::factory()->withPhoto()->create();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kesimpulan']))
            ->assertOk()->assertSee('Dokter Aktif')->assertDontSee('Dokter Nonaktif');
        $this->put($url, ['section' => 'kesimpulan', 'status' => 'draft', 'lock_version' => 0, 'dokter_id' => $inactive->id])->assertSessionHasErrors('dokter_id');
        $this->put($url, ['section' => 'kesimpulan', 'status' => 'draft', 'lock_version' => 0, 'dokter_id' => $active->id])->assertSessionHasNoErrors();
        $active->update(['is_active' => false]);
        $this->put($url, ['section' => 'kesimpulan', 'status' => 'draft', 'lock_version' => 1, 'dokter_id' => $active->id])->assertSessionHasNoErrors();
        $this->assertSame($active->id, $participant->fresh()->anamnesa->dokter_id);
    }

    public function test_guests_cannot_manage_doctors_or_open_visit_detail(): void
    {
        $participant = Karyawan::factory()->create();
        $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertRedirect(route('login'));
        $this->get(route('dokter.index'))->assertRedirect(route('login'));
        $this->post(route('dokter.store'), [])->assertRedirect(route('login'));
    }

    public function test_patient_registration_opens_without_the_existing_patient_picker(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = Karyawan::factory()->create();
        $this->get(route('pasien.create', ['rencana_kunjungan_id' => $participant->rencana_kunjungan_id]))
            ->assertOk()->assertDontSee('Gunakan identitas pasien yang sudah terdaftar')->assertDontSee('Cari pasien lama')
            ->assertSee('Penempatan MCU')->assertSee('Identitas pasien')->assertSee('Informasi pekerjaan')
            ->assertSee('Simpan pendaftaran')->assertSee('Setelah pendaftaran')
            ->assertSee('value="'.$participant->rencana_kunjungan_id.'" selected', false)
            ->assertSee('href="'.route('kunjungan-mcu.show', $participant->rencanaKunjungan).'"', false);
        $this->get(route('pasien.create', ['pasien_id' => $participant->pasien_id]))
            ->assertOk()->assertSee('Identitas dipilih:')->assertSee($participant->nama_karyawan)
            ->assertSee('name="pasien_id"', false);
    }

    public function test_patient_profile_shows_latest_employment_and_preserves_previous_mcu_snapshots(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $older = Karyawan::factory()->create(['tanggal_mcu' => '2025-09-12', 'bagian' => 'Produksi', 'nip' => 'EMP-OLD']);
        $latest = Karyawan::factory()->create([
            'pasien_id' => $older->pasien_id, 'nama_karyawan' => $older->nama_karyawan,
            'tanggal_mcu' => '2026-10-02', 'bagian' => 'Operasional', 'nip' => 'EMP-NEW', 'status_pemeriksaan' => 'proses',
        ]);
        $this->get(route('pasien.show', $older))->assertOk()->assertSee('Profil pasien')->assertSee('Pekerjaan saat ini')
            ->assertSee('Riwayat pekerjaan')->assertSee('Riwayat MCU')->assertSee('Buat kunjungan MCU')
            ->assertSee('Produksi')->assertSee('Operasional')->assertSee('EMP-NEW')
            ->assertSee($older->nomor_mcu)->assertSee($latest->nomor_mcu)
            ->assertViewHas('currentEmployment', fn (Karyawan $episode): bool => $episode->id === $latest->id)
            ->assertSee(route('pemeriksaan.edit', [$latest->rencanaKunjungan, $latest]), false);
        $this->assertSame('Produksi', $older->fresh()->bagian);
        $this->assertSame('EMP-OLD', $older->fresh()->nip);
    }

    public function test_patient_profile_uses_current_identity_and_hides_admin_actions_for_petugas(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();
        $participant->pasien->update(['nama' => 'Identitas Terbaru', 'tanggal_lahir' => '1990-04-12']);
        $this->get(route('pasien.show', $participant))->assertOk()->assertSee('Identitas Terbaru')
            ->assertDontSee('Buat kunjungan MCU')->assertDontSee('Kelola pendaftaran episode')
            ->assertSee('Belum dicatat')->assertSee('Tanggal tercatat bukan tanggal mulai atau akhir masa kerja.');
    }

    public function test_sidebar_has_logo_and_doctor_master_link_without_mcu_label_or_live_badge(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('dokter.index'))->assertOk()->assertSee('alt="Nayaka"', false)->assertDontSee('>MCU</span>', false)
            ->assertSee('Master data')->assertSee('href="'.route('dokter.index').'"', false)->assertDontSee('LIVE');
    }
}
