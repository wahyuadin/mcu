<?php

namespace Tests\Feature;

use App\Models\HrdDashboardPreference;
use App\Models\Karyawan;
use App\Models\McuItem;
use App\Models\Perusahaan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HrdDashboardPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_hrd_can_save_a_personal_dashboard_without_changing_company_or_colleagues(): void
    {
        $company = Perusahaan::factory()->create(['dashboard_layout' => [$this->widget('department')]]);
        $owner = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]);
        $colleague = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]);
        $widgets = [$this->widget('gender')];

        $this->actingAs($owner)->putJson(route('hrd.preferences'), ['widgets' => $widgets])->assertOk()->assertJsonPath('message', 'Dashboard pribadi disimpan. Dashboard rekan tidak berubah.');

        $preference = HrdDashboardPreference::sole();
        $this->assertSame($owner->id, $preference->user_id);
        $this->assertSame($company->id, $preference->perusahaan_id);
        $this->assertSame($widgets, $preference->widgets);
        $this->assertSame([$this->widget('department')], $company->fresh()->dashboard_layout);
        $this->getJson(route('hrd.data'))->assertOk()->assertJsonPath('layout.0.source', 'gender')->assertJsonPath('personal_layout', true);
        $this->actingAs($colleague)->getJson(route('hrd.data'))->assertOk()->assertJsonPath('layout.0.source', 'department')->assertJsonPath('personal_layout', false);
    }

    public function test_an_empty_personal_dashboard_stays_empty_and_reset_returns_to_company_default(): void
    {
        $company = Perusahaan::factory()->create(['dashboard_layout' => [$this->widget('age')]]);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]);
        $this->actingAs($hrd)->putJson(route('hrd.preferences'), ['widgets' => []])->assertOk();

        $this->getJson(route('hrd.data'))->assertOk()->assertJsonPath('layout', [])->assertJsonPath('widgets', [])->assertJsonPath('personal_layout', true);
        $this->putJson(route('hrd.preferences'), ['widgets' => [], 'use_default' => true])->assertOk();
        $this->assertNull(HrdDashboardPreference::sole()->widgets);
        $this->getJson(route('hrd.data'))->assertOk()->assertJsonPath('layout.0.source', 'age')->assertJsonPath('personal_layout', false);
    }

    public function test_hrd_cannot_write_or_preview_another_company_or_impersonate_another_user(): void
    {
        $company = Perusahaan::factory()->create();
        $foreign = Perusahaan::factory()->create();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]);
        $this->actingAs($hrd)->putJson(route('hrd.preferences', ['company' => $foreign->id]), ['widgets' => []])->assertNotFound();
        $this->postJson(route('hrd.preview', ['company' => $foreign->id]), ['widgets' => []])->assertNotFound();
        $this->putJson(route('hrd.preferences'), ['widgets' => [], 'user_id' => User::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->putJson(route('hrd.preferences'), ['widgets' => [], 'perusahaan_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('perusahaan_id');
        $this->assertDatabaseCount('hrd_dashboard_preferences', 0);
    }

    #[TestWith(['petugas'])]
    #[TestWith(['hrd'])]
    public function test_unassigned_roles_cannot_customize_or_preview(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))->putJson(route('hrd.preferences'), ['widgets' => []])->assertForbidden();
        $this->postJson(route('hrd.preview'), ['widgets' => []])->assertForbidden();
        $this->assertDatabaseCount('hrd_dashboard_preferences', 0);
    }

    public function test_guest_cannot_customize_a_dashboard(): void
    {
        $this->putJson(route('hrd.preferences'), ['widgets' => []])->assertUnauthorized();
        $this->assertDatabaseCount('hrd_dashboard_preferences', 0);
    }

    public function test_preview_and_reset_preview_do_not_persist_preferences(): void
    {
        $company = Perusahaan::factory()->create(['dashboard_layout' => [$this->widget('department')]]);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]);

        $this->actingAs($hrd)->postJson(route('hrd.preview'), ['widgets' => [$this->widget('gender')]])->assertOk()->assertJsonPath('layout.0.source', 'gender');
        $this->postJson(route('hrd.preview'), ['widgets' => [], 'use_default' => true])->assertOk()->assertJsonPath('layout.0.source', 'department');

        $this->assertDatabaseCount('hrd_dashboard_preferences', 0);
        $this->assertSame([$this->widget('department')], $company->fresh()->dashboard_layout);
    }

    public function test_hrd_catalog_excludes_unrelated_master_items_and_rejects_unknown_sources(): void
    {
        $participant = Karyawan::factory()->create();
        $unrelated = McuItem::factory()->create(['label' => 'Master bukan paket perusahaan']);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $this->actingAs($hrd)->getJson(route('hrd.data'))->assertOk()->assertDontSee($unrelated->label);

        $this->putJson(route('hrd.preferences'), ['widgets' => [$this->widget('sql:users')]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.source');
        $this->postJson(route('hrd.preview'), ['widgets' => [array_replace($this->widget('gender'), ['unexpected_key' => 'unsafe'])]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0');
        $this->assertDatabaseCount('hrd_dashboard_preferences', 0);
    }

    public function test_kpi_cards_preserve_zero_and_unknown_target_and_reject_incompatible_options(): void
    {
        $company = Perusahaan::factory()->create();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]);
        $registered = array_replace($this->widget('stat:registered'), ['chart' => 'number']);
        $target = array_replace($this->widget('stat:planned'), ['chart' => 'number']);

        $this->actingAs($hrd)->postJson(route('hrd.preview'), ['widgets' => [$registered, $target]])->assertOk()->assertJsonPath('widgets.0.kind', 'kpi')->assertJsonPath('widgets.0.total', 0)->assertJsonPath('widgets.1.total', null);
        $this->putJson(route('hrd.preferences'), ['widgets' => [array_replace($registered, ['chart' => 'donut'])]])->assertUnprocessable()->assertJsonValidationErrors('widgets.0.chart');
        $this->assertDatabaseCount('hrd_dashboard_preferences', 0);
    }

    public function test_admin_personal_layouts_are_separate_for_each_company_and_do_not_change_shared_layout(): void
    {
        $company = Perusahaan::factory()->create();
        $other = Perusahaan::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson(route('hrd.preferences', ['company' => $company->id]), ['widgets' => [$this->widget('gender')]])->assertOk();
        $this->putJson(route('hrd.preferences', ['company' => $other->id]), ['widgets' => [$this->widget('age')]])->assertOk();

        $this->getJson(route('hrd.data', ['company' => $company->id]))->assertOk()->assertJsonPath('layout.0.source', 'gender');
        $this->getJson(route('hrd.data', ['company' => $other->id]))->assertOk()->assertJsonPath('layout.0.source', 'age');
        $this->assertDatabaseCount('hrd_dashboard_preferences', 2);
        $this->assertNull($company->fresh()->dashboard_layout);
        $this->assertNull($other->fresh()->dashboard_layout);
    }

    public function test_hrd_page_exposes_inline_editor_and_catalog_with_company_scoped_data(): void
    {
        $company = Perusahaan::factory()->create(['nama_perusahaan' => '<script>Perusahaan</script>']);
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]))
            ->get(route('dashboard'))->assertOk()->assertSee('data-hrd-catalog', false)->assertSee('data-hrd-builder-save', false)
            ->assertSee('data-hrd-builder', false)->assertSee($company->nama_perusahaan)->assertDontSee($company->nama_perusahaan, false);
    }

    public function test_workspace_preserves_the_original_automatic_sources_instead_of_the_replacement_13_widget_layout(): void
    {
        $participant = Karyawan::factory()->create();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);

        $response = $this->actingAs($hrd)->getJson(route('hrd.data', ['workspace' => true]))->assertOk()->assertJsonPath('layout.0.source', 'stat:planned');

        $this->assertSame(array_column($response->json('sources'), 'id'), array_column($response->json('layout'), 'source'));
        $this->assertDatabaseCount('hrd_dashboard_preferences', 0);
    }

    public function test_csv_recap_uses_the_current_users_dashboard_instead_of_the_shared_layout(): void
    {
        $participant = Karyawan::factory()->create(['bagian' => 'Produksi']);
        $participant->perusahaan->update(['dashboard_layout' => [array_replace($this->widget('gender'), ['title' => 'Judul perusahaan'])]]);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        HrdDashboardPreference::factory()->create(['user_id' => $hrd->id, 'perusahaan_id' => $participant->perusahaan_id, 'widgets' => [array_replace($this->widget('department'), ['title' => 'Judul pribadi'])]]);

        $response = $this->actingAs($hrd)->get(route('hrd.recap', ['workspace' => true]))->assertOk()->assertDownload('Rekap_MCU.csv');

        $this->assertStringContainsString('Judul pribadi', $response->streamedContent());
        $this->assertStringNotContainsString('Judul perusahaan', $response->streamedContent());
    }

    public function test_saving_after_soft_deletion_restores_the_same_preference_without_duplicate_owners(): void
    {
        $company = Perusahaan::factory()->create();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $company->id]);
        $preference = HrdDashboardPreference::factory()->create(['user_id' => $hrd->id, 'perusahaan_id' => $company->id, 'widgets' => []]);
        $preference->delete();

        $this->actingAs($hrd)->putJson(route('hrd.preferences'), ['widgets' => [$this->widget('age')]])->assertOk();

        $this->assertDatabaseCount('hrd_dashboard_preferences', 1);
        $this->assertNull($preference->fresh()->deleted_at);
        $this->assertSame([$this->widget('age')], $preference->fresh()->widgets);
    }

    /** @return array{source: string, title: string, chart: string, metric: string, width: int, height: int} */
    private function widget(string $source): array
    {
        return ['source' => $source, 'title' => 'Widget pribadi', 'chart' => 'bar', 'metric' => 'count', 'width' => 1, 'height' => 1];
    }
}
