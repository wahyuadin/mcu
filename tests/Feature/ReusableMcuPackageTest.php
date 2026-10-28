<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReusableMcuPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_form_offers_live_search_and_escapes_searchable_item_labels(): void
    {
        $item = McuItem::factory()->create(['label' => 'Hemoglobin <b>contoh</b>', 'input_type' => 'text']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('paket.create'))->assertOk()->assertSee('data-package-search', false)->assertSee('Cari pemeriksaan')
            ->assertSee('data-package-search-status', false)->assertSee('data-package-search-empty', false)->assertSee($item->label)->assertDontSee('<b>contoh</b>', false);

        $this->get(route('paket.edit', Paket::factory()->master()->create()))->assertOk()->assertSee('data-package-search', false);
    }

    public function test_master_can_be_created_without_a_company_or_visit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('paket.create'))->assertOk()->assertDontSee('<select name="rencana_kunjungan_id"', false);
        $this->post(route('paket.store'), ['nama_paket' => 'Master Standar', 'items' => ['bmi', 'hipertensi']])->assertSessionHasNoErrors();
        $package = Paket::firstOrFail();
        $this->assertNull($package->perusahaan_id);
        $this->assertNull($package->rencana_kunjungan_id);
        $this->assertTrue($package->isItemActive('tinggi_badan'));
        $this->assertTrue($package->isItemActive('berat_badan'));
        $this->assertDatabaseCount('rencana_kunjungans', 0);
        $this->get(route('paket.index'))->assertOk()->assertSee('Master Standar');
        $this->get(route('paket.show', $package))->assertOk()->assertSee('Belum digunakan.');
    }

    public function test_one_master_can_be_bound_to_two_companies_without_duplication_and_registration_is_scoped(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->master()->create();
        $visits = RencanaKunjungan::factory()->count(2)->create(['kuota_peserta' => 0]);
        foreach ($visits as $visit) {
            $this->post(route('rencana-kunjungan.paket.bind', $visit), ['paket_id' => $package->id])->assertSessionHasNoErrors();
            $this->post(route('rencana-kunjungan.paket.bind', $visit), ['paket_id' => $package->id])->assertSessionHasNoErrors();
            $this->post(route('pasien.store'), ['nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id, 'nama_karyawan' => 'Peserta '.$visit->nomor_kunjungan, 'jenis_kelamin' => 'L'])->assertSessionHasNoErrors();
            $this->get(route('kunjungan-mcu.show', $visit))->assertOk()
                ->assertViewHas('kunjungan', fn ($data): bool => $data->pakets->first()->karyawans_count === 1);
        }
        $this->assertDatabaseCount('pakets', 1);
        $this->assertDatabaseCount('paket_rencana_kunjungan', 2);
        $this->assertSame(2, $package->karyawans()->count());
        $this->assertNotEquals($visits[0]->perusahaan_id, $visits[1]->perusahaan_id);
        $other = RencanaKunjungan::factory()->create();
        $this->post(route('peserta.store', $other), ['nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'paket_id' => $package->id, 'nama_karyawan' => 'Paket otomatis terikat', 'jenis_kelamin' => 'L'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('paket_rencana_kunjungan', ['paket_id' => $package->id, 'rencana_kunjungan_id' => $other->id]);
        $this->get(route('pasien.create', ['rencana_kunjungan_id' => $visits[1]->id]))->assertOk()->assertSee('Semua paket master dapat dipakai ulang.');
        foreach ($package->karyawans as $participant) {
            $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))->assertOk();
        }
    }

    public function test_binding_is_authorized_and_closed_visits_and_deleted_packages_are_rejected(): void
    {
        $package = Paket::factory()->master()->create();
        $visit = RencanaKunjungan::factory()->create();
        $url = route('rencana-kunjungan.paket.bind', $visit);
        $this->post($url, ['paket_id' => $package->id])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'petugas']))->post($url, ['paket_id' => $package->id])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit->update(['is_closing' => true]);
        $this->post($url, ['paket_id' => $package->id])->assertForbidden();
        $visit->update(['is_closing' => false]);
        $package->delete();
        $this->post($url, ['paket_id' => $package->id])->assertSessionHasErrors('paket_id');
        $this->assertDatabaseCount('paket_rencana_kunjungan', 0);
    }

    public function test_registration_lists_unbound_masters_and_binds_only_selected_package_without_duplicates(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $selected = Paket::factory()->master()->create(['nama_paket' => 'Paket Master A']);
        $unused = Paket::factory()->master()->create(['nama_paket' => 'Paket Master B']);
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $this->get(route('pasien.create'))->assertOk()->assertSee('Paket Master A')->assertSee('Paket Master B');
        $this->get(route('pasien.create', ['rencana_kunjungan_id' => $visit->id]))->assertOk()
            ->assertViewHas('pakets', fn ($packages): bool => $packages->count() === 2)
            ->assertSee('Paket Master A')->assertSee('Paket Master B')->assertDontSee('data-kunjungans=', false);
        $this->assertDatabaseCount('paket_rencana_kunjungan', 0);
        foreach (['Peserta pertama', 'Peserta kedua'] as $name) {
            $this->post(route('pasien.store'), [
                'rencana_kunjungan_id' => $visit->id, 'paket_id' => $selected->id,
                'nama_karyawan' => $name, 'jenis_kelamin' => 'P',
                'nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional',
            ])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('pakets', 2);
        $this->assertDatabaseCount('paket_rencana_kunjungan', 1);
        $this->assertSame(2, $selected->karyawans()->count());
        $this->assertSame(0, $unused->rencanaKunjungans()->count());
        $otherVisit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $this->post(route('pasien.store'), [
            'rencana_kunjungan_id' => $otherVisit->id, 'paket_id' => $selected->id,
            'nama_karyawan' => 'Peserta perusahaan lain', 'jenis_kelamin' => 'L',
            'nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('pakets', 2);
        $this->assertDatabaseCount('paket_rencana_kunjungan', 2);
        $this->assertSame(3, $selected->karyawans()->count());
        $this->assertDatabaseHas('karyawans', ['nama_karyawan' => 'Peserta perusahaan lain', 'perusahaan_id' => $otherVisit->perusahaan_id, 'paket_id' => $selected->id]);
    }

    public function test_failed_registration_does_not_bind_package_and_deleted_master_is_not_available(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->master()->create(['nama_paket' => 'Master tidak tersedia']);
        $visit = RencanaKunjungan::factory()->create(['is_closing' => true]);
        $payload = ['nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id, 'nama_karyawan' => 'Tidak tersimpan', 'jenis_kelamin' => 'L'];
        $this->post(route('pasien.store'), $payload)->assertSessionHasErrors('rencana_kunjungan_id');
        $this->post(route('peserta.store', $visit), $payload)->assertForbidden();
        $this->assertDatabaseCount('paket_rencana_kunjungan', 0);
        $visit->update(['is_closing' => false]);
        $package->delete();
        $this->get(route('pasien.create'))->assertOk()->assertDontSee('Master tidak tersedia');
        $this->post(route('pasien.store'), $payload)->assertSessionHasErrors('paket_id');
        $this->post(route('peserta.store', $visit), $payload)->assertSessionHasErrors('paket_id');
        $this->assertDatabaseCount('karyawans', 0);
        $this->assertDatabaseCount('paket_rencana_kunjungan', 0);
    }

    public function test_edit_can_choose_unbound_master_but_existing_exam_prevents_package_change_and_binding(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = Karyawan::factory()->create();
        $package = Paket::factory()->master()->create();
        $payload = ['nip' => 'EMP-001', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $package->id, 'nama_karyawan' => $participant->nama_karyawan, 'jenis_kelamin' => 'L'];
        $this->put(route('pasien.update', $participant), $payload)->assertSessionHasNoErrors();
        $this->assertSame($package->id, $participant->fresh()->paket_id);
        $this->assertDatabaseHas('paket_rencana_kunjungan', ['paket_id' => $package->id, 'rencana_kunjungan_id' => $participant->rencana_kunjungan_id]);
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'package_items' => ['hipertensi' => true]]);
        $replacement = Paket::factory()->master()->create();
        $payload['paket_id'] = $replacement->id;
        $this->put(route('pasien.update', $participant), $payload)->assertSessionHasErrors('paket_id');
        $this->assertSame(0, $replacement->rencanaKunjungans()->count());
        $this->assertSame(['hipertensi' => true], $exam->fresh()->package_items);
        $this->assertSame($package->id, $participant->fresh()->paket_id);
    }

    public function test_detaching_removes_only_the_selected_visit_and_blocks_packages_with_participants(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->master()->create();
        $visits = RencanaKunjungan::factory()->count(2)->create();
        $package->rencanaKunjungans()->attach($visits->modelKeys());
        $this->delete(route('rencana-kunjungan.paket.unbind', [$visits[0], $package]))->assertSessionHasNoErrors();
        $this->assertSame([$visits[1]->id], $package->rencanaKunjungans()->pluck('rencana_kunjungans.id')->all());
        Karyawan::factory()->create(['paket_id' => $package->id, 'rencana_kunjungan_id' => $visits[1]->id, 'perusahaan_id' => $visits[1]->perusahaan_id]);
        $this->delete(route('rencana-kunjungan.paket.unbind', [$visits[1], $package]))->assertSessionHasErrors('paket_id');
        $this->assertNotSoftDeleted($package);
        $this->assertDatabaseCount('paket_rencana_kunjungan', 1);
    }

    public function test_master_edit_keeps_bindings_and_existing_examinations_cannot_be_changed(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->master()->create();
        $visit = RencanaKunjungan::factory()->create();
        $visit->pakets()->attach($package);
        $this->put(route('paket.update', $package), ['nama_paket' => 'Master diperbarui', 'items' => ['rontgen']])->assertSessionHasNoErrors();
        $this->assertTrue($package->fresh()->isItemActive('rontgen'));
        $this->assertSame(1, $package->rencanaKunjungans()->count());
        $participant = Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id, 'rencana_kunjungan_id' => $visit->id, 'perusahaan_id' => $visit->perusahaan_id]);
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $visit->id, 'status' => 'final', 'package_items' => ['rontgen' => true]]);
        $this->put(route('paket.update', $package), ['nama_paket' => 'Tidak boleh', 'items' => ['hipertensi']])->assertSessionHasErrors('paket');
        $this->assertSame(['rontgen' => true], $exam->fresh()->package_items);
        $this->assertSame('Master diperbarui', $package->fresh()->nama_paket);
    }
}
