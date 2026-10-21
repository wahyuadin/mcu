<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ExaminationNavigationTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['ringkasan'])]
    #[TestWith(['identitas'])]
    #[TestWith(['anamnesa'])]
    #[TestWith(['fisik'])]
    #[TestWith(['kesimpulan'])]
    #[TestWith(['review'])]
    public function test_tabs_support_direct_navigation_and_return_a_replaceable_examination_region(string $tab): void
    {
        $participant = Karyawan::factory()->withPhoto()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $tab]))->assertOk()
            ->assertViewHas('tab', $tab)->assertSee('data-examination-page data-readonly="0"', false)
            ->assertSee(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_navigation_does_not_bypass_the_photo_requirement(): void
    {
        $participant = Karyawan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'petugas']));

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'anamnesa']))->assertOk()
            ->assertViewIs('mcu.pemeriksaan-foto')->assertSee('data-examination-page', false)
            ->assertDontSee('data-exam-form', false);
    }

    public function test_final_results_remain_read_only_during_tab_navigation(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']))->assertOk()
            ->assertSee('data-examination-page data-readonly="1"', false)
            ->assertDontSee('data-photo-modal-open', false)->assertDontSee('data-patient-identity', false);
    }
}
