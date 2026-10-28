<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\McuSubcategory;
use App\Models\Paket;
use App\Models\Pasien;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WorkspaceEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_template_copies_only_available_items_and_preserves_the_source(): void
    {
        $active = McuItem::factory()->create(['label' => 'Pemeriksaan aktif']);
        $inactive = McuItem::factory()->create(['label' => 'Pemeriksaan nonaktif', 'is_active' => false]);
        $source = Paket::factory()->create(['nama_paket' => 'Paket Sumber', 'deskripsi' => 'Deskripsi sumber', 'active_items' => ['bmi' => true], 'dynamic_schema' => [
            ['id' => $active->mcu_category_id, 'name' => $active->category->name, 'order' => 0, 'items' => [$active->definition()]],
            ['id' => $inactive->mcu_category_id, 'name' => $inactive->category->name, 'order' => 1, 'items' => [$inactive->definition()]],
        ]]);
        $snapshot = $source->dynamic_schema;
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('paket.create'))->assertOk()->assertSee('Salin dari paket')->assertSee('Gunakan sebagai template')->assertSee($source->nama_paket);
        $response = $this->getJson(route('paket.template', $source))->assertOk()
            ->assertJsonPath('nama_paket', 'Salinan Paket Sumber')->assertJsonPath('deskripsi', 'Deskripsi sumber')
            ->assertJsonPath('master_items', [$active->id])->assertJsonPath('items', ['bmi'])->assertJsonPath('unavailable', ['Pemeriksaan nonaktif']);
        $this->post(route('paket.store'), $response->json())->assertSessionHasNoErrors();
        $copy = Paket::where('nama_paket', 'Salinan Paket Sumber')->firstOrFail();
        $this->assertNull($copy->rencana_kunjungan_id);
        $this->assertSame(0, $copy->rencanaKunjungans()->count());
        $this->assertSame(0, $copy->karyawans()->count());
        $this->assertSame($active->id, $copy->dynamic_schema[0]['items'][0]['id']);
        $this->assertSame($snapshot, $source->fresh()->dynamic_schema);
        $this->assertSame('Paket Sumber', $source->fresh()->nama_paket);
    }

    public function test_template_excludes_items_in_inactive_categories_and_subcategories(): void
    {
        $subcategory = McuSubcategory::factory()->create(['is_active' => false]);
        $item = McuItem::factory()->create(['mcu_category_id' => $subcategory->mcu_category_id, 'mcu_subcategory_id' => $subcategory->id]);
        $inactiveCategory = McuCategory::factory()->create(['is_active' => false]);
        $other = McuItem::factory()->create(['mcu_category_id' => $inactiveCategory->id]);
        $source = Paket::factory()->master()->create(['active_items' => [], 'dynamic_schema' => [['items' => [$item->definition(), $other->definition()]]]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson(route('paket.template', $source))->assertOk()
            ->assertJsonPath('master_items', [])->assertJsonCount(1, 'unavailable');
    }

    public function test_planning_edit_updates_all_registration_fields_without_changing_quota(): void
    {
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 99]);
        $company = Perusahaan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('rencana-kunjungan.index'))->assertOk()->assertSee('data-planning-edit', false)->assertSee('data-planning-filter', false)->assertSee('data-loading-skeleton', false);
        $this->putJson(route('rencana-kunjungan.update', $visit), [
            'perusahaan_id' => $company->id, 'nomor_kunjungan' => 'PKS-EDIT-001', 'periode_kunjungan' => 'Oktober 2026',
            'tgl_mulai' => '2026-10-28', 'tgl_selesai' => '2026-10-30', 'jumlah_peserta_direncanakan' => 50,
        ])->assertOk()->assertJsonPath('message', 'Rencana kunjungan diperbarui.');
        $this->assertDatabaseHas('rencana_kunjungans', ['id' => $visit->id, 'perusahaan_id' => $company->id, 'nomor_kunjungan' => 'PKS-EDIT-001', 'periode_kunjungan' => 'Oktober 2026', 'jumlah_peserta_direncanakan' => 50, 'kuota_peserta' => 99]);
        $this->assertSame('2026-10-30', $visit->fresh()->tgl_selesai->format('Y-m-d'));
    }

    public function test_planning_edit_rejects_duplicate_number_invalid_dates_and_company_change_with_participants(): void
    {
        $participant = Karyawan::factory()->create();
        $visit = $participant->rencanaKunjungan;
        $other = RencanaKunjungan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['perusahaan_id' => $visit->perusahaan_id, 'nomor_kunjungan' => $visit->nomor_kunjungan, 'periode_kunjungan' => 'Oktober 2026', 'tgl_mulai' => '2026-10-28', 'tgl_selesai' => '2026-10-30', 'jumlah_peserta_direncanakan' => 5];
        $this->putJson(route('rencana-kunjungan.update', $visit), [...$data, 'nomor_kunjungan' => $other->nomor_kunjungan])->assertUnprocessable()->assertJsonValidationErrors('nomor_kunjungan');
        $this->putJson(route('rencana-kunjungan.update', $visit), [...$data, 'tgl_selesai' => '2026-10-27'])->assertUnprocessable()->assertJsonValidationErrors('tgl_selesai');
        $this->putJson(route('rencana-kunjungan.update', $visit), [...$data, 'perusahaan_id' => $other->perusahaan_id])->assertUnprocessable()->assertJsonValidationErrors('perusahaan_id');
        $this->putJson(route('rencana-kunjungan.update', $visit), $data)->assertOk();
        $visit->update(['is_closing' => true]);
        $this->putJson(route('rencana-kunjungan.update', $visit), $data)->assertForbidden();
        $this->assertSame($participant->perusahaan_id, $visit->fresh()->perusahaan_id);
    }

    public function test_item_order_response_includes_subcategory_filters_and_keeps_all_items(): void
    {
        $subcategory = McuSubcategory::factory()->create();
        $item = McuItem::factory()->create(['mcu_category_id' => $subcategory->mcu_category_id, 'mcu_subcategory_id' => $subcategory->id, 'sort_order' => 0]);
        $ungrouped = McuItem::factory()->create(['mcu_category_id' => $subcategory->mcu_category_id, 'sort_order' => 1]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('mcu-item.index'))->assertOk()->assertSee('Semua subkategori')->assertSee('data-item-order-subcategory', false);
        $this->getJson(route('mcu-item-order.show', $subcategory->category))->assertOk()->assertJsonPath('subcategories.0.id', $subcategory->id)
            ->assertJsonPath('items.0.id', $item->id)->assertJsonPath('items.0.subcategory_id', $subcategory->id)
            ->assertJsonPath('items.1.id', $ungrouped->id)->assertJsonPath('items.1.subcategory_id', null);
    }

    public function test_patients_without_visits_are_visible_editable_and_deletable(): void
    {
        $patient = Pasien::factory()->create(['nama' => '<b>Pasien mandiri</b>']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('pasien.index'))->assertOk()->assertSee($patient->nama)->assertDontSee('<b>Pasien mandiri</b>', false)
            ->assertSee(route('pasien.identity.edit', $patient), false)->assertSee('data-patient-delete', false)->assertSee('Hapus pasien?');
        $this->get(route('pasien.identity.edit', $patient))->assertOk()->assertSee('Edit identitas pasien');
        $this->put(route('pasien.identity.update', $patient), ['nama' => 'Identitas diperbarui', 'nik' => '0012345678901234', 'jenis_kelamin' => 'P', 'tanggal_lahir' => '1990-01-01'])->assertRedirect(route('pasien.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pasiens', ['id' => $patient->id, 'nama' => 'Identitas diperbarui', 'nik' => '0012345678901234']);
        $this->delete(route('pasien.identity.destroy', $patient))->assertRedirect(route('pasien.index'));
        $this->assertSoftDeleted($patient);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_patient_with_a_visit_cannot_be_deleted_even_if_the_episode_is_archived(bool $archived): void
    {
        $episode = Karyawan::factory()->create();
        $patient = $episode->pasien;
        if ($archived) {
            $episode->delete();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $html = $this->get(route('pasien.index'))->assertOk()->assertSee('Pasien sudah memiliki kunjungan.')->getContent();
        $this->assertMatchesRegularExpression('/data-patient-delete\b[^>]*\bdisabled\b/', $html);
        $this->delete(route('pasien.identity.destroy', $patient))->assertSessionHasErrors(['pasien' => 'Pasien sudah memiliki kunjungan.']);
        $this->assertNotSoftDeleted($patient);
    }

    public function test_legacy_episode_delete_endpoint_also_refuses_a_registered_patient_without_results(): void
    {
        $episode = Karyawan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->delete(route('pasien.destroy', $episode))->assertSessionHasErrors('pasien');
        $this->assertNotSoftDeleted($episode);
    }

    public function test_new_endpoints_require_authentication_and_administrative_access(): void
    {
        $patient = Pasien::factory()->create();
        $visit = RencanaKunjungan::factory()->create();
        $package = Paket::factory()->master()->create();
        $this->get(route('paket.template', $package))->assertRedirect(route('login'));
        $this->get(route('pasien.identity.edit', $patient))->assertRedirect(route('login'));
        $this->put(route('rencana-kunjungan.update', $visit), [])->assertRedirect(route('login'));
        $this->delete(route('pasien.identity.destroy', $patient))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->getJson(route('paket.template', $package))->assertForbidden();
        $this->get(route('pasien.identity.edit', $patient))->assertForbidden();
        $this->put(route('pasien.identity.update', $patient), [])->assertForbidden();
        $this->putJson(route('rencana-kunjungan.update', $visit), [])->assertForbidden();
        $this->delete(route('pasien.identity.destroy', $patient))->assertForbidden();

        $this->assertNotSoftDeleted($patient);
    }
}
