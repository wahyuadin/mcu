<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientBirthplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_and_identity_edits_save_birthplace_and_new_episodes_copy_master_identity(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $package = Paket::factory()->master()->create();
        $data = ['nip' => 'EMP-001', 'bagian' => 'Operasional', 'nama_karyawan' => 'Wahyu Adi Nugroho', 'jenis_kelamin' => 'L', 'tempat_lahir' => 'Bandung', 'tanggal_lahir' => '2005-08-24', 'rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id];

        $this->get(route('pasien.create'))->assertOk()->assertSee('name="tempat_lahir"', false);
        $this->post(route('pasien.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $episode = Karyawan::where('nama_karyawan', 'Wahyu Adi Nugroho')->firstOrFail();
        $this->assertSame('Bandung', $episode->tempat_lahir);
        $this->assertSame('Bandung', $episode->pasien->tempat_lahir);
        $this->get(route('pasien.show', $episode))->assertOk()->assertSee('Tempat lahir')->assertSee('Bandung');
        $this->get(route('pasien.edit', $episode))->assertOk()->assertSee('value="Bandung"', false);
        $this->put(route('pasien.update', $episode), [...$data, 'tempat_lahir' => 'Cimahi'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Cimahi', $episode->fresh()->pasien->tempat_lahir);
        $otherVisit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $this->get(route('pasien.create', ['pasien_id' => $episode->pasien_id]))->assertOk()->assertSee('value="Cimahi"', false);
        $this->post(route('pasien.store'), ['nip' => 'EMP-002', 'bagian' => 'Operasional', 'pasien_id' => $episode->pasien_id, 'tempat_lahir' => 'Override ditolak', 'rencana_kunjungan_id' => $otherVisit->id, 'paket_id' => $package->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('karyawans', ['pasien_id' => $episode->pasien_id, 'rencana_kunjungan_id' => $otherVisit->id, 'tempat_lahir' => 'Cimahi']);
        $this->assertDatabaseCount('pasiens', 1);
    }

    public function test_birthplace_is_optional_validated_and_escaped_in_patient_profile(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $episode = Karyawan::factory()->create(['tempat_lahir' => '<script>Bandung</script>']);
        $data = ['nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'nama_karyawan' => $episode->nama_karyawan, 'jenis_kelamin' => $episode->jenis_kelamin, 'rencana_kunjungan_id' => $episode->rencana_kunjungan_id, 'paket_id' => $episode->paket_id];
        $this->get(route('pasien.show', $episode))->assertOk()->assertSee('&lt;script&gt;Bandung&lt;/script&gt;', false)->assertDontSee('<script>Bandung</script>', false);
        $this->put(route('pasien.update', $episode), [...$data, 'tempat_lahir' => str_repeat('x', 256)])->assertSessionHasErrors('tempat_lahir');
        $this->assertSame('<script>Bandung</script>', $episode->fresh()->tempat_lahir);
        $this->put(route('pasien.update', $episode), [...$data, 'tempat_lahir' => null])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($episode->fresh()->tempat_lahir);
        $this->assertNull($episode->fresh()->pasien->tempat_lahir);
    }
}
