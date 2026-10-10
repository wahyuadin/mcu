<?php

namespace Tests\Feature;

use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PemeriksaanOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_is_the_default_entry_and_explicit_anamnesa_still_opens_the_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $url = route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]);
        $this->get($url)->assertOk()->assertViewHas('tab', 'ringkasan')->assertSee('Daftar pemeriksaan')
            ->assertSee('Tahapan pemeriksaan')->assertSee('Perlu dilengkapi')->assertSee('Dokter penanggung jawab')
            ->assertSee('1 dari 2 bagian')->assertDontSee('name="section"', false);
        $this->get($url.'?tab=anamnesa')->assertOk()->assertViewHas('tab', 'anamnesa')
            ->assertSee('anamnesa[hipertensi][answer]', false)->assertSee('Ringkasan pemeriksaan');
        $this->get(route('kunjungan-mcu.show', $participant->rencanaKunjungan))->assertOk()
            ->assertSee('href="'.$url.'"', false);
        $this->get($url.'?tab=unknown')->assertOk()->assertViewHas('tab', 'ringkasan');
    }

    public function test_summary_lists_identity_first_and_counts_it_in_result_completeness(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'ringkasan']))
            ->assertOk()->assertSeeInOrder(['data-examination="identitas"', 'data-examination="anamnesa"'], false)
            ->assertSee('Isi / lihat Identitas MCU')->assertSee('Selesai diisi')
            ->assertSee('1 dari 2 bagian')->assertViewHas('completionPercent', 50)
            ->assertViewHas('examinations', fn (array $items): bool => count($items) === 2 && $items['identitas']['status'] === 'Selesai');
    }

    public function test_summary_does_not_label_incomplete_identity_as_complete(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $participant->update(['nip' => null]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'ringkasan']))
            ->assertOk()->assertSee('data-examination="identitas"', false)->assertSee('Isi / lihat Identitas MCU')
            ->assertSee('0 dari 2 bagian')->assertViewHas('completionPercent', 0)
            ->assertViewHas('examinations', fn (array $items): bool => $items['identitas']['status'] === 'Draft')
            ->assertSee('Identitas MCU perlu dilengkapi');
    }

    public function test_missing_photo_is_not_counted_as_completed_identity_in_historical_summary(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        Storage::disk('local')->delete($participant->foto);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))
            ->assertOk()->assertSee('0 dari 2 bagian')->assertViewHas('completionPercent', 0)
            ->assertViewHas('examinations', fn (array $items): bool => $items['identitas']['status'] === 'Draft');
    }

    public function test_identity_is_counted_even_when_package_has_no_medical_examinations(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant([]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))
            ->assertOk()->assertSee('1 dari 1 bagian')->assertViewHas('completionPercent', 100)
            ->assertViewHas('examinations', fn (array $items): bool => array_keys($items) === ['identitas'])
            ->assertSee('Finalisasi terkunci');
    }

    public function test_full_package_overview_has_eight_rows_including_identity_and_real_item_counts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $keys = array_keys(collect(Paket::getDefaultItemCatalog())->collapse()->all());
        $participant = $this->participant($keys);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))->assertOk()
            ->assertSee('1 dari 8 bagian')->assertViewHas('examinations', function (array $items): bool {
                return count($items) === 8 && isset($items['identitas'], $items['rontgen'], $items['ekg'], $items['audiometri'], $items['spirometri']);
            })->assertSee('tab=penunjang#rontgen', false)->assertSee('Finalisasi terkunci');
    }

    public function test_progress_distinguishes_completed_drafts_and_incomplete_supporting_tests(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi', 'lab_kolesterol', 'rontgen', 'ekg']);
        $doctor = Dokter::factory()->create();
        $participant->anamnesa()->create([
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id,
            'anamnesa_data' => ['hipertensi' => ['answer' => 'Tidak']],
            'laboratorium' => ['lab_kolesterol' => ['result' => '228']],
            'penunjang' => ['rontgen' => ['kesimpulan' => 'Normal Chest', 'doctor_id' => $doctor->id]],
            'section_status' => ['anamnesa' => 'selesai', 'laboratorium' => 'draft', 'penunjang' => 'draft'],
        ]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))->assertOk()
            ->assertSee('2 dari 5 bagian')->assertViewHas('completionPercent', 40)
            ->assertViewHas('examinations', fn (array $items): bool => $items['anamnesa']['status'] === 'Selesai'
                && $items['laboratorium']['status'] === 'Draft' && $items['rontgen']['status'] === 'Draft'
                && $items['ekg']['status'] === 'Belum diisi');
    }

    public function test_completed_skipped_supporting_results_are_labelled_without_becoming_normal(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['rontgen', 'ekg']);
        $doctor = Dokter::factory()->create();
        $participant->anamnesa()->create([
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id,
            'penunjang' => [
                'rontgen' => ['skipped' => true, 'reason' => 'Alat tidak tersedia'],
                'ekg' => ['irama' => 'Sinus', 'heart_rate' => '70', 'axis' => 'Normal', 'kelainan' => 'Tidak ada', 'kesimpulan' => 'Normal', 'doctor_id' => $doctor->id],
            ],
            'section_status' => ['penunjang' => 'selesai'],
        ]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))->assertOk()
            ->assertSee('3 dari 3 bagian')->assertSee('Tidak dilakukan — alasan tercatat')
            ->assertViewHas('examinations', fn (array $items): bool => $items['rontgen']['skipped'] && $items['rontgen']['status'] === 'Selesai');
    }

    public function test_final_results_show_readonly_summary_and_report_link(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))->assertOk()
            ->assertSee('Final · terkunci')->assertSee('Hasil terkunci')->assertDontSee('Lengkapi anamnesis')
            ->assertSee(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]), false);
    }

    public function test_complete_results_link_to_review_instead_of_finalizing_without_confirmation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $doctor = Dokter::factory()->create();
        $exam = $participant->anamnesa()->create([
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id,
            'anamnesa_data' => ['hipertensi' => ['answer' => 'Tidak']],
            'section_status' => ['anamnesa' => 'selesai', 'kesimpulan' => 'selesai'],
            'dokter_id' => $doctor->id, 'status_kelaikan' => 'Fit to Work',
            'catatan_dokter' => 'Sehat', 'anjuran_saran' => 'Olahraga rutin',
        ]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]))->assertOk()
            ->assertSee('Lanjut ke finalisasi')->assertDontSee('Finalisasi terkunci');
        $this->assertNotSame('final', $exam->fresh()->status);
    }

    /** @param list<string> $keys */
    private function participant(array $keys): Karyawan
    {
        $package = Paket::factory()->create(['active_items' => array_fill_keys($keys, true)]);

        return Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id]);
    }
}
