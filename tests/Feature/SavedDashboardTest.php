<?php

namespace Tests\Feature;

use App\Models\DashboardPreference;
use App\Models\HrdDashboardPreference;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SavedDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin'])]
    #[TestWith(['petugas'])]
    #[TestWith(['hrd'])]
    public function test_accounts_can_save_multiple_named_dashboards_and_restore_the_selected_one(string $role): void
    {
        $company = Perusahaan::factory()->create();
        $user = User::factory()->create(['role' => $role, 'perusahaan_id' => $role === 'hrd' ? $company->id : null]);
        $this->actingAs($user);
        $first = $this->postJson(route('dashboard.saved.store'), ['name' => 'Monitoring Harian', 'widgets' => [$this->widget('peserta')]])->assertCreated()->json('dashboard.id');
        $second = $this->postJson(route('dashboard.saved.store'), ['name' => 'Progres MCU', 'widgets' => [$this->widget('selesai')]])->assertCreated()->json('dashboard.id');

        $this->assertDatabaseCount('dashboard_preferences', 1);
        $this->assertCount(2, DashboardPreference::sole()->dashboards);
        $this->assertSame($user->id, DashboardPreference::sole()->user_id);
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('active_dashboard', $second)->assertJsonPath('layout.0.source', 'selesai')->assertJsonCount(2, 'dashboards');
        $this->putJson(route('dashboard.selection'), ['dashboard' => $first])->assertOk();
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('active_dashboard', $first)->assertJsonPath('layout.0.source', 'peserta');
    }

    public function test_company_filters_share_one_compact_canvas_and_never_change_the_saved_configuration(): void
    {
        $first = Karyawan::factory()->create();
        $other = Karyawan::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->get(route('dashboard'))->assertOk()->assertViewIs('mcu.index')->assertSee('Semua Perusahaan');
        $this->get(route('dashboard', ['company' => $first->perusahaan_id]))->assertOk()->assertViewIs('mcu.index');
        $this->getJson(route('dashboard.data', ['company' => $first->perusahaan_id]))->assertOk()->assertJsonPath('stats.peserta', 1)->assertJsonCount(5, 'layout')->assertJsonPath('personal_layout', false);
        $id = $this->postJson(route('dashboard.saved.store', ['company' => $first->perusahaan_id]), ['name' => 'Lintas perusahaan', 'widgets' => [$this->widget('peserta')]])->assertCreated()->json('dashboard.id');
        $before = DashboardPreference::sole()->dashboards;

        $this->getJson(route('dashboard.data', ['company' => $other->perusahaan_id]))->assertOk()->assertJsonPath('active_dashboard', $id)->assertJsonPath('widgets.0.total', 1)->assertJsonPath('recent_visits.0.company', $other->perusahaan->nama_perusahaan);
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('active_dashboard', $id)->assertJsonPath('widgets.0.total', 2);
        $this->assertSame($before, DashboardPreference::sole()->dashboards);
    }

    public function test_default_selection_does_not_delete_named_dashboards_or_write_shared_company_settings(): void
    {
        $company = Perusahaan::factory()->create(['dashboard_layout' => [$this->widget('gender', 'bar')]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $id = $this->postJson(route('dashboard.saved.store'), ['name' => 'Kosong', 'widgets' => []])->assertCreated()->json('dashboard.id');
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('personal_layout', true)->assertJsonPath('layout', []);

        $this->putJson(route('dashboard.selection'), ['dashboard' => 'default'])->assertOk();

        $this->getJson(route('dashboard.data', ['company' => $company->id]))->assertOk()->assertJsonPath('personal_layout', false)->assertJsonCount(5, 'layout')->assertJsonCount(1, 'dashboards');
        $this->assertSame([$this->widget('gender', 'bar')], $company->fresh()->dashboard_layout);
        $this->getJson(route('dashboard.data', ['dashboard' => $id]))->assertOk()->assertJsonPath('layout', []);
    }

    public function test_rename_and_save_update_only_the_target_and_stale_tabs_cannot_overwrite_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $first = $this->postJson(route('dashboard.saved.store'), ['name' => 'Pertama', 'widgets' => [$this->widget('peserta')]])->assertCreated()->json('dashboard.id');
        $second = $this->postJson(route('dashboard.saved.store'), ['name' => 'Kedua', 'widgets' => []])->assertCreated()->json('dashboard.id');

        $this->patchJson(route('dashboard.saved.update', $first), ['name' => 'Baru', 'version' => 1, 'widgets' => [$this->widget('proses')]])->assertOk()->assertJsonPath('dashboard.version', 2);

        $this->patchJson(route('dashboard.saved.update', $first), ['name' => 'Versi usang', 'version' => 1])->assertConflict();
        $this->deleteJson(route('dashboard.saved.destroy', $first), ['version' => 1])->assertConflict();
        $this->getJson(route('dashboard.data', ['dashboard' => $first]))->assertOk()->assertJsonPath('dashboard.name', 'Baru')->assertJsonPath('layout.0.source', 'proses');
        $this->getJson(route('dashboard.data', ['dashboard' => $second]))->assertOk()->assertJsonPath('dashboard.name', 'Kedua')->assertJsonPath('layout', []);
        $this->deleteJson(route('dashboard.saved.destroy', $first), ['version' => 2])->assertOk();
        $this->assertCount(1, DashboardPreference::sole()->dashboards);
    }

    public function test_other_accounts_cannot_read_select_modify_or_delete_a_saved_dashboard(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $colleague = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($owner)->postJson(route('dashboard.saved.store'), ['name' => 'Privat', 'widgets' => []])->assertCreated()->json('dashboard.id');

        $this->actingAs($colleague)->getJson(route('dashboard.data', ['dashboard' => $id]))->assertNotFound();

        $this->putJson(route('dashboard.selection'), ['dashboard' => $id])->assertNotFound();
        $this->patchJson(route('dashboard.saved.update', $id), ['name' => 'Diretas', 'version' => 1])->assertNotFound();
        $this->deleteJson(route('dashboard.saved.destroy', $id), ['version' => 1])->assertNotFound();
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('dashboards', []);
        $this->assertSame('Privat', DashboardPreference::where('user_id', $owner->id)->sole()->dashboards[0]['name']);
    }

    public function test_hrd_can_customize_the_same_canvas_but_cannot_expand_company_or_operational_permissions(): void
    {
        $own = Karyawan::factory()->create();
        $foreign = Karyawan::factory()->create();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $own->perusahaan_id]);
        $this->actingAs($hrd)->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('stats.peserta', 1)->assertJsonPath('company.id', $own->perusahaan_id);
        $id = $this->postJson(route('dashboard.saved.store'), ['name' => 'Demografi', 'widgets' => [$this->widget('gender', 'donut')]])->assertCreated()->json('dashboard.id');

        $this->getJson(route('dashboard.data', ['company' => $foreign->perusahaan_id]))->assertNotFound();

        $this->postJson(route('dashboard.preview', ['company' => $foreign->perusahaan_id]), ['widgets' => []])->assertNotFound();
        $this->patchJson(route('dashboard.saved.update', ['dashboard' => $id, 'company' => $foreign->perusahaan_id]), ['version' => 1, 'widgets' => []])->assertNotFound();
        $this->get(route('rencana-kunjungan.index'))->assertForbidden();
        $this->putJson(route('hrd.layout'), ['widgets' => []])->assertForbidden();
        $this->assertSame($own->perusahaan_id, DashboardPreference::sole()->dashboards[0]['company_id']);
    }

    public function test_preview_uses_unsaved_chart_configuration_and_filters_without_persisting(): void
    {
        Karyawan::factory()->create(['jenis_kelamin' => 'L', 'bagian' => 'Produksi']);
        Karyawan::factory()->create(['jenis_kelamin' => 'P', 'bagian' => 'Keuangan']);
        $widget = [...$this->widget('gender', 'donut'), 'title' => 'Pratinjau langsung', 'filter_source' => 'department', 'filter_value' => 'Produksi', 'width' => 2, 'height' => 2];

        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('dashboard.preview'), ['widgets' => [$widget]])->assertOk()
            ->assertJsonPath('widgets.0.title', 'Pratinjau langsung')->assertJsonPath('widgets.0.chart', 'donut')->assertJsonPath('widgets.0.width', 2)
            ->assertJsonPath('widgets.0.total', 1)->assertJsonPath('widgets.0.rows.0.label', 'Pria');

        $this->assertDatabaseCount('dashboard_preferences', 0);
        $this->assertDatabaseCount('hrd_dashboard_preferences', 0);
    }

    public function test_unified_medical_charts_only_include_released_final_results_and_respect_the_selected_company(): void
    {
        $released = Karyawan::factory()->create(['status_pemeriksaan' => 'selesai', 'is_closing' => true]);
        $released->anamnesa()->create(['rencana_kunjungan_id' => $released->rencana_kunjungan_id, 'status' => 'final', 'status_kelaikan' => 'Fit to Work']);
        $unreleased = Karyawan::factory()->create(['status_pemeriksaan' => 'selesai']);
        $unreleased->anamnesa()->create(['rencana_kunjungan_id' => $unreleased->rencana_kunjungan_id, 'status' => 'final', 'status_kelaikan' => 'RAHASIA BELUM DIRILIS']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $id = $this->postJson(route('dashboard.saved.store'), ['name' => 'Kelayakan kerja', 'widgets' => [$this->widget('fitness', 'bar')]])->assertCreated()->json('dashboard.id');

        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('widgets.0.total', 1)->assertJsonPath('widgets.0.rows.0.label', 'Fit to Work')->assertDontSee('RAHASIA BELUM DIRILIS')->assertJsonMissingPath('participants');

        $this->getJson(route('dashboard.data', ['company' => $unreleased->perusahaan_id]))->assertOk()->assertJsonPath('active_dashboard', $id)->assertJsonPath('widgets.0.total', 0)->assertJsonPath('widgets.0.rows', []);
    }

    public function test_the_dashboard_limit_rejects_new_copies_without_changing_existing_layouts(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $dashboards = array_map(fn (int $index): array => ['id' => 'dashboard-'.$index, 'name' => 'Dashboard '.$index, 'version' => 1, 'widgets' => [], 'company_id' => null], range(1, 50));
        DashboardPreference::factory()->create(['user_id' => $user->id, 'dashboards' => $dashboards, 'active_dashboard' => 'dashboard-1']);

        $this->actingAs($user)->postJson(route('dashboard.saved.store'), ['name' => 'Melebihi batas', 'widgets' => []])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertSame($dashboards, DashboardPreference::sole()->dashboards);
        $this->assertSame('dashboard-1', DashboardPreference::sole()->active_dashboard);
    }

    public function test_legacy_personal_layouts_are_available_and_not_discarded_when_a_new_dashboard_is_saved(): void
    {
        $company = Perusahaan::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        DashboardPreference::factory()->create(['user_id' => $admin->id, 'widgets' => [$this->widget('peserta')]]);
        $legacy = HrdDashboardPreference::factory()->create(['user_id' => $admin->id, 'perusahaan_id' => $company->id, 'widgets' => [$this->widget('gender', 'bar')]]);
        $this->actingAs($admin)->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('active_dashboard', 'legacy-operational')->assertJsonCount(2, 'dashboards');

        $this->postJson(route('dashboard.saved.store'), ['name' => 'Baru', 'widgets' => []])->assertCreated();

        $this->assertCount(3, DashboardPreference::sole()->dashboards);
        $this->getJson(route('dashboard.data', ['dashboard' => 'legacy-'.$legacy->id]))->assertOk()->assertJsonPath('layout.0.source', 'gender');
        $this->assertSame([$this->widget('gender', 'bar')], $legacy->fresh()->widgets);
    }

    public function test_reassigned_hrd_cannot_reuse_the_previous_companys_saved_layout_and_it_is_not_deleted(): void
    {
        $first = Perusahaan::factory()->create();
        $second = Perusahaan::factory()->create();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $first->id]);
        $id = $this->actingAs($hrd)->postJson(route('dashboard.saved.store'), ['name' => 'Perusahaan lama', 'widgets' => []])->assertCreated()->json('dashboard.id');
        $hrd->update(['perusahaan_id' => $second->id]);

        $this->actingAs($hrd)->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('dashboards', [])->assertJsonPath('active_dashboard', 'default');

        $this->getJson(route('dashboard.data', ['dashboard' => $id]))->assertNotFound();
        $this->postJson(route('dashboard.saved.store'), ['name' => 'Perusahaan baru', 'widgets' => []])->assertCreated();
        $this->assertCount(2, DashboardPreference::sole()->dashboards);
    }

    public function test_role_changes_do_not_expose_dashboard_titles_or_layouts_from_the_previous_role(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($user)->postJson(route('dashboard.saved.store'), ['name' => 'Analisis medis privat', 'widgets' => [$this->widget('fitness', 'bar')]])->assertCreated()->json('dashboard.id');
        $user->update(['role' => 'petugas']);

        $this->actingAs($user)->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('dashboards', [])->assertDontSee('Analisis medis privat');

        $this->getJson(route('dashboard.data', ['dashboard' => $id]))->assertNotFound();
        $this->postJson(route('dashboard.saved.store'), ['name' => 'Dilarang', 'widgets' => [$this->widget('fitness', 'bar')]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.source');
    }

    #[TestWith(['user_id'])]
    #[TestWith(['role'])]
    #[TestWith(['perusahaan_id'])]
    public function test_save_cannot_override_owner_role_or_company(string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('dashboard.saved.store'), ['name' => 'Privat', 'widgets' => [], $field => 'forged'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    public function test_invalid_names_unknown_sources_and_incompatible_visualizations_are_not_saved(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('dashboard.saved.store'), ['name' => ' ', 'widgets' => []])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(route('dashboard.saved.store'), ['name' => 'A', 'widgets' => [$this->widget('sql:users')]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.source');
        $this->postJson(route('dashboard.saved.store'), ['name' => 'A', 'widgets' => [$this->widget('peserta', 'donut')]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.chart');
        $this->postJson(route('dashboard.saved.store'), ['name' => 'A', 'widgets' => [[...$this->widget('peserta'), 'sql' => 'users']]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0');

        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    public function test_guests_and_unassigned_hrd_cannot_create_or_select_dashboards(): void
    {
        $this->postJson(route('dashboard.saved.store'), ['name' => 'A', 'widgets' => []])->assertUnauthorized();
        $this->putJson(route('dashboard.selection'), ['dashboard' => 'default'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'hrd']))->postJson(route('dashboard.saved.store'), ['name' => 'A', 'widgets' => []])->assertForbidden();
        $this->getJson(route('dashboard.data'))->assertForbidden();

        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    /** @return array<string, mixed> */
    private function widget(string $source, string $chart = 'number'): array
    {
        return ['source' => $source, 'title' => 'Widget saya', 'chart' => $chart, 'metric' => 'count', 'width' => 1, 'height' => 1];
    }
}
