<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HrdWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_hrd_is_company_scoped_and_does_not_gain_internal_patient_access(): void
    {
        $participant = Karyawan::factory()->create(['nama_karyawan' => 'Perusahaan sendiri']);
        $foreign = Karyawan::factory()->create(['nama_karyawan' => 'Rahasia perusahaan lain']);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $this->actingAs($hrd)->get(route('dashboard'))->assertRedirect(route('hrd.index'));
        $this->get(route('hrd.index'))->assertOk()->assertSee('Pembaruan otomatis setiap 4 detik')->assertDontSee('Rahasia perusahaan lain');
        $this->getJson(route('hrd.data'))->assertOk()->assertJsonPath('stats.registered', 1)->assertJsonPath('participants.0.name', 'Perusahaan sendiri')->assertDontSee('Rahasia perusahaan lain');
        $this->getJson(route('hrd.data', ['company' => $foreign->perusahaan_id]))->assertNotFound();
        $this->getJson(route('hrd.data', ['visit' => $foreign->rencana_kunjungan_id]))->assertNotFound();
        $this->getJson(route('hrd.data', ['package' => $foreign->paket_id]))->assertNotFound();
        $this->get(route('pasien.index'))->assertForbidden();
        $this->get(route('pemeriksaan.report', [$participant->rencana_kunjungan_id, $participant]))->assertForbidden();
        $this->putJson(route('hrd.layout'), ['widgets' => []])->assertForbidden();
    }

    public function test_unassigned_hrd_and_operator_cannot_access_workspace(): void
    {
        $this->getJson(route('hrd.data'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'hrd']))->get(route('hrd.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'petugas']))->get(route('hrd.index'))->assertForbidden();
    }

    public function test_admin_can_assign_hrd_company_but_self_profile_cannot_change_it(): void
    {
        $company = Perusahaan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['name' => 'HRD', 'email' => 'hrd@example.test', 'role' => 'hrd', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'];
        $this->post(route('users.store'), $data)->assertSessionHasErrors('perusahaan_id');
        $this->post(route('users.store'), $data + ['perusahaan_id' => $company->id])->assertRedirect(route('users.index'));
        $hrd = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame($company->id, $hrd->perusahaan_id);
        $this->actingAs($hrd)->patchJson(route('profile.update'), ['name' => 'HRD', 'email' => $hrd->email, 'perusahaan_id' => Perusahaan::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('perusahaan_id');
        $this->assertSame($company->id, $hrd->fresh()->perusahaan_id);
    }

    public function test_releasing_one_final_participant_does_not_close_visit_and_blocks_revision(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create(['status_pemeriksaan' => 'selesai']);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = route('peserta.release', [$participant->rencana_kunjungan_id, $participant]);
        $this->post($url, [])->assertSessionHasErrors('confirmed');
        $this->post($url, ['confirmed' => 1, 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->assertTrue($participant->fresh()->is_closing);
        $this->assertFalse($participant->rencanaKunjungan->fresh()->is_closing);
        $this->post(route('pemeriksaan.revise', [$participant->rencana_kunjungan_id, $participant]), ['reason' => 'Koreksi setelah rilis'])->assertForbidden();
        $this->put(route('pemeriksaan.update', [$participant->rencana_kunjungan_id, $participant]), ['status' => 'draft'])->assertForbidden();
        $this->get(route('pasien.edit', $participant))->assertForbidden();
        $this->post($url, ['confirmed' => 1, 'lock_version' => 0])->assertSessionHasNoErrors();
    }

    public function test_draft_release_and_hrd_release_are_rejected(): void
    {
        $participant = Karyawan::factory()->create();
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'draft']);
        $url = route('peserta.release', [$participant->rencana_kunjungan_id, $participant]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url, ['confirmed' => 1, 'lock_version' => 0])->assertSessionHasErrors('release');
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]))->post($url, ['confirmed' => 1])->assertForbidden();
        $this->assertFalse($participant->fresh()->is_closing);
    }

    public function test_completed_visit_does_not_release_but_closed_visit_releases_final_participants(): void
    {
        $participant = Karyawan::factory()->create(['status_pemeriksaan' => 'selesai']);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = route('rencana-kunjungan.status', $participant->rencana_kunjungan_id);
        $this->post($url, ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertFalse($participant->fresh()->is_closing);
        $this->post($url, ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertTrue($participant->fresh()->is_closing);
    }

    public function test_target_does_not_change_registration_quota(): void
    {
        $participant = Karyawan::factory()->create();
        $visit = $participant->rencanaKunjungan;
        $quota = $visit->kuota_peserta;
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('rencana-kunjungan.target', $visit), ['jumlah_peserta_direncanakan' => 529])->assertSessionHasNoErrors();
        $this->assertSame(529, $visit->fresh()->jumlah_peserta_direncanakan);
        $this->assertSame($quota, $visit->fresh()->kuota_peserta);
        $this->put(route('rencana-kunjungan.target', $visit), ['jumlah_peserta_direncanakan' => 0])->assertSessionHasErrors('jumlah_peserta_direncanakan');
        $visit->update(['is_closing' => true]);
        $this->put(route('rencana-kunjungan.target', $visit), ['jumlah_peserta_direncanakan' => 530])->assertForbidden();
    }

    public function test_medical_statistics_use_released_snapshot_only(): void
    {
        $item = McuItem::factory()->create(['input_type' => 'number', 'label' => 'Hemoglobin', 'lab_config' => ['result_type' => 'number', 'rule' => 'range', 'min' => 12, 'max' => 16, 'unit' => 'g/dL', 'reference' => '12–16', 'normal_report' => '', 'abnormal_report' => 'Tinggi']]);
        $schema = [['id' => $item->category->id, 'name' => 'Hematologi', 'order' => 0, 'items' => [$item->definition()]]];
        $package = Paket::factory()->create(['dynamic_schema' => $schema, 'active_items' => []]);
        $released = Karyawan::factory()->create(['paket_id' => $package->id, 'status_pemeriksaan' => 'selesai', 'is_closing' => true]);
        $released->rencanaKunjungan->update(['jumlah_peserta_direncanakan' => 529]);
        $released->anamnesa()->create(['rencana_kunjungan_id' => $released->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => $schema, 'dynamic_answers' => [$item->id => '18']]);
        $unreleased = Karyawan::factory()->create(['paket_id' => $package->id, 'status_pemeriksaan' => 'selesai']);
        $unreleased->anamnesa()->create(['rencana_kunjungan_id' => $unreleased->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => $schema, 'dynamic_answers' => [$item->id => '14']]);
        $item->update(['lab_config' => ['result_type' => 'number', 'rule' => 'range', 'min' => 0, 'max' => 100]]);
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $released->perusahaan_id]));
        $response = $this->getJson(route('hrd.data'));
        $response->assertOk()->assertJsonPath('stats.planned', 529)->assertJsonPath('stats.final', 2)->assertJsonPath('stats.remaining', 527)->assertJsonPath('stats.released', 1);
        $widget = collect($response->json('widgets'))->firstWhere('title', 'Hemoglobin');
        $this->assertSame(1, $widget['total']);
        $this->assertSame(100, (int) $widget['abnormal_percent']);
        $this->assertSame('Tidak normal', $widget['rows'][0]['label']);
        $this->assertArrayNotHasKey('values', $response->json('participants.0'));
        $this->getJson(route('hrd.data', ['cross_source' => $widget['source'], 'cross_value' => 'Tidak normal']))->assertOk()->assertJsonPath('filtered_count', 1);
    }

    public function test_builder_saves_layout_and_rejects_unknown_sources(): void
    {
        $company = Perusahaan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $widget = ['source' => 'gender', 'title' => 'Peserta', 'chart' => 'donut', 'metric' => 'count', 'width' => 2, 'height' => 1];
        $this->putJson(route('hrd.layout', ['company' => $company->id]), ['widgets' => [$widget]])->assertOk();
        $this->assertSame([$widget], $company->fresh()->dashboard_layout);
        $this->putJson(route('hrd.layout', ['company' => $company->id]), ['widgets' => [array_replace($widget, ['source' => 'sql:users'])]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.source');
        $this->putJson(route('hrd.layout', ['company' => $company->id]), ['widgets' => []])->assertOk();
        $this->assertNull($company->fresh()->dashboard_layout);
    }

    public function test_missing_target_is_not_fake_zero(): void
    {
        $company = Perusahaan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]))->getJson(route('hrd.data'))->assertOk()->assertJsonPath('stats.planned', null)->assertJsonPath('stats.remaining', null)->assertJsonPath('stats.registered', 0);
    }

    public function test_released_result_locks_identity_status_photo_and_stale_release_is_rejected(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create(['status_pemeriksaan' => 'selesai']);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'lock_version' => 3]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('peserta.release', [$participant->rencana_kunjungan_id, $participant]), ['confirmed' => 1, 'lock_version' => 2])->assertSessionHasErrors('release');
        $this->assertFalse($participant->fresh()->is_closing);
        $this->post(route('peserta.release', [$participant->rencana_kunjungan_id, $participant]), ['confirmed' => 1, 'lock_version' => 3])->assertSessionHasNoErrors();
        $this->post(route('peserta.status', [$participant->rencana_kunjungan_id, $participant]), ['status' => 'batal', 'reason' => 'Pembatalan setelah rilis'])->assertForbidden();
        $this->post(route('pemeriksaan.photo', [$participant->rencana_kunjungan_id, $participant]), ['foto_pasien' => UploadedFile::fake()->image('foto.png')])->assertForbidden();
        $this->put(route('pasien.update', $participant), ['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $participant->paket_id, 'nama_karyawan' => 'Identitas berubah', 'jenis_kelamin' => 'L'])->assertForbidden();
        $this->assertSame('selesai', $participant->fresh()->status_pemeriksaan);
    }

    public function test_missing_and_incomparable_laboratory_results_do_not_enter_normal_percentage(): void
    {
        $item = McuItem::factory()->create(['input_type' => 'number', 'label' => 'Lab angka', 'lab_config' => ['result_type' => 'number', 'rule' => 'range', 'min' => 12, 'max' => 16, 'unit' => 'g/dL']]);
        $schema = [['id' => $item->category->id, 'name' => 'Lab', 'order' => 0, 'items' => [$item->definition()]]];
        $package = Paket::factory()->create(['dynamic_schema' => $schema, 'active_items' => []]);
        foreach (['14', '18', null, 'tidak numerik'] as $answer) {
            $participant = Karyawan::factory()->create(['paket_id' => $package->id, 'status_pemeriksaan' => 'selesai', 'is_closing' => true]);
            $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => $schema, 'dynamic_answers' => [$item->id => $answer]]);
        }
        $outside = Karyawan::factory()->create(['perusahaan_id' => $package->perusahaan_id, 'rencana_kunjungan_id' => $package->rencana_kunjungan_id, 'status_pemeriksaan' => 'selesai', 'is_closing' => true]);
        $outside->anamnesa()->create(['rencana_kunjungan_id' => $outside->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => [], 'dynamic_answers' => [$item->id => '14']]);
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $package->perusahaan_id]));

        $response = $this->getJson(route('hrd.data'))->assertOk();
        $widget = collect($response->json('widgets'))->firstWhere('title', 'Lab angka');

        $this->assertSame(4, $widget['total']);
        $this->assertSame(2, $widget['valid']);
        $this->assertSame(50.0, (float) $widget['abnormal_percent']);
        $rows = collect($widget['rows'])->keyBy('label');
        $this->assertNull($rows['Belum diperiksa']['percent']);
        $this->assertNull($rows['Tidak dapat dibandingkan']['percent']);
        $this->assertSame(50.0, (float) $rows['Normal']['percent']);
    }

    public function test_numeric_dimension_and_measure_are_computed_and_preview_never_saves_layout(): void
    {
        $package = Paket::factory()->create(['active_items' => ['tinggi_badan' => true]]);
        foreach ([['L', 160], ['L', 180], ['P', 150]] as [$gender, $height]) {
            $participant = Karyawan::factory()->create(['paket_id' => $package->id, 'jenis_kelamin' => $gender, 'status_pemeriksaan' => 'selesai', 'is_closing' => true]);
            $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => ['tinggi_badan' => true], 'tinggi_badan' => $height]);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $widget = ['source' => 'physical:tinggi_badan', 'title' => 'Tinggi menurut jenis kelamin', 'dimension' => 'gender', 'metric' => 'average', 'chart' => 'bar', 'width' => 2, 'height' => 1];
        $response = $this->postJson(route('hrd.preview', ['company' => $package->perusahaan_id]), ['widgets' => [$widget]])->assertOk();
        $rows = collect($response->json('widgets.0.rows'))->keyBy('label');
        $this->assertSame(170.0, (float) $rows['Pria']['value']);
        $this->assertSame(150.0, (float) $rows['Wanita']['value']);
        $this->assertNull($package->perusahaan->fresh()->dashboard_layout);
        $this->putJson(route('hrd.layout', ['company' => $package->perusahaan_id]), ['widgets' => [array_replace($widget, ['source' => 'gender'])]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.metric');
        $this->putJson(route('hrd.layout', ['company' => $package->perusahaan_id]), ['widgets' => [array_replace($widget, ['chart' => 'pie'])]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.chart');
    }

    public function test_changed_units_remain_separate_and_new_master_does_not_fill_custom_layout(): void
    {
        $item = McuItem::factory()->create(['input_type' => 'number', 'label' => 'Hemoglobin', 'lab_config' => ['result_type' => 'number', 'rule' => 'range', 'min' => 12, 'max' => 16, 'unit' => 'g/dL']]);
        $package = Paket::factory()->create(['active_items' => []]);
        foreach ([['g/dL', 12, 16, 14], ['g/L', 120, 160, 140]] as [$unit, $min, $max, $answer]) {
            $definition = $item->definition();
            $definition['lab_config'] = ['result_type' => 'number', 'rule' => 'range', 'unit' => $unit, 'min' => $min, 'max' => $max];
            $schema = [['id' => $item->category->id, 'name' => 'Lab', 'order' => 0, 'items' => [$definition]]];
            $participant = Karyawan::factory()->create(['paket_id' => $package->id, 'status_pemeriksaan' => 'selesai', 'is_closing' => true]);
            $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => $schema, 'dynamic_answers' => [$item->id => $answer]]);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->getJson(route('hrd.data', ['company' => $package->perusahaan_id]))->assertOk();
        $labs = collect($response->json('widgets'))->where('title', 'Hemoglobin');
        $this->assertCount(2, $labs);
        $this->assertSame(['g/L', 'g/dL'], $labs->pluck('unit')->sort()->values()->all());
        $this->assertSame([1, 1], $labs->pluck('total')->values()->all());
        $widget = ['source' => 'gender', 'title' => 'Jenis kelamin', 'chart' => 'donut', 'metric' => 'count', 'width' => 1, 'height' => 1];
        $this->putJson(route('hrd.layout', ['company' => $package->perusahaan_id]), ['widgets' => [$widget]])->assertOk();
        $new = McuItem::factory()->create(['label' => 'Master baru', 'is_active' => true]);
        $new->category->update(['is_active' => true]);
        $this->travel(4)->seconds();
        $response = $this->getJson(route('hrd.data', ['company' => $package->perusahaan_id]))->assertOk();
        $this->assertCount(1, $response->json('widgets'));
        $this->assertContains('Master baru', array_column($response->json('builder_sources'), 'title'));
    }

    public function test_details_are_released_episode_scoped_and_exclude_shared_identity_or_attachment_paths(): void
    {
        $participant = Karyawan::factory()->create(['nama_karyawan' => 'Episode perusahaan sendiri', 'status_pemeriksaan' => 'selesai', 'is_closing' => true]);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'catatan_dokter' => 'Hasil untuk perusahaan sendiri']);
        $foreign = Karyawan::factory()->create(['pasien_id' => $participant->pasien_id, 'nama_karyawan' => 'Episode perusahaan lain']);
        $participant->pasien->update(['nama' => 'Identitas dari perusahaan lain']);
        $unreleased = Karyawan::factory()->create(['paket_id' => $participant->paket_id]);
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]));
        $this->getJson(route('hrd.participants.show', $participant))->assertOk()->assertJsonPath('name', 'Episode perusahaan sendiri')->assertDontSee('Identitas dari perusahaan lain')->assertJsonMissingPath('foto');
        $this->getJson(route('hrd.participants.show', $foreign))->assertNotFound();
        $this->getJson(route('hrd.participants.show', $unreleased))->assertNotFound();
        $this->get(route('pemeriksaan.attachment', [$foreign->rencana_kunjungan_id, $foreign, 'foto']))->assertForbidden();
    }

    public function test_cached_company_data_is_not_accessible_after_reassignment_and_filters_have_no_fake_target(): void
    {
        $participant = Karyawan::factory()->create();
        $participant->rencanaKunjungan->update(['jumlah_peserta_direncanakan' => 529]);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $this->actingAs($hrd)->getJson(route('hrd.data', ['q' => $participant->nama_karyawan]))->assertOk()->assertJsonPath('stats.planned', null);
        $this->getJson(route('hrd.data'))->assertOk()->assertJsonPath('stats.planned', 529);
        $hrd->update(['perusahaan_id' => Perusahaan::factory()->create()->id]);
        $this->getJson(route('hrd.data', ['company' => $participant->perusahaan_id]))->assertNotFound();
    }

    public function test_planned_529_and_final_505_leave_24_without_per_participant_database_queries(): void
    {
        $package = Paket::factory()->create(['active_items' => [], 'dynamic_schema' => []]);
        $package->rencanaKunjungan->update(['jumlah_peserta_direncanakan' => 529]);
        $participants = Karyawan::factory()->count(505)->create(['paket_id' => $package->id, 'status_pemeriksaan' => 'selesai']);
        foreach ($participants as $participant) {
            $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => []]);
        }
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $package->perusahaan_id]));
        DB::enableQueryLog();

        $this->getJson(route('hrd.data'))->assertOk()->assertJsonPath('stats.planned', 529)->assertJsonPath('stats.final', 505)->assertJsonPath('stats.remaining', 24)->assertJsonPath('stats.released', 0);

        $this->assertLessThan(35, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_csv_is_company_scoped_and_excel_formulas_are_neutralized(): void
    {
        $participant = Karyawan::factory()->create(['bagian' => '=1+1']);
        $foreign = Karyawan::factory()->create(['bagian' => 'RAHASIA_PERUSAHAAN_LAIN']);
        $participant->perusahaan->update(['dashboard_layout' => [['source' => 'department', 'title' => '=Judul', 'chart' => 'table', 'metric' => 'count', 'width' => 1, 'height' => 1]]]);
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]));

        $response = $this->get(route('hrd.recap'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'=Judul", $csv);
        $this->assertStringNotContainsString('RAHASIA_PERUSAHAAN_LAIN', $csv);
        $this->get(route('hrd.recap', ['company' => $foreign->perusahaan_id]))->assertNotFound();
    }

    public function test_builder_keeps_historical_sources_when_another_visit_is_filtered_and_age_stays_at_mcu_date(): void
    {
        $item = McuItem::factory()->create(['input_type' => 'number', 'lab_config' => ['result_type' => 'number', 'rule' => 'range', 'min' => 12, 'max' => 16, 'unit' => 'g/dL']]);
        $package = Paket::factory()->create(['active_items' => [], 'dynamic_schema' => []]);
        $package->rencanaKunjungan->update(['tgl_mulai' => '2026-10-15']);
        $participant = Karyawan::factory()->create(['paket_id' => $package->id, 'tanggal_lahir' => '2000-10-16', 'tanggal_mcu' => null, 'status_pemeriksaan' => 'selesai', 'is_closing' => true]);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [],
            'dynamic_schema' => [['id' => $item->category->id, 'name' => 'Kategori historis', 'order' => 0, 'items' => [$item->definition()]]], 'dynamic_answers' => [$item->id => 14]]);
        $otherVisit = RencanaKunjungan::factory()->create(['perusahaan_id' => $package->perusahaan_id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson(route('hrd.data', ['company' => $package->perusahaan_id]))->assertOk();
        $source = collect($response->json('widgets'))->firstWhere('kind', 'laboratory')['source'];
        $filtered = $this->getJson(route('hrd.data', ['company' => $package->perusahaan_id, 'visit' => $otherVisit->id]))->assertOk();
        $this->assertContains($source, array_column($filtered->json('builder_sources'), 'id'));
        $this->travel(1)->years();
        $response = $this->getJson(route('hrd.data', ['company' => $package->perusahaan_id, 'visit' => $participant->rencana_kunjungan_id]))->assertOk();
        $ages = collect($response->json('widgets'))->firstWhere('source', 'age');
        $this->assertSame('25 atau kurang', $ages['rows'][0]['label']);
    }
}
