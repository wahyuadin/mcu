<?php

namespace Tests\Feature;

use App\Models\Perusahaan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_can_be_created_without_logo_and_displays_default_icon(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post(route('perusahaan.store'), ['nama_perusahaan' => 'Tanpa logo'])->assertSessionHasNoErrors();

        $company = Perusahaan::firstOrFail();
        $this->assertNull($company->logo_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->get(route('perusahaan.index'))->assertOk()->assertSee('Tanpa logo')->assertSee('data-company-default-icon', false)->assertSee('Logo perusahaan (opsional)')->assertSee('enctype="multipart/form-data"', false);
        $this->get(route('perusahaan.logo', $company))->assertNotFound();
    }

    public function test_uploaded_logo_is_resized_to_private_webp_and_displayed(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post(route('perusahaan.store'), ['nama_perusahaan' => 'Dengan logo', 'logo' => UploadedFile::fake()->image('logo.png', 1024, 512)])->assertSessionHasNoErrors();

        $company = Perusahaan::firstOrFail();
        Storage::disk('local')->assertExists($company->logo_path);
        $dimensions = getimagesizefromstring(Storage::disk('local')->get($company->logo_path));
        $this->assertSame('image/webp', $dimensions['mime']);
        $this->assertSame(512, $dimensions[0]);
        $this->assertSame(256, $dimensions[1]);
        $this->get(route('perusahaan.index'))->assertOk()->assertSee('alt="Logo Dengan logo"', false)->assertSee(route('perusahaan.logo', $company));
        $this->get(route('perusahaan.logo', $company))->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_existing_company_logo_can_be_uploaded_and_replaced_without_changing_identity(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $company = Perusahaan::factory()->create();
        $name = $company->nama_perusahaan;
        $url = route('perusahaan.logo.update', $company);

        $this->put($url, ['logo' => UploadedFile::fake()->image('first.jpg')])->assertSessionHasNoErrors();
        $firstPath = $company->fresh()->logo_path;
        $this->put($url, ['logo' => UploadedFile::fake()->image('second.png'), 'nama_perusahaan' => 'Tidak boleh berubah'])->assertSessionHasNoErrors();

        $this->assertNotSame($firstPath, $company->fresh()->logo_path);
        Storage::disk('local')->assertMissing($firstPath);
        $this->assertSame($name, $company->fresh()->nama_perusahaan);
        Storage::disk('local')->assertExists($company->fresh()->logo_path);
        $this->put($url, [])->assertSessionHasErrors('logo');
    }

    public function test_invalid_or_oversized_upload_does_not_create_company_or_replace_existing_logo(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('perusahaan.store'), ['nama_perusahaan' => 'Ditolak', 'logo' => UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('logo');
        $this->post(route('perusahaan.store'), ['nama_perusahaan' => 'Ditolak', 'logo' => UploadedFile::fake()->image('large.png')->size(2049)])->assertSessionHasErrors('logo');
        $this->assertDatabaseCount('perusahaans', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $company = Perusahaan::factory()->create(['logo_path' => 'perusahaan/original.webp']);
        Storage::disk('local')->put($company->logo_path, 'original');

        $this->put(route('perusahaan.logo.update', $company), ['logo' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])->assertSessionHasErrors('logo');

        $this->assertSame('perusahaan/original.webp', $company->fresh()->logo_path);
        $this->assertSame('original', Storage::disk('local')->get($company->logo_path));
    }

    public function test_missing_file_returns_not_found_and_guests_and_operators_cannot_manage_logos(): void
    {
        Storage::fake('local');
        $company = Perusahaan::factory()->create(['logo_path' => 'perusahaan/missing.webp']);
        $url = route('perusahaan.logo', $company);
        $this->get($url)->assertRedirect(route('login'));
        $this->put($url, [])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->get($url)->assertNotFound();
        $this->put($url, [])->assertForbidden();
        $this->post(route('perusahaan.store'), ['nama_perusahaan' => 'Tidak diizinkan'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get($url)->assertNotFound();
        $this->assertDatabaseCount('perusahaans', 1);
    }
}
