<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PatientPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_photo_blocks_all_form_tabs_and_direct_result_updates(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();
        foreach (['ringkasan', 'identitas', 'anamnesa', 'fisik', 'laboratorium', 'penunjang', 'kesimpulan', 'review'] as $tab) {
            $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $tab]))
                ->assertOk()->assertViewIs('mcu.pemeriksaan-foto')->assertSee('Foto pasien terlebih dahulu')
                ->assertSee('Buka kamera realtime')->assertSee('Ctrl+V / ⌘+V')->assertDontSee('name="section"', false);
        }
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), [
            'section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0,
            'anamnesa' => ['hipertensi' => ['answer' => 'Ya']],
        ])->assertSessionHasErrors('foto_pasien');
        $this->assertNull($participant->fresh()->anamnesa);
    }

    public function test_photo_is_converted_to_private_webp_resized_and_unlocks_forms(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();
        $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), [
            'foto_pasien' => UploadedFile::fake()->image('portrait.png', 1800, 2400),
        ])->assertRedirect(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']))->assertSessionHasNoErrors();
        $participant->refresh();
        $this->assertTrue($participant->hasStoredPhoto());
        $this->assertStringEndsWith('.webp', $participant->foto);
        $bytes = Storage::disk('local')->get($participant->foto);
        $dimensions = getimagesizefromstring($bytes);
        $this->assertSame('image/webp', $dimensions['mime']);
        $this->assertSame(900, $dimensions[0]);
        $this->assertSame(1200, $dimensions[1]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'anamnesa']))
            ->assertOk()->assertViewIs('mcu.pemeriksaan')->assertSee('anamnesa[hipertensi][answer]', false);
        $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'foto']))
            ->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_sources_over_five_megabytes_or_unsupported_files_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();
        $url = route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]);
        $this->post($url, [])->assertSessionHasErrors('foto_pasien');
        $this->post($url, ['foto_pasien' => UploadedFile::fake()->image('large.jpg')->size(5121)])->assertSessionHasErrors('foto_pasien');
        $this->post($url, ['foto_pasien' => UploadedFile::fake()->create('bad.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('foto_pasien');
        $this->assertNull($participant->fresh()->foto);
        $this->assertSame([], Storage::disk('local')->allFiles('mcu/'.$participant->id));
        $this->post($url, ['foto_pasien' => UploadedFile::fake()->image('limit.png')->size(5120)])->assertSessionHasNoErrors();
        $this->assertTrue($participant->fresh()->hasStoredPhoto());
    }

    public function test_missing_stored_file_does_not_unlock_forms_even_when_path_is_present(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->withPhoto()->create();
        Storage::disk('local')->delete($participant->foto);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertViewIs('mcu.pemeriksaan-foto');
    }

    public function test_photo_replacement_invalidates_stale_forms_and_preserves_previous_photo_for_revisions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->withPhoto()->create();
        $previousPath = $participant->foto;
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'lock_version' => 4]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'foto']))->assertOk()->assertSee('Ganti foto pasien');
        $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), ['foto_pasien' => UploadedFile::fake()->image('new.jpg')])
            ->assertRedirect(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']))->assertSessionHasNoErrors();
        $this->assertNotSame($previousPath, $participant->fresh()->foto);
        $this->assertSame(5, $exam->fresh()->lock_version);
        Storage::disk('local')->assertExists($previousPath);
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 4])->assertSessionHasErrors('pemeriksaan');
    }

    public function test_locked_results_and_closed_visits_cannot_change_photos_but_historical_results_remain_readable(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), ['foto_pasien' => UploadedFile::fake()->image('foto.png')])->assertForbidden();
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'review']))->assertOk()->assertSee('Hasil final — hanya baca');
        $participant->rencanaKunjungan->update(['is_closing' => true]);
        $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), ['foto_pasien' => UploadedFile::fake()->image('foto.png')])->assertForbidden();
    }

    public function test_guest_unauthorized_role_and_cross_visit_requests_cannot_upload_photos(): void
    {
        $participant = Karyawan::factory()->create();
        $url = route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]);
        $this->post($url, [])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'unknown']))->post($url, [])->assertForbidden();
        $other = Karyawan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'petugas']))->post(route('pemeriksaan.photo', [$other->rencanaKunjungan, $participant]), [])->assertNotFound();
    }

    public function test_finalization_cannot_bypass_missing_photo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id]);
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['lock_version' => 0, 'confirmed' => 1])->assertSessionHasErrors('foto_pasien');
        $this->assertNotSame('final', $exam->fresh()->status);
    }

    public function test_identity_displays_saved_episode_data_without_duplicate_inputs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->withPhoto()->create([
            'tanggal_mcu' => '2026-10-10', 'nip' => 'EMP-12345', 'bagian' => 'Operasional', 'jabatan' => 'Supervisor',
        ]);
        $response = $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']))
            ->assertOk()->assertSee('10/10/2026')->assertSee('EMP-12345')->assertSee('Operasional')->assertSee('Supervisor')
            ->assertSee('Lanjut ke anamnesa')->assertDontSee('Simpan draft')->assertDontSee('data-exam-form', false);
        foreach (['tanggal_mcu', 'nip', 'bagian', 'jabatan'] as $field) {
            $response->assertDontSee('name="'.$field.'"', false);
        }
    }

    public function test_replaced_photo_has_a_new_display_url_and_serves_the_new_image(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->withPhoto()->create();
        $previousBytes = Storage::disk('local')->get($participant->foto);
        $previousUrl = route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'foto', 'v' => hash('sha256', $participant->foto)]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']))->assertSee($previousUrl);

        $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), [
            'foto_pasien' => UploadedFile::fake()->image('replacement.png', 60, 90),
        ])->assertSessionHasNoErrors();
        $participant->refresh();
        $newUrl = route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'foto', 'v' => hash('sha256', $participant->foto)]);
        $this->assertNotSame($previousUrl, $newUrl);
        foreach (['identitas', 'foto'] as $tab) {
            $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $tab]))
                ->assertOk()->assertSee($newUrl)->assertDontSee($previousUrl);
        }
        $this->get(route('pasien.show', $participant))->assertOk()->assertSee($newUrl)->assertDontSee($previousUrl);
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee($newUrl);
        $response = $this->get($newUrl)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $newBytes = $response->streamedContent();
        $this->assertNotSame($previousBytes, $newBytes);
        $this->assertSame(Storage::disk('local')->get($participant->foto), $newBytes);
        $dimensions = getimagesizefromstring($newBytes);
        $this->assertSame(60, $dimensions[0]);
        $this->assertSame(90, $dimensions[1]);
    }

    public function test_identity_has_clear_edit_actions_and_photo_modal_instead_of_a_photo_page_link(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = Karyawan::factory()->withPhoto()->create();
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']))
            ->assertOk()->assertSee('Edit identitas pasien')->assertDontSee(route('pasien.edit', $participant))
            ->assertSee('data-patient-identity', false)->assertSee(route('pasien.update', $participant))
            ->assertSee('placeholder="Masukkan nama lengkap sesuai identitas"', false)
            ->assertSee('placeholder="Masukkan 16 digit NIK"', false)
            ->assertSee('placeholder="Contoh: EMP-876"', false)
            ->assertSee('placeholder="Contoh: Jakarta"', false)
            ->assertSee('placeholder="Contoh: Operasional"', false)
            ->assertSee('placeholder="Contoh: Staf administrasi"', false)
            ->assertSee('placeholder="Contoh: 081234567890"', false)->assertSee('Pilih jenis kelamin')
            ->assertDontSee('name="tanggal_mcu"', false)
            ->assertSee('data-photo-modal-open', false)->assertSee('<dialog id="patient-photo-modal"', false)
            ->assertSee('aria-labelledby="patient-photo-modal-title"', false)->assertSee('Simpan foto baru')
            ->assertSee('Buka kamera realtime')->assertSee('name="foto_pasien"', false)
            ->assertDontSee('Perbaiki identitas pasien')->assertDontSee('Ganti foto pasien')
            ->assertDontSee(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'foto']));
    }

    public function test_photo_validation_failure_reopens_modal_and_preserves_current_photo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->withPhoto()->create();
        $oldPath = $participant->foto;
        $url = route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']);
        $this->followingRedirects()->from($url)->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), [])
            ->assertOk()->assertViewHas('tab', 'identitas')->assertSee('data-photo-modal-errors', false)
            ->assertSee('Pilih ulang foto sebelum menyimpan.');
        $this->assertSame($oldPath, $participant->fresh()->foto);
        Storage::disk('local')->assertExists($oldPath);
    }

    public function test_readonly_identity_has_photo_and_qr_without_edit_buttons_or_modal(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']))
            ->assertOk()->assertSee('Foto peserta '.$participant->nama_karyawan)->assertSee('QR menuju pemeriksaan pasien')
            ->assertDontSee('Edit identitas pasien')->assertDontSee('data-photo-modal-open', false)
            ->assertDontSee('<dialog', false);
    }

    public function test_identity_modal_updates_identity_and_returns_to_the_identity_tab_without_changing_the_photo(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create();
        $photo = $participant->foto;
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->put(route('pasien.update', $participant), ['nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'identity_modal' => '1', 'nama_karyawan' => 'Nama Diperbarui', 'jenis_kelamin' => 'P',
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $participant->paket_id])->assertRedirect(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'identitas']));

        $this->assertSame('Nama Diperbarui', $participant->fresh()->nama_karyawan);
        $this->assertSame('Nama Diperbarui', $participant->pasien->fresh()->nama);
        $this->assertSame($photo, $participant->fresh()->foto);
    }

    public function test_identity_validation_reopens_the_combined_modal_with_the_entered_values(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'identitas']);

        $this->followingRedirects()->from($url)->put(route('pasien.update', $participant), ['identity_modal' => '1', 'nama_karyawan' => 'Draft Nama', 'nik' => 'invalid', 'jenis_kelamin' => 'P',
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $participant->paket_id])->assertOk()
            ->assertSee('data-photo-modal-errors', false)->assertSee('value="Draft Nama"', false)->assertSee('value="invalid"', false);

        $this->assertNotSame('Draft Nama', $participant->fresh()->nama_karyawan);
    }

    public function test_identity_modal_cannot_edit_a_final_examination(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        $name = $participant->nama_karyawan;
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->put(route('pasien.update', $participant), ['nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'identity_modal' => '1', 'nama_karyawan' => 'Tidak boleh', 'jenis_kelamin' => 'P',
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $participant->paket_id])->assertForbidden();

        $this->assertSame($name, $participant->fresh()->nama_karyawan);
    }

    #[TestWith([null])]
    #[TestWith(['2026-10-01'])]
    public function test_first_episode_photo_records_the_actual_mcu_date(?string $previousDate): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(10, 0));
        $participant = Karyawan::factory()->create(['tanggal_mcu' => $previousDate]);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));

        $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), ['foto_pasien' => UploadedFile::fake()->image('foto.jpg')])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-22', $participant->fresh()->tanggal_mcu->toDateString());
    }

    #[TestWith(['2026-10-01', '2026-10-01'])]
    #[TestWith([null, '2026-10-22'])]
    public function test_replacing_a_photo_preserves_the_mcu_date_unless_it_is_missing(?string $previousDate, string $expectedDate): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(10, 0));
        $participant = Karyawan::factory()->withPhoto()->create(['tanggal_mcu' => $previousDate]);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));

        $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), ['foto_pasien' => UploadedFile::fake()->image('replacement.jpg')])->assertSessionHasNoErrors();

        $this->assertSame($expectedDate, $participant->fresh()->tanggal_mcu->toDateString());
    }

    public function test_inherited_photo_does_not_prevent_recording_the_new_episode_date(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(10, 0));
        $previous = Karyawan::factory()->withPhoto()->create();
        $participant = Karyawan::factory()->create(['pasien_id' => $previous->pasien_id, 'foto' => $previous->foto, 'tanggal_mcu' => '2026-10-01']);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));

        $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), ['foto_pasien' => UploadedFile::fake()->image('new-episode.jpg')])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-22', $participant->fresh()->tanggal_mcu->toDateString());
    }

    public function test_failed_photo_upload_does_not_record_a_mcu_date(): void
    {
        $participant = Karyawan::factory()->create(['tanggal_mcu' => null]);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));

        $this->post(route('pemeriksaan.photo', [$participant->rencanaKunjungan, $participant]), [])->assertSessionHasErrors('foto_pasien');

        $this->assertNull($participant->fresh()->tanggal_mcu);
    }

    public function test_identity_modal_cannot_override_the_automatically_recorded_mcu_date(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create(['tanggal_mcu' => '2026-10-01']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->put(route('pasien.update', $participant), ['nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'identity_modal' => '1', 'nama_karyawan' => $participant->nama_karyawan, 'jenis_kelamin' => $participant->jenis_kelamin,
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $participant->paket_id, 'tanggal_mcu' => '2026-10-30'])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-01', $participant->fresh()->tanggal_mcu->toDateString());
    }
}
