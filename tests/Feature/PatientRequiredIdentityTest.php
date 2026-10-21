<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\PatientImportBatch;
use App\Models\User;
use App\PatientImport;
use App\PatientSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PatientRequiredIdentityTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['nip', null])]
    #[TestWith(['nip', '   '])]
    #[TestWith(['nik', '123'])]
    #[TestWith(['tanggal_lahir', null])]
    #[TestWith(['tanggal_lahir', '2099-01-01'])]
    #[TestWith(['bagian', '   '])]
    public function test_create_edit_modal_and_visit_registration_reject_invalid_identity(string $field, ?string $value): void
    {
        $participant = Karyawan::factory()->create(['nik' => '0012345678901234']);
        $data = ['nip' => 'EMP-001', 'rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $participant->paket_id, 'nama_karyawan' => 'Pasien Baru', 'jenis_kelamin' => 'L', 'nik' => '0012345678901234', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', $field => $value];
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post(route('pasien.store'), $data)->assertSessionHasErrors($field);
        $this->post(route('peserta.store', $participant->rencanaKunjungan), $data)->assertSessionHasErrors($field);
        $this->put(route('pasien.update', $participant), $data)->assertSessionHasErrors($field);
        $this->put(route('pasien.update', $participant), [...$data, 'identity_modal' => '1'])->assertSessionHasErrors($field);

        $this->assertDatabaseCount('karyawans', 1);
        $this->assertNotSame('Pasien Baru', $participant->fresh()->nama_karyawan);
    }

    public function test_valid_identity_is_saved_and_nik_leading_zeroes_are_preserved(): void
    {
        $package = Paket::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post(route('pasien.store'), ['nip' => 'EMP-001', 'rencana_kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, 'nama_karyawan' => 'Pasien Lengkap', 'jenis_kelamin' => 'P', 'nik' => '0012345678901234', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Keuangan'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('karyawans', ['nik' => '0012345678901234', 'bagian' => 'Keuangan']);
    }

    public function test_existing_master_identity_is_validated_before_creating_an_episode(): void
    {
        $participant = Karyawan::factory()->create(['nik' => null, 'tanggal_lahir' => null]);
        $package = Paket::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post(route('pasien.store'), ['nip' => 'EMP-001', 'pasien_id' => $participant->pasien_id, 'rencana_kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, 'bagian' => 'Operasional', 'nik' => '0012345678901234', 'tanggal_lahir' => '1990-01-01'])->assertSessionHasErrors(['tanggal_lahir']);

        $this->assertDatabaseCount('karyawans', 1);
    }

    public function test_optional_national_nik_does_not_block_create_edit_or_existing_master_registration(): void
    {
        $package = Paket::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['rencana_kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, 'nama_karyawan' => 'Tanpa NIK KTP', 'jenis_kelamin' => 'L', 'nip' => '001EMP-01', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional'];

        $this->post(route('pasien.store'), $data)->assertSessionHasNoErrors();
        $participant = Karyawan::firstOrFail();
        $this->put(route('pasien.update', $participant), [...$data, 'identity_modal' => '1', 'nik' => null])->assertSessionHasNoErrors();
        $other = Paket::factory()->create();
        $this->post(route('pasien.store'), ['pasien_id' => $participant->pasien_id, 'rencana_kunjungan_id' => $other->rencana_kunjungan_id, 'paket_id' => $other->id, 'nip' => 'EMP-002', 'bagian' => 'Keuangan'])->assertSessionHasNoErrors();
        $this->post(route('peserta.store', $other->rencanaKunjungan), [...$data, 'paket_id' => $other->id, 'nip' => 'EMP-003'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('karyawans', 3);
        $this->assertNull($participant->fresh()->nik);
        $this->assertSame('001EMP-01', $participant->fresh()->nip);
    }

    public function test_create_edit_and_identity_modal_mark_the_three_fields_as_required(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        foreach ([route('pasien.create'), route('pasien.edit', $participant), route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas'])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach (['nip', 'tanggal_lahir', 'bagian'] as $field) {
                $this->assertMatchesRegularExpression('/<input\b[^>]*name="'.$field.'"[^>]*\brequired\b/', $html);
            }
            $this->assertDoesNotMatchRegularExpression('/<input\b[^>]*name="nik"[^>]*\brequired\b/', $html);
        }
    }

    #[TestWith(['nip'])]
    #[TestWith(['tanggal_lahir'])]
    #[TestWith(['bagian'])]
    public function test_import_mapping_cannot_remove_a_required_identity_column(string $field): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $mapping = array_combine(PatientSpreadsheet::HEADERS, PatientSpreadsheet::HEADERS);
        $batch = PatientImportBatch::factory()->create(['user_id' => $user->id, 'headers' => PatientSpreadsheet::HEADERS, 'mapping' => $mapping]);
        $mapping[$field] = null;

        $this->patchJson(route('pasien.import.update', $batch), ['version' => $batch->version, 'mapping' => $mapping])->assertUnprocessable()->assertJsonValidationErrors('mapping.'.$field);

        $this->assertSame($field, $batch->fresh()->mapping[$field]);
    }

    #[TestWith(['nip', null])]
    #[TestWith(['tanggal_lahir', null])]
    #[TestWith(['bagian', null])]
    public function test_import_preview_and_confirmation_reject_rows_with_missing_required_fields(string $field, ?string $value): void
    {
        $package = Paket::factory()->create();
        $row = array_fill_keys(PatientSpreadsheet::HEADERS, null);
        $row = array_replace($row, ['nip' => 'EMP-001', 'nama_karyawan' => 'Import Pasien', 'nik' => '0012345678901234', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'jenis_kelamin' => 'L', 'kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, $field => $value]);
        $batch = PatientImportBatch::factory()->create(['headers' => PatientSpreadsheet::HEADERS, 'mapping' => array_combine(PatientSpreadsheet::HEADERS, PatientSpreadsheet::HEADERS), 'source_rows' => [2 => array_map(fn ($value): array => ['value' => $value, 'type' => DataType::TYPE_STRING, 'error' => null, 'cached' => null], array_values($row))]]);

        $rows = (new PatientImport)->preview($batch);
        $this->assertSame('failed', $rows[0]['status']);
        $this->assertStringContainsString(str_replace('_', ' ', $field).' wajib diisi.', implode(' ', $rows[0]['errors']));
        (new PatientImport)->confirm($batch, $batch->version);

        $this->assertDatabaseCount('karyawans', 0);
    }
}
