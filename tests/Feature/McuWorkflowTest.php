<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class McuWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_username_or_email(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $this->post(route('login.store'), ['login' => 'admin', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));

        $this->post(route('login.store'), ['login' => 'admin@example.com', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_can_create_two_packages_and_assign_participants(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::create(['nama_perusahaan' => 'XYZ']);
        $visit = RencanaKunjungan::create(['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'XYZ-1', 'periode_kunjungan' => 'Oktober', 'kuota_peserta' => 1000]);
        $this->post(route('paket.store'), ['rencana_kunjungan_id' => $visit->id, 'nama_paket' => 'Paket 1 XYZ Custom', 'items' => ['tinggi_badan', 'hipertensi']])->assertRedirect();
        $this->post(route('paket.store'), ['rencana_kunjungan_id' => $visit->id, 'nama_paket' => 'Paket 2 XYZ Full', 'items' => ['tinggi_badan', 'hipertensi', 'tbc']])->assertRedirect();
        $custom = Paket::where('nama_paket', 'Paket 1 XYZ Custom')->firstOrFail();
        $full = Paket::where('nama_paket', 'Paket 2 XYZ Full')->firstOrFail();
        $this->assertFalse($custom->isItemActive('tbc'));
        $this->assertTrue($full->isItemActive('tbc'));
        $this->post(route('peserta.store', $visit), ['paket_id' => $custom->id, 'nama_karyawan' => 'Andi', 'jenis_kelamin' => 'L'])->assertRedirect();
        $this->post(route('peserta.store', $visit), ['paket_id' => $full->id, 'nama_karyawan' => 'Budi', 'jenis_kelamin' => 'L'])->assertRedirect();
        $this->assertSame(2, $visit->karyawans()->count());
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('perusahaan.index'))->assertOk();
        $this->get(route('paket.index'))->assertOk();
        $this->get(route('pasien.index'))->assertOk();
        $this->get(route('rencana-kunjungan.index'))->assertOk();
        $this->get(route('kunjungan-mcu.index'))->assertOk();
        $this->get(route('audits.index'))->assertOk();
        $this->get(route('rencana-kunjungan.show', $visit))->assertOk();
        $this->get(route('pemeriksaan.edit', [$visit, $visit->karyawans()->firstOrFail()]))->assertOk();
    }

    public function test_exam_rejects_values_for_items_outside_package_and_cross_visit_participant(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::create(['nama_perusahaan' => 'XYZ']);
        $visit = RencanaKunjungan::create(['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'XYZ-1', 'periode_kunjungan' => 'Oktober', 'kuota_peserta' => 1000]);
        $other = RencanaKunjungan::create(['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'XYZ-2', 'periode_kunjungan' => 'November', 'kuota_peserta' => 1000]);
        $package = Paket::create(['perusahaan_id' => $company->id, 'rencana_kunjungan_id' => $visit->id, 'nama_paket' => 'Tanpa TBC', 'active_items' => ['tinggi_badan' => true, 'tbc' => false]]);
        $participant = Karyawan::create(['perusahaan_id' => $company->id, 'rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id, 'nama_karyawan' => 'Andi']);
        $this->get(route('pemeriksaan.edit', [$other, $participant]))->assertNotFound();
        $this->post(route('pemeriksaan.photo', [$visit, $participant]), ['foto_pasien' => UploadedFile::fake()->image('foto.png')])->assertSessionHasNoErrors();
        $this->put(route('pemeriksaan.update', [$visit, $participant]), ['status' => 'draft', 'tinggi_badan' => 172, 'history' => ['tbc' => 1], 'berat_badan' => 90])->assertRedirect();
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame('172.00', $exam->tinggi_badan);
        $this->assertArrayNotHasKey('tbc', $exam->riwayat_penyakit_dahulu);
        $this->assertNull($exam->berat_badan);
    }

    public function test_admin_can_manage_packages_and_patients_on_separate_routes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::create(['nama_perusahaan' => 'XYZ']);
        $visit = RencanaKunjungan::create(['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'XYZ-3', 'periode_kunjungan' => 'Oktober', 'kuota_peserta' => 5]);
        $this->get(route('paket.create'))->assertOk();
        $this->post(route('paket.store'), ['rencana_kunjungan_id' => $visit->id, 'nama_paket' => 'Paket Dasar', 'items' => ['tbc']])->assertRedirect();
        $package = Paket::firstOrFail();
        $this->get(route('paket.show', $package))->assertOk();
        $this->get(route('paket.edit', $package))->assertOk();
        $this->put(route('paket.update', $package), ['rencana_kunjungan_id' => $visit->id, 'nama_paket' => 'Paket Baru', 'items' => ['tinggi_badan']])->assertRedirect();
        $this->assertFalse($package->fresh()->isItemActive('tbc'));

        $this->get(route('pasien.create'))->assertOk();
        $this->post(route('pasien.store'), ['rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id, 'nama_karyawan' => 'Siti', 'jenis_kelamin' => 'P'])->assertRedirect();
        $patient = Karyawan::firstOrFail();
        $this->get(route('pasien.show', $patient))->assertOk();
        $this->get(route('pasien.edit', $patient))->assertOk();
        $this->put(route('pasien.update', $patient), ['rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id, 'nama_karyawan' => 'Siti Baru', 'jenis_kelamin' => 'P'])->assertRedirect();
        $this->assertSame('Siti Baru', $patient->fresh()->nama_karyawan);
        $this->delete(route('paket.destroy', $package))->assertSessionHasErrors('paket');
        $this->delete(route('pasien.destroy', $patient))->assertRedirect(route('pasien.index'));
        $this->assertSoftDeleted($patient);
        $this->delete(route('paket.destroy', $package))->assertRedirect(route('paket.index'));
        $this->assertSoftDeleted($package);
    }

    public function test_patient_can_reuse_package_from_another_visit_and_auto_bind_it_to_selected_visit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::create(['nama_perusahaan' => 'XYZ']);
        $visit = RencanaKunjungan::create(['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'XYZ-3', 'periode_kunjungan' => 'Oktober']);
        $other = RencanaKunjungan::create(['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'XYZ-4', 'periode_kunjungan' => 'November']);
        $package = Paket::create(['perusahaan_id' => $company->id, 'rencana_kunjungan_id' => $other->id, 'nama_paket' => 'Lain', 'active_items' => ['tbc' => true]]);
        $this->post(route('pasien.store'), ['rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id, 'nama_karyawan' => 'Siti', 'jenis_kelamin' => 'P'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('karyawans', 1);
        $this->assertSame(2, $package->rencanaKunjungans()->count());
        $this->assertDatabaseHas('karyawans', ['paket_id' => $package->id, 'rencana_kunjungan_id' => $visit->id, 'perusahaan_id' => $company->id]);
        $this->get(route('pemeriksaan.edit', [$other, Karyawan::firstOrFail()]))->assertNotFound();
    }

    public function test_staff_can_view_patients_but_cannot_change_them_or_manage_packages(): void
    {
        $company = Perusahaan::create(['nama_perusahaan' => 'XYZ']);
        $visit = RencanaKunjungan::create(['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'XYZ-5', 'periode_kunjungan' => 'Oktober']);
        $package = Paket::create(['perusahaan_id' => $company->id, 'rencana_kunjungan_id' => $visit->id, 'nama_paket' => 'Standar', 'active_items' => ['tbc' => true]]);
        $patient = Karyawan::create(['perusahaan_id' => $company->id, 'rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id, 'nama_karyawan' => 'Andi']);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->get(route('pasien.index'))->assertOk();
        $this->get(route('kunjungan-mcu.index'))->assertOk();
        $this->get(route('pasien.show', $patient))->assertOk();
        $this->get(route('pasien.create'))->assertForbidden();
        $this->get(route('paket.index'))->assertForbidden();
        $this->delete(route('pasien.destroy', $patient))->assertForbidden();
        $this->assertNotSoftDeleted($patient);
    }

    public function test_guest_and_doctor_cannot_manage_packages(): void
    {
        $company = Perusahaan::create(['nama_perusahaan' => 'XYZ']);
        $visit = RencanaKunjungan::create(['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'XYZ-1', 'periode_kunjungan' => 'Oktober']);
        $this->post(route('paket.store'), ['rencana_kunjungan_id' => $visit->id, 'nama_paket' => 'Paket', 'items' => ['tbc']])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'dokter']))->post(route('paket.store'), ['rencana_kunjungan_id' => $visit->id, 'nama_paket' => 'Paket', 'items' => ['tbc']])->assertForbidden();
    }
}
