<?php

namespace Tests\Feature;

use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_defaults_to_yes_and_accepts_no_but_rejects_invalid_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('mcu-category.create'))->assertOk()->assertViewHas('category', fn (McuCategory $category): bool => $category->has_responsible_doctor);
        $this->post(route('mcu-category.store'), ['name' => 'Thorax', 'is_active' => 1])->assertSessionHasNoErrors();
        $category = McuCategory::where('name', 'Thorax')->firstOrFail();
        $this->assertTrue($category->has_responsible_doctor);
        $this->put(route('mcu-category.update', $category), ['name' => 'Thorax', 'is_active' => 1, 'has_responsible_doctor' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($category->fresh()->has_responsible_doctor);
        $this->get(route('mcu-category.edit', $category))->assertOk()->assertSee('Dokter penanggung jawab')->assertViewHas('category', fn (McuCategory $category): bool => ! $category->has_responsible_doctor);
        $this->put(route('mcu-category.update', $category), ['name' => 'Thorax', 'is_active' => 1, 'has_responsible_doctor' => 'invalid'])->assertSessionHasErrors('has_responsible_doctor');
        $this->assertFalse($category->fresh()->has_responsible_doctor);
    }

    public function test_package_snapshots_preserve_setting_despite_master_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create(['has_responsible_doctor' => false]);
        $item = McuItem::factory()->create(['mcu_category_id' => $category->id]);
        $this->post(route('paket.store'), ['nama_paket' => 'Paket dokter', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $package = Paket::where('nama_paket', 'Paket dokter')->firstOrFail();
        $this->assertFalse($package->dynamic_schema[0]['has_responsible_doctor']);
        $category->update(['has_responsible_doctor' => true]);
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$category->id]))->assertOk()->assertDontSee('name="category_doctors['.$category->id.']"', false);
    }

    public function test_category_doctors_are_optional_isolated_and_preserved_when_not_submitted(): void
    {
        [$participant, $first, $second] = $this->participant();
        $doctor = Dokter::factory()->create(['is_active' => true]);
        $otherDoctor = Dokter::factory()->create(['is_active' => true]);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$first->mcu_category_id]))->assertOk()->assertSee('Dokter Pemeriksa (opsional)')->assertSee('name="category_doctors['.$first->mcu_category_id.']"', false);
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$first->id => 'Normal']])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'selesai', 'lock_version' => 1, 'category_doctors' => [$first->mcu_category_id => $doctor->id]])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'kategori-'.$second->mcu_category_id, 'status' => 'draft', 'lock_version' => 2, 'category_doctors' => [$second->mcu_category_id => $otherDoctor->id]])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'draft', 'lock_version' => 3, 'dynamic_answers' => [$first->id => 'Koreksi']])->assertSessionHasNoErrors();
        $this->assertSame([$first->mcu_category_id => $doctor->id, $second->mcu_category_id => $otherDoctor->id], $participant->fresh()->anamnesa->category_doctors);
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'draft', 'lock_version' => 4, 'category_doctors' => [$first->mcu_category_id => null]])->assertSessionHasNoErrors();
        $this->assertSame([$first->mcu_category_id => null, $second->mcu_category_id => $otherDoctor->id], $participant->fresh()->anamnesa->category_doctors);
    }

    public function test_disabled_foreign_invalid_and_new_inactive_doctors_are_rejected(): void
    {
        [$participant, $first, $second] = $this->participant(false);
        $doctor = Dokter::factory()->create(['is_active' => true]);
        $inactive = Dokter::factory()->create(['is_active' => false]);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$first->mcu_category_id]))->assertOk()->assertDontSee('name="category_doctors['.$first->mcu_category_id.']"', false);
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'draft', 'lock_version' => 0, 'category_doctors' => [$first->mcu_category_id => $doctor->id]])->assertSessionHasErrors('category_doctors');
        $this->put($url, ['section' => 'kategori-'.$second->mcu_category_id, 'status' => 'draft', 'lock_version' => 0, 'category_doctors' => [$first->mcu_category_id => $doctor->id]])->assertSessionHasErrors('category_doctors');
        foreach ([$inactive->id, 'missing-id'] as $id) {
            $this->put($url, ['section' => 'kategori-'.$second->mcu_category_id, 'status' => 'draft', 'lock_version' => 0, 'category_doctors' => [$second->mcu_category_id => $id]])->assertSessionHasErrors('category_doctors.'.$second->mcu_category_id);
        }
        $this->put($url, ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0, 'category_doctors' => [$second->mcu_category_id => $doctor->id]])->assertSessionHasErrors('category_doctors');
        $this->assertDatabaseCount('anamnesas', 0);
    }

    public function test_legacy_snapshots_default_to_yes_and_existing_inactive_doctor_remains_selectable(): void
    {
        [$participant, $first] = $this->participant();
        $schema = $participant->paket->dynamic_schema;
        unset($schema[0]['has_responsible_doctor']);
        $participant->paket->update(['dynamic_schema' => $schema]);
        $doctor = Dokter::factory()->create(['is_active' => true]);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'draft', 'lock_version' => 0, 'category_doctors' => [$first->mcu_category_id => $doctor->id]])->assertSessionHasNoErrors();
        $doctor->update(['is_active' => false]);
        $first->category->update(['has_responsible_doctor' => false]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'kategori-'.$first->mcu_category_id]))->assertOk()->assertSee('name="category_doctors['.$first->mcu_category_id.']"', false)->assertSee($doctor->nama_dokter);
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'draft', 'lock_version' => 1, 'category_doctors' => [$first->mcu_category_id => $doctor->id]])->assertSessionHasNoErrors();
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'draft', 'lock_version' => 1, 'category_doctors' => [$first->mcu_category_id => null]])->assertSessionHasErrors('pemeriksaan');
        $this->assertSame($doctor->id, $participant->fresh()->anamnesa->category_doctors[$first->mcu_category_id]);
        $participant->anamnesa->update(['status' => 'final']);
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'draft', 'lock_version' => 2, 'category_doctors' => [$first->mcu_category_id => null]])->assertForbidden();
        $this->post(route('pemeriksaan.revise', [$participant->rencanaKunjungan, $participant]), ['reason' => 'Koreksi dokter kategori'])->assertSessionHasNoErrors();
        $exam = $participant->fresh()->anamnesa;
        $this->assertSame($doctor->id, $exam->revisions[0]['snapshot']['category_doctors'][$first->mcu_category_id]);
        $this->put($url, ['section' => 'kategori-'.$first->mcu_category_id, 'status' => 'draft', 'lock_version' => $exam->lock_version, 'category_doctors' => [$first->mcu_category_id => null]])->assertSessionHasNoErrors();
        $this->assertSame($doctor->id, $exam->fresh()->revisions[0]['snapshot']['category_doctors'][$first->mcu_category_id]);
    }

    /** @return array{Karyawan, McuItem, McuItem} */
    private function participant(bool $enabled = true): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = McuItem::factory()->create(['input_type' => 'text']);
        $second = McuItem::factory()->create(['input_type' => 'text']);
        $package = Paket::factory()->master()->create(['active_items' => [], 'dynamic_schema' => [
            ['id' => $first->mcu_category_id, 'name' => 'Thorax', 'order' => 1, 'has_responsible_doctor' => $enabled, 'items' => [$first->definition()]],
            ['id' => $second->mcu_category_id, 'name' => 'EKG', 'order' => 2, 'has_responsible_doctor' => true, 'items' => [$second->definition()]],
        ]]);
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);

        return [$participant->fresh(), $first, $second];
    }
}
