<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\McuItem;
use App\Models\Perusahaan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HrdReadOnlyExaminationTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['ringkasan'])]
    #[TestWith(['identitas'])]
    #[TestWith(['anamnesa'])]
    #[TestWith(['fisik'])]
    #[TestWith(['laboratorium'])]
    #[TestWith(['penunjang'])]
    #[TestWith(['kesimpulan'])]
    #[TestWith(['review'])]
    public function test_hrd_can_open_each_released_examination_tab_as_read_only(string $tab): void
    {
        $participant = $this->releasedParticipant();
        $participant->anamnesa->update(['revisions' => [['at' => '2026-10-01', 'operator' => 'Operator rahasia', 'reason' => 'Alasan internal', 'snapshot' => ['catatan_dokter' => 'Riwayat yang belum dirilis']]]]);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $before = $participant->anamnesa->getAttributes();

        $this->actingAs($hrd)->get(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => $tab]))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertViewHas('readonly', true)->assertViewHas('hrdReadonly', true)
            ->assertSee('Hanya baca')->assertSee($participant->nama_karyawan)->assertDontSee('data-exam-form', false)->assertDontSee('name="images[', false)->assertDontSee('name="dynamic_files[', false)->assertDontSee('name="foto_pasien"', false)
            ->assertDontSee('Buka revisi hasil')->assertDontSee('Simpan draft')->assertDontSee('Riwayat yang belum dirilis')->assertDontSee('data-label-open', false);

        $this->assertSame($before, $participant->anamnesa->fresh()->getAttributes());
    }

    public function test_participant_links_and_back_navigation_preserve_dashboard_and_scope(): void
    {
        $participant = $this->releasedParticipant();
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]));
        $url = route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant]);
        $this->getJson(route('hrd.data'))->assertOk()->assertJsonPath('participants.0.examination_url', $url);
        $context = ['from_dashboard' => 1, 'dashboard' => 'default', 'company' => $participant->perusahaan_id, 'visit' => $participant->rencana_kunjungan_id, 'visit_status' => 'draft'];

        $response = $this->get(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, ...$context]))->assertOk();

        $response->assertSee(route('dashboard', array_diff_key($context, ['from_dashboard' => true])).'#hrd-participants');
        $response->assertSee(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, ...$context, 'tab' => 'kesimpulan']));
    }

    #[TestWith(['belum', false, 'draft'])]
    #[TestWith(['selesai', false, 'final'])]
    #[TestWith(['selesai', true, 'draft'])]
    #[TestWith(['proses', true, 'final'])]
    public function test_hrd_cannot_view_an_unreleased_or_inconsistent_examination(string $status, bool $closed, string $examStatus): void
    {
        $participant = Karyawan::factory()->create(['status_pemeriksaan' => $status, 'is_closing' => $closed]);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => $examStatus]);
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]));

        $this->get(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant]))->assertNotFound();
        $this->get(route('pemeriksaan.report', [$participant->rencana_kunjungan_id, $participant]))->assertNotFound();
        $this->get(route('pemeriksaan.attachment', [$participant->rencana_kunjungan_id, $participant, 'foto']))->assertNotFound();
        $this->getJson(route('hrd.data'))->assertOk()->assertJsonPath('participants.0.examination_url', null);
    }

    public function test_hrd_access_is_episode_scoped_and_reassignment_revokes_existing_links(): void
    {
        $own = $this->releasedParticipant();
        $foreign = $this->releasedParticipant();
        $foreign->update(['pasien_id' => $own->pasien_id]);
        $own->pasien->update(['nama' => 'Nama dari episode perusahaan lain']);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $own->perusahaan_id]);
        $this->actingAs($hrd)->get(route('pemeriksaan.edit', [$own->rencana_kunjungan_id, $own]))->assertOk()->assertDontSee('Nama dari episode perusahaan lain');
        $this->get(route('pemeriksaan.edit', [$foreign->rencana_kunjungan_id, $foreign]))->assertNotFound();
        $this->get(route('pemeriksaan.edit', [$foreign->rencana_kunjungan_id, $own]))->assertNotFound();
        $hrd->update(['perusahaan_id' => Perusahaan::factory()->create()->id]);

        $this->actingAs($hrd)->get(route('pemeriksaan.edit', [$own->rencana_kunjungan_id, $own]))->assertNotFound();
    }

    public function test_released_photos_and_reports_are_private_and_revision_attachments_remain_internal(): void
    {
        $participant = $this->releasedParticipant();
        $photo = 'mcu/'.$participant->id.'/foto.jpg';
        Storage::disk('local')->put($photo, 'released-photo');
        $participant->update(['foto' => $photo]);
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]));

        $this->get(route('pemeriksaan.attachment', [$participant->rencana_kunjungan_id, $participant, 'foto']))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('pemeriksaan.report', [$participant->rencana_kunjungan_id, $participant]))->assertOk()->assertSee('Hasil dirilis')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('pemeriksaan.attachment', [$participant->rencana_kunjungan_id, $participant, 'foto', 'version' => 1]))->assertNotFound();
    }

    public function test_hrd_does_not_gain_mutation_print_label_or_internal_patient_permissions(): void
    {
        $participant = $this->releasedParticipant();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $this->actingAs($hrd);
        $before = $participant->anamnesa->getAttributes();

        $this->put(route('pemeriksaan.update', [$participant->rencana_kunjungan_id, $participant]), ['status' => 'draft'])->assertForbidden();
        $this->post(route('pemeriksaan.revise', [$participant->rencana_kunjungan_id, $participant]), ['reason' => 'Ditolak'])->assertForbidden();
        $this->post(route('pemeriksaan.finalize', [$participant->rencana_kunjungan_id, $participant]))->assertForbidden();
        $this->post(route('pemeriksaan.photo', [$participant->rencana_kunjungan_id, $participant]))->assertForbidden();
        $this->post(route('peserta.release', [$participant->rencana_kunjungan_id, $participant]))->assertForbidden();
        $this->get(route('pemeriksaan.checkpoint', [$participant->rencana_kunjungan_id, $participant]))->assertForbidden();
        $this->get(route('pemeriksaan.labels', [$participant->rencana_kunjungan_id, $participant]))->assertForbidden();
        $this->get(route('pasien.index'))->assertForbidden();

        $this->assertFalse(Gate::forUser($hrd)->allows('examine-mcu'));
        $this->assertFalse(Gate::forUser($hrd)->allows('view-patients'));
        $this->assertSame($before, $participant->anamnesa->fresh()->getAttributes());
    }

    public function test_dynamic_results_use_the_released_snapshot_and_current_private_attachments_only(): void
    {
        $participant = $this->releasedParticipant();
        $item = McuItem::factory()->create(['label' => 'Pertanyaan historis', 'input_type' => 'text', 'allow_attachment' => true]);
        $schema = [['id' => $item->category->id, 'name' => 'Kategori historis', 'items' => [$item->definition()]]];
        $path = 'mcu/'.$participant->id.'/dynamic/hasil.pdf';
        Storage::disk('local')->put($path, '%PDF-hasil-final');
        $participant->anamnesa->update(['dynamic_schema' => $schema, 'dynamic_answers' => [$item->id => 'Jawaban final snapshot'],
            'dynamic_attachments' => [$item->id => ['path' => $path, 'mime' => 'application/pdf']]]);
        $item->update(['label' => 'Master diubah setelah rilis']);
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]));

        $this->get(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'kategori-'.$item->category->id]))->assertOk()
            ->assertSee('Pertanyaan historis')->assertSee('Jawaban final snapshot')->assertSee('Unduh PDF tersimpan')->assertDontSee('Master diubah setelah rilis')->assertDontSee('name="dynamic_files[', false);
        $this->get(route('pemeriksaan.attachment', [$participant->rencana_kunjungan_id, $participant, 'dynamic-'.$item->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('pemeriksaan.attachment', [$participant->rencana_kunjungan_id, $participant, 'dynamic-'.$item->id, 'version' => 1]))->assertNotFound();
    }

    private function releasedParticipant(): Karyawan
    {
        $participant = Karyawan::factory()->create(['status_pemeriksaan' => 'selesai', 'is_closing' => true]);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => ['hipertensi' => true, 'tinggi_badan' => true, 'haemoglobin' => true, 'rontgen' => true],
            'dynamic_schema' => [], 'catatan_dokter' => 'Hasil dirilis', 'status_kelaikan' => 'Fit to Work']);

        return $participant;
    }
}
