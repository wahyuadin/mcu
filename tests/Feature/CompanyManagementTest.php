<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_list_has_edit_and_delete_actions_and_edit_form_preserves_current_data(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create(['nama_perusahaan' => 'PT Contoh <script>alert(1)</script>', 'alamat' => 'Alamat perusahaan', 'email' => 'admin@example.test', 'telp' => '021-12345678']);

        $this->get(route('perusahaan.index'))->assertOk()->assertSee(route('perusahaan.edit', $company))->assertSee(route('perusahaan.destroy', $company))
            ->assertSee('Edit')->assertSee('Hapus')->assertDontSee('<script>alert(1)</script>', false);
        $this->get(route('perusahaan.edit', $company))->assertOk()->assertSee('Edit Perusahaan')->assertSee($company->nama_perusahaan)
            ->assertSee('Alamat perusahaan')->assertSee('admin@example.test')->assertSee('021-12345678')
            ->assertSee(route('perusahaan.update', $company))->assertSee(route('perusahaan.logo.update', $company))->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_company_details_can_be_updated_without_changing_logo_or_related_visits(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create(['logo_path' => 'perusahaan/original.webp']);
        Storage::disk('local')->put($company->logo_path, 'original');
        $visit = RencanaKunjungan::factory()->create(['perusahaan_id' => $company->id]);
        $visitBefore = $visit->fresh()->toArray();
        $data = ['nama_perusahaan' => 'PT Nama Baru', 'alamat' => 'Jl. Baru No. 10', 'email' => 'baru@example.test', 'telp' => '08123456789'];

        $this->put(route('perusahaan.update', $company), $data + ['logo_path' => 'forged.webp'])->assertRedirect(route('perusahaan.index'))->assertSessionHasNoErrors();

        $this->assertSame($data, $company->fresh()->only(array_keys($data)));
        $this->assertSame('perusahaan/original.webp', $company->fresh()->logo_path);
        $this->assertSame('original', Storage::disk('local')->get($company->logo_path));
        $this->assertSame($visitBefore, $visit->fresh()->toArray());
        $this->get(route('kunjungan-mcu.show', $visit))->assertOk()->assertSee('PT Nama Baru')->assertSee('Jl. Baru No. 10')->assertSee('baru@example.test');
    }

    public function test_invalid_company_updates_keep_saved_details_and_form_retains_submitted_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create();
        $before = $company->fresh()->toArray();
        $url = route('perusahaan.update', $company);

        $this->from(route('perusahaan.edit', $company))->put($url, ['nama_perusahaan' => 'Draft perusahaan', 'email' => 'invalid-email'])->assertRedirect(route('perusahaan.edit', $company))->assertSessionHasErrors('email');
        $this->get(route('perusahaan.edit', $company))->assertOk()->assertSee('value="Draft perusahaan"', false)->assertSee('value="invalid-email"', false);
        $this->put($url, ['nama_perusahaan' => ''])->assertSessionHasErrors('nama_perusahaan');
        $this->put($url, ['nama_perusahaan' => str_repeat('a', 256)])->assertSessionHasErrors('nama_perusahaan');
        $this->assertSame($before, $company->fresh()->toArray());
    }

    public function test_unused_company_can_be_soft_deleted_and_is_removed_from_company_options(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create(['nama_perusahaan' => 'Perusahaan tanpa data MCU']);

        $this->delete(route('perusahaan.destroy', $company))->assertRedirect(route('perusahaan.index'))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($company);
        $this->get(route('perusahaan.index'))->assertOk()->assertDontSee('Perusahaan tanpa data MCU');
        $this->get(route('rencana-kunjungan.index'))->assertOk()->assertDontSee('Perusahaan tanpa data MCU');
        $this->get(route('perusahaan.edit', $company))->assertNotFound();
        $this->delete(route('perusahaan.destroy', $company))->assertNotFound();
    }

    #[TestWith(['kunjungan', false])]
    #[TestWith(['kunjungan', true])]
    #[TestWith(['peserta', false])]
    #[TestWith(['peserta', true])]
    #[TestWith(['paket', false])]
    #[TestWith(['paket', true])]
    public function test_company_with_current_or_archived_mcu_data_cannot_be_deleted(string $relation, bool $archived): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create();
        $related = match ($relation) {
            'kunjungan' => RencanaKunjungan::factory()->create(['perusahaan_id' => $company->id]),
            'peserta' => Karyawan::factory()->create(['perusahaan_id' => $company->id]),
            'paket' => Paket::factory()->master()->create(['perusahaan_id' => $company->id]),
        };
        if ($archived) {
            $related->delete();
        }
        $before = $related->fresh()->toArray();

        $this->from(route('perusahaan.index'))->delete(route('perusahaan.destroy', $company))->assertRedirect(route('perusahaan.index'))
            ->assertSessionHasErrors(['perusahaan' => 'Perusahaan tidak dapat dihapus karena sudah memiliki kunjungan, peserta, atau paket MCU.']);

        $this->assertNotSoftDeleted($company);
        $this->assertSame($before, $related->fresh()->toArray());
    }

    public function test_guests_and_non_admin_operators_cannot_edit_update_or_delete_companies(): void
    {
        $company = Perusahaan::factory()->create();
        $before = $company->fresh()->toArray();
        $this->get(route('perusahaan.edit', $company))->assertRedirect(route('login'));
        $this->put(route('perusahaan.update', $company), ['nama_perusahaan' => 'Ditolak'])->assertRedirect(route('login'));
        $this->delete(route('perusahaan.destroy', $company))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->get(route('perusahaan.edit', $company))->assertForbidden();
        $this->put(route('perusahaan.update', $company), ['nama_perusahaan' => 'Ditolak'])->assertForbidden();
        $this->delete(route('perusahaan.destroy', $company))->assertForbidden();
        $this->assertSame($before, $company->fresh()->toArray());
    }
}
