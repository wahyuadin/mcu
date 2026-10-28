<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Pasien;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PatientWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_photo_on_left_age_company_npk_and_three_icon_actions_without_search_button(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 28));
        $episode = Karyawan::factory()->withPhoto()->create(['nama_karyawan' => 'Ichwan', 'tanggal_lahir' => '1976-04-24', 'nip' => '001234']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $html = $this->get(route('pasien.index'))->assertOk()->assertSee('50 tahun')->assertSee($episode->perusahaan->nama_perusahaan)
            ->assertSee('001234')->assertSee('data-patient-open="detail"', false)->assertSee('data-patient-open="edit"', false)
            ->assertSee('data-patient-delete', false)->assertDontSee('Riwayat &amp; detail')->getContent();
        $this->assertMatchesRegularExpression('/<img[^>]+alt="Foto Ichwan"[\s\S]*?<strong[^>]*>Ichwan<\/strong>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*>\s*Cari\s*<\/button>/', $html);
        $output = getenv('MCU_PATIENT_BROWSER_OUTPUT');
        if (is_string($output) && $output !== '') {
            File::ensureDirectoryExists($output);
            File::put($output.'/index.html', $html);
            File::put($output.'/detail.html', $this->get(route('pasien.identity.show', $episode->pasien_id))->assertOk()->getContent());
            File::put($output.'/edit.html', $this->get(route('pasien.identity.edit', [$episode->pasien_id, 'modal' => 1]))->assertOk()->getContent());
            File::put($output.'/photo.webp', Storage::disk('local')->get($episode->foto));
        }
    }

    #[TestWith(['nama', 'asc', ['Alpha', 'Beta', 'Zeta']])]
    #[TestWith(['nama', 'desc', ['Zeta', 'Beta', 'Alpha']])]
    #[TestWith(['tanggal_lahir', 'asc', ['Zeta', 'Beta', 'Alpha']])]
    public function test_allowlisted_sort_is_applied_and_retained_in_pagination(string $sort, string $direction, array $expected): void
    {
        Pasien::factory()->create(['nama' => 'Zeta', 'tanggal_lahir' => '1970-01-01']);
        Pasien::factory()->create(['nama' => 'Alpha', 'tanggal_lahir' => '1990-01-01']);
        Pasien::factory()->create(['nama' => 'Beta', 'tanggal_lahir' => '1980-01-01']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('pasien.index', compact('sort', 'direction')))->assertOk()
            ->assertViewHas('pasiens', fn ($patients): bool => $patients->pluck('nama')->all() === $expected);
    }

    public function test_invalid_sort_cannot_be_used_as_sql_and_defaults_to_latest_registration(): void
    {
        $first = Pasien::factory()->create(['created_at' => now()->subDays(2)]);
        $last = Pasien::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('pasien.index', ['sort' => 'nama desc; DROP TABLE pasiens', 'direction' => 'random']))->assertOk()
            ->assertViewHas('sort', 'created_at')->assertViewHas('direction', 'desc')
            ->assertViewHas('pasiens', fn ($patients): bool => $patients->modelKeys() === [$last->id, $first->id]);
    }

    public function test_detail_modal_reuses_the_patient_profile_and_includes_all_visit_history(): void
    {
        $first = Karyawan::factory()->create(['tanggal_mcu' => '2025-10-01']);
        $latest = Karyawan::factory()->create(['pasien_id' => $first->pasien_id, 'tanggal_mcu' => '2026-10-01']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('pasien.identity.show', $first->pasien_id))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Profil pasien')->assertSee('Pekerjaan saat ini')->assertSee('Riwayat pekerjaan')->assertSee('Riwayat MCU')
            ->assertSee($first->nomor_mcu)->assertSee($latest->nomor_mcu)->assertDontSee('<!doctype', false)->assertDontSee('id="sidebar"', false);
        $this->get(route('pasien.show', $first))->assertOk()->assertSee('Profil pasien')->assertSee('Riwayat MCU');
    }

    public function test_detail_and_edit_work_without_an_episode(): void
    {
        $patient = Pasien::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('pasien.identity.show', $patient))->assertOk()->assertSee('Pasien belum memiliki kunjungan MCU.');
        $this->get(route('pasien.identity.edit', [$patient, 'modal' => 1]))->assertOk()->assertSee('Profil pasien')->assertSee('Identitas pasien')->assertSee('data-patient-photo-input', false)->assertDontSee('<!doctype', false);
    }

    public function test_edit_saves_identity_employment_and_webp_photo_without_modifying_older_episodes(): void
    {
        Storage::fake('local');
        $old = Karyawan::factory()->create(['tanggal_mcu' => '2025-10-01']);
        $current = Karyawan::factory()->create(['pasien_id' => $old->pasien_id, 'tanggal_mcu' => '2026-10-01']);
        $originalName = $old->nama_karyawan;
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('pasien.identity.edit', [$old->pasien_id, 'modal' => 1]))->assertOk()->assertSee($current->nip);
        $payload = ['patient_workspace' => '1', 'episode_id' => $current->id, 'nama' => 'Ichwan diperbarui', 'jenis_kelamin' => 'L', 'nik' => '0012345678901234', 'tanggal_lahir' => '1976-04-24', 'nip' => 'NPK-001', 'bagian' => 'Keuangan', 'jabatan' => 'Manajer', 'foto_pasien' => UploadedFile::fake()->image('foto.jpg', 1600, 1200)];
        $this->putJson(route('pasien.identity.update', $old->pasien_id), $payload)->assertOk()->assertJsonPath('message', 'Data dan foto pasien berhasil diperbarui.');
        $this->assertDatabaseHas('pasiens', ['id' => $old->pasien_id, 'nama' => 'Ichwan diperbarui', 'nik' => '0012345678901234']);
        $this->assertDatabaseHas('karyawans', ['id' => $current->id, 'nama_karyawan' => 'Ichwan diperbarui', 'nip' => 'NPK-001', 'bagian' => 'Keuangan']);
        $path = $current->fresh()->foto;
        $this->assertStringStartsWith('mcu/'.$current->id.'/', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('image/webp', Storage::disk('local')->mimeType($path));
        $this->assertSame($originalName, $old->fresh()->nama_karyawan);
        $this->assertNull($old->fresh()->foto);
        $this->get(route('pasien.identity.photo', $old->pasien_id))->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_standalone_photo_is_stored_privately_and_shown_in_the_list(): void
    {
        Storage::fake('local');
        $patient = Pasien::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson(route('pasien.identity.update', $patient), ['patient_workspace' => 1, 'nama' => $patient->nama, 'jenis_kelamin' => 'P', 'tanggal_lahir' => '1990-01-01', 'foto_pasien' => UploadedFile::fake()->image('foto.png')])->assertOk();
        $this->assertStringStartsWith('patients/'.$patient->id.'/', $patient->fresh()->foto);
        $this->get(route('pasien.index'))->assertOk()->assertSee(route('pasien.identity.photo', $patient), false);
        $this->get(route('pasien.identity.photo', $patient))->assertOk();
    }

    #[TestWith(['closed'])]
    #[TestWith(['final'])]
    #[TestWith(['cancelled'])]
    public function test_locked_episode_refuses_edit_and_photo_changes(string $state): void
    {
        Storage::fake('local');
        $episode = Karyawan::factory()->create();
        if ($state === 'closed') {
            $episode->rencanaKunjungan->update(['is_closing' => true]);
        } elseif ($state === 'final') {
            $episode->anamnesa()->create(['rencana_kunjungan_id' => $episode->rencana_kunjungan_id, 'status' => 'final']);
        } else {
            $episode->update(['status_pemeriksaan' => 'batal']);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('pasien.identity.edit', [$episode->pasien_id, 'modal' => 1]))->assertForbidden();
        $this->putJson(route('pasien.identity.update', $episode->pasien_id), ['nama' => 'Tidak boleh berubah', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '1990-01-01', 'foto_pasien' => UploadedFile::fake()->image('foto.jpg')])->assertForbidden();
        $this->assertNotSame('Tidak boleh berubah', $episode->pasien->fresh()->nama);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_photo_and_stale_episode_cannot_partially_update_identity(): void
    {
        $episode = Karyawan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['patient_workspace' => 1, 'nama' => 'Jangan disimpan', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '1990-01-01', 'nip' => 'NPK-01', 'bagian' => 'Operasional'];
        $this->putJson(route('pasien.identity.update', $episode->pasien_id), [...$data, 'episode_id' => $episode->id, 'foto_pasien' => UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml')])->assertUnprocessable()->assertJsonValidationErrors('foto_pasien');
        $this->putJson(route('pasien.identity.update', $episode->pasien_id), $data)->assertUnprocessable()->assertJsonValidationErrors('episode_id');
        $this->assertNotSame('Jangan disimpan', $episode->pasien->fresh()->nama);
    }

    public function test_photo_falls_back_to_latest_available_episode_and_is_not_public(): void
    {
        Storage::fake('local');
        $photo = Karyawan::factory()->withPhoto()->create(['tanggal_mcu' => '2025-10-01']);
        Karyawan::factory()->create(['pasien_id' => $photo->pasien_id, 'tanggal_mcu' => '2026-10-01']);
        $url = route('pasien.identity.photo', $photo->pasien_id);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'hrd']))->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'petugas']))->get($url)->assertOk();
        $this->get(route('pasien.index'))->assertOk()->assertSee($url, false)->assertDontSee('data-patient-open="edit"', false);
    }

    public function test_ajax_delete_returns_json_but_still_refuses_patients_with_any_visit(): void
    {
        $patient = Pasien::factory()->create();
        $episode = Karyawan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson(route('pasien.identity.destroy', $patient))->assertOk()->assertJsonPath('message', 'Pasien dihapus.');
        $this->assertSoftDeleted($patient);
        $this->deleteJson(route('pasien.identity.destroy', $episode->pasien_id))->assertUnprocessable()->assertJsonValidationErrors('pasien');
        $this->assertNotSoftDeleted($episode->pasien);
    }

    public function test_non_admin_can_view_detail_but_cannot_edit_or_upload_photo(): void
    {
        $patient = Pasien::factory()->create();
        $this->get(route('pasien.identity.show', $patient))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->get(route('pasien.identity.show', $patient))->assertOk();
        $this->get(route('pasien.identity.edit', [$patient, 'modal' => 1]))->assertForbidden();
        $this->putJson(route('pasien.identity.update', $patient), [])->assertForbidden();

    }
}
