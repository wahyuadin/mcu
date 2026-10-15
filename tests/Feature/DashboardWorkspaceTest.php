<?php

namespace Tests\Feature;

use App\Models\DashboardPreference;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DashboardWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin'])]
    #[TestWith(['petugas'])]
    #[TestWith(['hrd'])]
    public function test_one_dashboard_menu_serves_each_role_without_a_separate_hrd_dashboard(string $role): void
    {
        $company = Perusahaan::factory()->create();
        $user = User::factory()->create(['role' => $role, 'perusahaan_id' => $role === 'hrd' ? $company->id : null]);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('Dashboard &amp; Laporan HRD', false)->assertSee('Atur dashboard');

        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $this->assertSame(1, (new DOMXPath($dom))->query('//a[@data-sidebar-link and normalize-space(.)="Dashboard"]')->length);
        $response->assertViewIs('mcu.index')->assertSee('data-dashboard-workspace', false);
    }

    #[TestWith(['admin'])]
    #[TestWith(['petugas'])]
    public function test_original_operational_default_keeps_four_summary_cards_and_recent_visits(string $role): void
    {
        $participant = Karyawan::factory()->create(['status_pemeriksaan' => 'selesai']);
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Kunjungan Aktif')->assertSee('Total Peserta')->assertSee('Pemeriksaan Selesai')->assertSee('Kunjungan MCU Terbaru');

        $response = $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('personal_layout', false)->assertJsonPath('stats.perusahaan', 1)->assertJsonPath('stats.peserta', 1)->assertJsonPath('stats.selesai', 1)->assertJsonCount(5, 'layout');

        $this->assertSame(['perusahaan', 'kunjungan_aktif', 'peserta', 'selesai', 'kunjungan_terbaru'], array_column($response->json('layout'), 'source'));
        $response->assertJsonPath('recent_visits.0.url', $role === 'admin' ? route('rencana-kunjungan.show', $participant->rencana_kunjungan_id) : route('kunjungan-mcu.show', $participant->rencana_kunjungan_id));
        $response->assertJsonPath('recent_visits.0.action_label', $role === 'admin' ? 'Kelola' : 'Buka');
    }

    public function test_admin_company_analysis_uses_the_same_dashboard_entry_and_legacy_hrd_url_redirects(): void
    {
        $company = Perusahaan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('dashboard', ['company' => $company->id]))->assertOk()->assertViewIs('mcu.index')->assertSee('data-dashboard-workspace', false)->assertSee('Semua Perusahaan');
        $this->get(route('hrd.index', ['company' => $company->id]))->assertRedirect(route('dashboard', ['company' => $company->id]));
    }

    public function test_hrd_cannot_switch_to_another_company_through_the_shared_dashboard_url(): void
    {
        $company = Perusahaan::factory()->create();
        $foreign = Perusahaan::factory()->create();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]);

        $this->actingAs($hrd)->get(route('dashboard', ['company' => $foreign->id]))->assertNotFound();
    }

    public function test_unassigned_hrd_cannot_use_the_operational_default_as_a_fallback(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hrd']))->get(route('dashboard'))->assertForbidden();
    }

    public function test_legacy_doctor_keeps_the_readonly_summary_without_gaining_dashboard_editor_access(): void
    {
        $doctor = User::factory()->create(['role' => 'dokter']);
        $this->actingAs($doctor)->get(route('dashboard'))->assertOk()->assertSee('Kunjungan MCU Terbaru')->assertDontSee('data-dashboard-workspace', false)->assertDontSee('Atur dashboard');

        $this->getJson(route('dashboard.data'))->assertForbidden();
        $this->postJson(route('dashboard.saved.store'), ['name' => 'Dilarang', 'widgets' => []])->assertForbidden();
        $this->getJson(route('hrd.data'))->assertForbidden();
        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    #[TestWith(['admin'])]
    #[TestWith(['petugas'])]
    public function test_personal_layout_is_saved_per_account_and_preview_never_changes_the_default(string $role): void
    {
        $owner = User::factory()->create(['role' => $role]);
        $colleague = User::factory()->create(['role' => $role]);
        $widgets = [$this->widget('proses', 'Progres saya')];

        $this->actingAs($owner)->postJson(route('dashboard.preview'), ['widgets' => $widgets])->assertOk()->assertJsonPath('widgets.0.title', 'Progres saya');
        $this->assertDatabaseCount('dashboard_preferences', 0);
        $this->putJson(route('dashboard.preferences'), ['widgets' => $widgets])->assertOk();
        $this->assertSame($widgets, DashboardPreference::sole()->widgets);
        $this->assertSame($owner->id, DashboardPreference::sole()->user_id);
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('personal_layout', true)->assertJsonPath('layout.0.title', 'Progres saya');
        $this->actingAs($colleague)->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('personal_layout', false)->assertJsonCount(5, 'layout');
    }

    public function test_role_changes_do_not_reuse_a_dashboard_saved_for_another_role(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        DashboardPreference::factory()->create(['user_id' => $user->id, 'role' => 'admin', 'widgets' => []]);
        $user->update(['role' => 'petugas']);

        $this->actingAs($user)->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('personal_layout', false)->assertJsonCount(5, 'layout');
    }

    public function test_empty_custom_layout_is_distinct_from_reset_to_the_original_default(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']))->putJson(route('dashboard.preferences'), ['widgets' => []])->assertOk();
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('widgets', [])->assertJsonPath('personal_layout', true);
        $this->postJson(route('dashboard.preview'), ['widgets' => [], 'use_default' => true])->assertOk()->assertJsonCount(5, 'layout');

        $this->putJson(route('dashboard.preferences'), ['widgets' => [], 'use_default' => true])->assertOk();

        $this->assertNull(DashboardPreference::sole()->widgets);
        $this->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('personal_layout', false)->assertJsonCount(5, 'widgets');
    }

    public function test_petugas_cannot_select_hrd_sources_or_company_analysis_or_gain_admin_permissions(): void
    {
        $company = Perusahaan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'petugas']))->get(route('dashboard', ['company' => $company->id]))->assertForbidden();
        $this->getJson(route('hrd.data'))->assertForbidden();
        $this->putJson(route('hrd.preferences'), ['widgets' => []])->assertForbidden();
        $this->putJson(route('dashboard.preferences'), ['widgets' => [$this->widget('fitness', 'Hasil medis')]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.source');
        $this->get(route('rencana-kunjungan.index'))->assertForbidden();
        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    #[TestWith(['user_id'])]
    #[TestWith(['role'])]
    #[TestWith(['company'])]
    public function test_owner_and_scope_cannot_be_overridden_in_a_save_request(string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson(route('dashboard.preferences'), ['widgets' => [], $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    public function test_guests_cannot_access_the_dashboard_and_hrd_uses_only_the_scoped_unified_api(): void
    {
        $this->getJson(route('dashboard.data'))->assertUnauthorized();
        $this->putJson(route('dashboard.preferences'), ['widgets' => []])->assertUnauthorized();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => Perusahaan::factory()->create()->id]);
        $this->actingAs($hrd)->getJson(route('dashboard.data'))->assertOk()->assertJsonPath('company.id', $hrd->perusahaan_id)->assertJsonPath('stats.peserta', 0);
        $this->putJson(route('dashboard.preferences'), ['widgets' => []])->assertForbidden();
        $this->postJson(route('dashboard.preview'), ['widgets' => []])->assertOk()->assertJsonPath('company.id', $hrd->perusahaan_id);
        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    public function test_operational_catalog_and_payload_do_not_contain_medical_results_or_hrd_export_links(): void
    {
        $participant = Karyawan::factory()->create();
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'catatan_dokter' => 'RAHASIA HASIL MEDIS']);

        $response = $this->actingAs(User::factory()->create(['role' => 'petugas']))->getJson(route('dashboard.data'))->assertOk()->assertDontSee('RAHASIA HASIL MEDIS')->assertJsonMissingPath('participants')->assertJsonMissingPath('export');

        $this->assertSame(['kpi', 'operation_table'], array_values(array_unique(array_column($response->json('sources'), 'kind'))));
    }

    public function test_unknown_sources_invalid_charts_and_unexpected_widget_fields_are_rejected_without_saving(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson(route('dashboard.preferences'), ['widgets' => [array_replace($this->widget('proses'), ['chart' => 'table'])]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.chart');
        $this->putJson(route('dashboard.preferences'), ['widgets' => [array_replace($this->widget('proses'), ['sql' => 'users'])]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0');
        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    /** @return array{source: string, title: string, chart: string, metric: string, width: int, height: int} */
    private function widget(string $source, string $title = 'Widget saya'): array
    {
        return ['source' => $source, 'title' => $title, 'chart' => 'number', 'metric' => 'count', 'width' => 1, 'height' => 1];
    }
}
