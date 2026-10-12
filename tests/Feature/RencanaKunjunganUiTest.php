<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RencanaKunjunganUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_list_shows_company_logo_beside_name_and_default_icon_when_no_logo_is_registered(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create(['nama_perusahaan' => 'PT. Toyota Manufacturing', 'logo_path' => 'perusahaan/toyota.webp']);
        RencanaKunjungan::factory()->create(['perusahaan_id' => $company->id]);
        RencanaKunjungan::factory()->create(['perusahaan_id' => Perusahaan::factory()->create(['logo_path' => null])->id]);

        $this->get(route('rencana-kunjungan.index'))->assertOk()
            ->assertSee('src="'.route('perusahaan.logo', $company).'"', false)
            ->assertSee('alt="Logo PT. Toyota Manufacturing"', false)
            ->assertSee('data-company-default-icon', false);
    }

    public function test_new_plan_defaults_to_today_and_fourteen_days_later_without_quota_input(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 25)->startOfDay());
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('rencana-kunjungan.index'))->assertOk()
            ->assertSee('value="2026-12-25"', false)->assertSee('value="2027-01-08"', false)
            ->assertDontSee('name="kuota_peserta"', false);
    }

    public function test_unlimited_plan_accepts_registration_from_both_entry_points_and_patient_transfer(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $package = Paket::factory()->create(['rencana_kunjungan_id' => $visit->id]);
        $payload = ['rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id, 'nama_karyawan' => 'Peserta tanpa kuota', 'jenis_kelamin' => 'L'];
        $this->post(route('peserta.store', $visit), $payload)->assertSessionHasNoErrors();
        $this->post(route('pasien.store'), $payload)->assertSessionHasNoErrors();
        $participant = Karyawan::factory()->create();
        $this->put(route('pasien.update', $participant), $payload)->assertSessionHasNoErrors();
        $this->assertSame($visit->id, $participant->fresh()->rencana_kunjungan_id);
        $this->assertSame(3, $visit->karyawans()->count());
    }

    public function test_existing_positive_quota_still_limits_registration(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 1]);
        $package = Paket::factory()->create(['rencana_kunjungan_id' => $visit->id]);
        Karyawan::factory()->create(['rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id]);
        $payload = ['rencana_kunjungan_id' => $visit->id, 'paket_id' => $package->id, 'nama_karyawan' => 'Peserta tambahan', 'jenis_kelamin' => 'L'];
        $this->post(route('peserta.store', $visit), $payload)->assertSessionHasErrors('nama_karyawan');
        $this->post(route('pasien.store'), $payload)->assertSessionHasErrors('rencana_kunjungan_id');
        $this->assertSame(1, $visit->karyawans()->count());
    }

    public function test_planning_list_filters_by_company_search_and_status_without_changing_global_counts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create(['nama_perusahaan' => 'Perusahaan Rencana']);
        $draft = RencanaKunjungan::factory()->create(['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'MCU-RNC-001', 'status' => 'draft']);
        RencanaKunjungan::factory()->create(['status' => 'ongoing']);
        RencanaKunjungan::factory()->create(['status' => 'completed', 'is_closing' => true]);
        $this->get(route('rencana-kunjungan.index', ['q' => 'MCU-RNC', 'perusahaan' => $company->id, 'status' => 'draft']))
            ->assertOk()->assertSee('Registrasi PKS & perencanaan', false)->assertSee('Simpan rencana kunjungan')
            ->assertSee('Alur setelah registrasi')->assertSee('Detail rencana')
            ->assertViewHas('kunjungans', fn ($visits): bool => $visits->total() === 1 && $visits->first()->id === $draft->id)
            ->assertViewHas('stats', fn (array $stats): bool => $stats['total'] === 3 && $stats['draft'] === 1 && $stats['ongoing'] === 1 && $stats['closed'] === 1);
        $this->get(route('rencana-kunjungan.index', ['q' => 'Perusahaan Rencana']))->assertOk()
            ->assertViewHas('kunjungans', fn ($visits): bool => $visits->total() === 1);
    }

    public function test_closed_and_completed_filters_are_distinct_and_pagination_preserves_search(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        RencanaKunjungan::factory()->count(21)->create(['status' => 'completed', 'is_closing' => false, 'periode_kunjungan' => 'Oktober 2026']);
        $closed = RencanaKunjungan::factory()->create(['status' => 'completed', 'is_closing' => true]);
        $this->get(route('rencana-kunjungan.index', ['status' => 'completed', 'q' => 'Oktober']))->assertOk()
            ->assertViewHas('kunjungans', fn ($visits): bool => $visits->total() === 21 && $visits->count() === 20)
            ->assertSee('status=completed', false)->assertSee('q=Oktober', false);
        $this->get(route('rencana-kunjungan.index', ['status' => 'closed']))->assertOk()
            ->assertViewHas('kunjungans', fn ($visits): bool => $visits->total() === 1 && $visits->first()->id === $closed->id);
    }

    public function test_registration_form_preserves_validation_input_and_still_creates_a_plan(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create();
        $payload = ['perusahaan_id' => $company->id, 'nomor_kunjungan' => 'MCU-PLAN-001', 'periode_kunjungan' => 'November 2026', 'tgl_mulai' => '2026-11-02', 'tgl_selesai' => '2026-11-01'];
        $this->from(route('rencana-kunjungan.index'))->post(route('rencana-kunjungan.store'), $payload)
            ->assertRedirect(route('rencana-kunjungan.index'))->assertSessionHasErrors('tgl_selesai');
        $this->get(route('rencana-kunjungan.index'))->assertOk()->assertSee('value="MCU-PLAN-001"', false)
            ->assertSee('value="2026-11-02"', false)->assertSee('value="2026-11-01"', false);
        $payload['tgl_selesai'] = '2026-11-03';
        $response = $this->post(route('rencana-kunjungan.store'), $payload);
        $visit = RencanaKunjungan::where('nomor_kunjungan', 'MCU-PLAN-001')->firstOrFail();
        $response->assertRedirect(route('rencana-kunjungan.show', $visit))->assertSessionHasNoErrors();
        $this->assertSame(0, $visit->kuota_peserta);
        $this->assertSame('2026-11-03', $visit->tgl_selesai->format('Y-m-d'));
    }

    public function test_empty_state_and_admin_access_are_preserved(): void
    {
        $this->get(route('rencana-kunjungan.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'petugas']))->get(route('rencana-kunjungan.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('rencana-kunjungan.index'))->assertOk()
            ->assertSee('Belum ada rencana kunjungan')->assertSee('Daftarkan perusahaan terlebih dahulu.');
    }

    public function test_company_names_are_escaped_in_planning_list_and_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create(['nama_perusahaan' => '<script>alert(1)</script>']);
        RencanaKunjungan::factory()->create(['perusahaan_id' => $company->id]);
        $this->get(route('rencana-kunjungan.index'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }
}
