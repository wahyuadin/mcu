<?php

namespace Tests\Feature;

use App\Models\DashboardPreference;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DashboardFiltersTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['draft', false, 'draft'])]
    #[TestWith(['ongoing', false, 'ongoing'])]
    #[TestWith(['completed', false, 'completed'])]
    #[TestWith(['completed', true, 'closed'])]
    public function test_status_filters_scope_statistics_recent_visits_and_chart_previews(string $status, bool $closed, string $filter): void
    {
        $company = Perusahaan::factory()->create();
        $selected = RencanaKunjungan::factory()->create(['perusahaan_id' => $company->id, 'status' => $status, 'is_closing' => $closed]);
        $foreignStatus = $status === 'draft' ? 'ongoing' : 'draft';
        $other = RencanaKunjungan::factory()->create(['perusahaan_id' => $company->id, 'status' => $foreignStatus]);
        Karyawan::factory()->create(['rencana_kunjungan_id' => $selected->id, 'perusahaan_id' => $company->id, 'jenis_kelamin' => 'L']);
        Karyawan::factory()->count(2)->create(['rencana_kunjungan_id' => $other->id, 'perusahaan_id' => $company->id, 'jenis_kelamin' => 'P']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson(route('dashboard.data', ['company' => $company->id, 'visit_status' => $filter]))->assertOk()->assertJsonPath('stats.peserta', 1)
            ->assertJsonCount(1, 'recent_visits')->assertJsonPath('recent_visits.0.number', $selected->nomor_kunjungan)->assertJsonCount(5, 'layout');

        $this->postJson(route('dashboard.preview', ['company' => $company->id, 'visit_status' => $filter]), ['widgets' => [$this->chart()]])->assertOk()
            ->assertJsonPath('widgets.0.total', 1)->assertJsonPath('widgets.0.rows.0.label', 'Pria');
        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    public function test_completed_and_closed_are_distinct_and_no_filter_includes_both(): void
    {
        $done = Karyawan::factory()->create();
        $closed = Karyawan::factory()->create();
        $done->rencanaKunjungan->update(['status' => 'completed']);
        $closed->rencanaKunjungan->update(['status' => 'completed', 'is_closing' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson(route('dashboard.data', ['visit_status' => 'completed']))->assertOk()->assertJsonPath('stats.peserta', 1)->assertJsonPath('recent_visits.0.number', $done->rencanaKunjungan->nomor_kunjungan);

        $this->getJson(route('dashboard.data', ['visit_status' => 'closed']))->assertOk()->assertJsonPath('stats.peserta', 1)->assertJsonPath('recent_visits.0.number', $closed->rencanaKunjungan->nomor_kunjungan);
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('stats.peserta', 2)->assertJsonCount(2, 'visit_options');
    }

    public function test_selecting_a_visit_does_not_replace_a_saved_layout_and_the_same_url_reproduces_the_scope(): void
    {
        $participant = Karyawan::factory()->create();
        Karyawan::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->postJson(route('dashboard.saved.store'), ['name' => 'Demografi', 'widgets' => [$this->chart()]])->assertCreated();
        $before = DashboardPreference::sole()->dashboards;
        $scope = ['company' => $participant->perusahaan_id, 'visit' => $participant->rencana_kunjungan_id];

        $this->get(route('dashboard', $scope))->assertOk()->assertSee('data-dashboard-visit-status', false)->assertSee('Reset filter')->assertViewHas('stats', fn (array $stats): bool => $stats['peserta'] === 1);

        $this->getJson(route('dashboard.data', $scope))->assertOk()->assertJsonPath('stats.peserta', 1)->assertJsonPath('filters.visit', $participant->rencana_kunjungan_id)->assertJsonPath('layout.0.source', 'gender');
        $this->assertSame($before, DashboardPreference::sole()->dashboards);
    }

    public function test_foreign_visits_cannot_expand_hrd_scope_or_bypass_the_admin_company_filter(): void
    {
        $own = Karyawan::factory()->create();
        $foreign = Karyawan::factory()->create();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $own->perusahaan_id]);

        $this->actingAs($hrd)->getJson(route('dashboard.data', ['visit' => $foreign->rencana_kunjungan_id]))->assertNotFound();

        $this->postJson(route('dashboard.preview', ['visit' => $foreign->rencana_kunjungan_id]), ['widgets' => []])->assertNotFound();
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonCount(1, 'visit_options')->assertDontSee($foreign->rencana_kunjungan_id);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson(route('dashboard.data', ['company' => $own->perusahaan_id, 'visit' => $foreign->rencana_kunjungan_id]))->assertNotFound();
    }

    public function test_incompatible_visit_status_returns_empty_results_instead_of_silently_broadening_the_filter(): void
    {
        $participant = Karyawan::factory()->create();
        $participant->rencanaKunjungan->update(['status' => 'ongoing']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson(route('dashboard.data', ['visit' => $participant->rencana_kunjungan_id, 'visit_status' => 'draft']))->assertOk()
            ->assertJsonPath('stats.peserta', 0)->assertJsonPath('recent_visits', [])->assertJsonPath('filters.visit', $participant->rencana_kunjungan_id);
    }

    public function test_invalid_status_and_visit_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson(route('dashboard.data', ['visit_status' => 'sql']))->assertUnprocessable()->assertJsonValidationErrors('visit_status');
        $this->getJson(route('dashboard.data', ['visit' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('visit');
    }

    public function test_hrd_participant_lists_and_recap_use_the_same_visit_status_scope(): void
    {
        $ongoing = Karyawan::factory()->create(['nama_karyawan' => 'Peserta berjalan', 'bagian' => 'Produksi']);
        $ongoing->rencanaKunjungan->update(['status' => 'ongoing']);
        $draft = Karyawan::factory()->create(['perusahaan_id' => $ongoing->perusahaan_id, 'nama_karyawan' => 'Peserta draft']);
        $draft->rencanaKunjungan->update(['perusahaan_id' => $ongoing->perusahaan_id, 'status' => 'draft']);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $ongoing->perusahaan_id]);

        $this->actingAs($hrd)->getJson(route('hrd.data', ['visit_status' => 'ongoing']))->assertOk()->assertJsonPath('stats.registered', 1)->assertJsonPath('participants.0.name', 'Peserta berjalan')->assertDontSee('Peserta draft');

        $this->get(route('hrd.recap', ['visit_status' => 'ongoing']))->assertOk()->assertDownload('Rekap_MCU.csv');
    }

    public function test_the_default_dashboard_can_be_renamed_per_account_without_creating_or_changing_layouts(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('default_dashboard.name', 'Dashboard');

        $this->patchJson(route('dashboard.saved.update', 'default'), ['name' => 'Ringkasan saya', 'version' => 1])->assertOk()->assertJsonPath('dashboard.version', 2);

        $this->assertSame('Ringkasan saya', DashboardPreference::sole()->default_name);
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('default_dashboard.name', 'Ringkasan saya')->assertJsonPath('personal_layout', false)->assertJsonPath('dashboards', [])->assertJsonCount(5, 'layout');
        $this->get(route('dashboard'))->assertOk()->assertSee('Ringkasan saya')->assertDontSee('Dashboard Bawaan');
        $this->actingAs($other)->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('default_dashboard.name', 'Dashboard');
    }

    public function test_filtering_by_status_keeps_the_hrd_catalog_available_for_other_visits(): void
    {
        $participant = Karyawan::factory()->create();
        $participant->rencanaKunjungan->update(['status' => 'draft']);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);

        $response = $this->actingAs($hrd)->getJson(route('hrd.data', ['visit_status' => 'ongoing']))->assertOk()->assertJsonPath('stats.registered', 0);

        $this->assertContains('history:hipertensi', array_column($response->json('builder_sources'), 'id'));
        $this->postJson(route('hrd.preview', ['visit_status' => 'ongoing']), ['widgets' => [[...$this->chart(), 'source' => 'history:hipertensi']]])->assertOk()->assertJsonPath('widgets.0.total', 0);
        $this->assertDatabaseCount('hrd_dashboard_preferences', 0);
    }

    public function test_renaming_the_default_after_hrd_reassignment_preserves_old_dashboards_without_duplicating_them(): void
    {
        $oldCompany = Perusahaan::factory()->create();
        $newCompany = Perusahaan::factory()->create();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $oldCompany->id]);
        $this->actingAs($hrd)->postJson(route('dashboard.saved.store'), ['name' => 'Dashboard perusahaan lama', 'widgets' => [$this->chart()]])->assertCreated();
        $oldDashboards = DashboardPreference::sole()->dashboards;
        $hrd->update(['perusahaan_id' => $newCompany->id]);

        $this->actingAs($hrd)->patchJson(route('dashboard.saved.update', 'default'), ['name' => 'Ringkasan baru', 'version' => 1])->assertOk();
        $this->patchJson(route('dashboard.saved.update', 'default'), ['name' => 'Ringkasan pribadi', 'version' => 2])->assertOk();

        $this->assertSame($oldDashboards, DashboardPreference::sole()->dashboards);
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('dashboards', [])->assertJsonPath('default_dashboard.name', 'Ringkasan pribadi');
        $hrd->update(['perusahaan_id' => $oldCompany->id]);
        $this->actingAs($hrd)->getJson(route('dashboard.data'))->assertOk()->assertJsonCount(1, 'dashboards')->assertJsonPath('dashboards.0.name', 'Dashboard perusahaan lama');
    }

    public function test_default_rename_obeys_version_checks_and_cannot_be_used_to_replace_or_delete_the_default_layout(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson(route('dashboard.saved.update', 'default'), ['name' => 'Baru', 'version' => 1])->assertOk();
        $this->patchJson(route('dashboard.saved.update', 'default'), ['name' => 'Usang', 'version' => 1])->assertConflict();
        $this->patchJson(route('dashboard.saved.update', 'default'), ['widgets' => [], 'version' => 2])->assertUnprocessable()->assertJsonValidationErrors('widgets');
        $this->deleteJson(route('dashboard.saved.destroy', 'default'), ['version' => 2])->assertNotFound();

        $this->assertSame('Baru', DashboardPreference::sole()->default_name);
        $this->assertNull(DashboardPreference::sole()->widgets);
    }

    /** @return array<string, mixed> */
    private function chart(): array
    {
        return ['source' => 'gender', 'title' => 'Demografi', 'chart' => 'bar', 'metric' => 'count', 'width' => 1, 'height' => 1];
    }
}
