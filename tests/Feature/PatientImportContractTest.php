<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\PatientImportReceipt;
use App\Models\RencanaKunjungan;
use App\Models\User;
use App\PatientSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PatientImportContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_has_the_agreed_dummy_values_and_optional_nik_comment(): void
    {
        $visit = RencanaKunjungan::factory()->create(['id' => '01a1805a-95a1-702e-b66d-7654f9955633']);
        $package = Paket::factory()->master()->create(['id' => '01a1805e-92a3-70ce-bfcf-8ac92d84a56c']);
        $book = (new PatientSpreadsheet)->template();
        try {
            $sheet = $book->getSheet(0);
            $this->assertSame([$visit->id, $package->id, 'Wahyu Adi Nugroho', '8841583952153971', 'NYK-19423', '1', 'Bandung', '2005-08-24', 'Operasional', 'Staff IT', '086001143473'], $sheet->rangeToArray('A2:K2')[0]);
            $this->assertStringContainsString('Opsional', $sheet->getComment('D1')->getText()->getPlainText());
            $this->assertStringContainsString('Wajib', $sheet->getComment('E1')->getText()->getPlainText());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_optional_columns_can_be_absent_and_import_leaves_mcu_date_empty_until_photo_is_saved(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->create();
        $values = ['kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, 'nama_karyawan' => 'Tanpa kolom opsional', 'nip' => 'NPK-001', 'jenis_kelamin' => '1', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional'];
        $package->rencanaKunjungan->update(['jumlah_peserta_direncanakan' => 100]);
        $batch = $this->stage($values);
        $this->assertSame(1, $batch['stats']['ready']);
        $this->assertNull($batch['mapping']['nik']);
        $this->assertArrayNotHasKey('tanggal_mcu', $batch['mapping']);
        $this->assertArrayNotHasKey('tanggal_mcu', $batch['rows'][0]['data']);
        $this->assertSame(['kunjungan_id', 'paket_id', 'nama_karyawan', 'nip', 'jenis_kelamin', 'tanggal_lahir', 'bagian'], $batch['required_fields']);
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1);
        $participant = Karyawan::where('nama_karyawan', $values['nama_karyawan'])->firstOrFail();
        foreach (['nik', 'tempat_lahir', 'jabatan', 'telepon', 'tanggal_mcu'] as $field) {
            $this->assertNull($participant->$field);
        }
        $this->travelTo(now()->setDate(2026, 11, 1));
        $this->post(route('pemeriksaan.photo', [$participant->rencana_kunjungan_id, $participant]), ['foto_pasien' => UploadedFile::fake()->image('first.jpg')])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-01', $participant->fresh()->tanggal_mcu->toDateString());
        $this->travel(2)->days();
        $this->post(route('pemeriksaan.photo', [$participant->rencana_kunjungan_id, $participant]), ['foto_pasien' => UploadedFile::fake()->image('replacement.jpg')])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-01', $participant->fresh()->tanggal_mcu->toDateString());
    }

    public function test_legacy_mcu_date_is_ignored_and_no_longer_offered_in_mapping(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->create();
        $package->rencanaKunjungan->update(['jumlah_peserta_direncanakan' => 100]);
        $batch = $this->stage(['kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, 'nama_karyawan' => 'Template lama', 'nip' => 'NPK-OLD', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', 'tanggal_mcu' => '2020-01-01']);
        $this->assertSame(1, $batch['stats']['ready']);
        $this->assertArrayNotHasKey('tanggal_mcu', $batch['mapping']);
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1);
        $this->assertNull(Karyawan::where('nama_karyawan', 'Template lama')->firstOrFail()->tanggal_mcu);
        $retry = $this->stage(['kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, 'nama_karyawan' => 'Template lama', 'nip' => 'NPK-OLD', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional']);
        $this->assertSame(1, $retry['stats']['existing']);
    }

    #[TestWith(['kunjungan_id'])]
    #[TestWith(['paket_id'])]
    #[TestWith(['nama_karyawan'])]
    #[TestWith(['nip'])]
    #[TestWith(['jenis_kelamin'])]
    #[TestWith(['tanggal_lahir'])]
    #[TestWith(['bagian'])]
    public function test_all_create_form_required_fields_are_also_required_in_import(string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->create();
        $values = ['kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, 'nama_karyawan' => 'Wajib lengkap', 'nip' => 'NPK-01', 'jenis_kelamin' => '1', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', $field => ''];
        $batch = $this->stage($values);
        $this->assertSame(1, $batch['stats']['failed']);
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 0);
        $this->assertDatabaseCount('karyawans', 0);
    }

    #[TestWith(['nik'])]
    #[TestWith(['tempat_lahir'])]
    #[TestWith(['jabatan'])]
    #[TestWith(['telepon'])]
    public function test_optional_column_mapping_can_be_removed(string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->create();
        $values = ['kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, 'nama_karyawan' => 'Opsional', 'nip' => 'NPK-01', 'jenis_kelamin' => '1', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional', $field => $field === 'nik' ? '1234567890123456' : 'Contoh'];
        $package->rencanaKunjungan->update(['jumlah_peserta_direncanakan' => 100]);
        $batch = $this->stage($values);
        $mapping = [...$batch['mapping'], $field => null];
        $this->patchJson($batch['mapping_url'], ['version' => 0, 'mapping' => $mapping])->assertOk()->assertJsonPath('stats.ready', 1)->assertJsonPath('mapping.'.$field, null);
    }

    public function test_existing_receipt_hash_is_preserved_when_template_column_order_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $participant = Karyawan::factory()->create(['tanggal_mcu' => '2025-01-01', 'nik' => null, 'telepon' => null, 'jabatan' => null, 'tempat_lahir' => null]);
        $legacyData = ['nama_karyawan' => $participant->nama_karyawan, 'nik' => null, 'nip' => $participant->nip, 'jenis_kelamin' => $participant->jenis_kelamin, 'tanggal_lahir' => $participant->tanggal_lahir->toDateString(), 'bagian' => $participant->bagian, 'jabatan' => null, 'telepon' => null, 'tanggal_mcu' => null, 'kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $participant->paket_id];
        PatientImportReceipt::create(['fingerprint' => hash_hmac('sha256', json_encode($legacyData, JSON_THROW_ON_ERROR), config('app.key')), 'karyawan_id' => $participant->id]);
        unset($legacyData['tanggal_mcu']);
        $batch = $this->stage($legacyData);
        $this->assertSame(1, $batch['stats']['existing']);
        $this->assertSame('2025-01-01', $participant->fresh()->tanggal_mcu->toDateString());
        $this->assertDatabaseCount('karyawans', 1);
    }

    #[TestWith(['123'])]
    #[TestWith(['12345678901234567'])]
    #[TestWith(['123456789012345X'])]
    public function test_nik_must_be_sixteen_digits_only_when_it_is_filled(string $nik): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $package = Paket::factory()->create();
        $batch = $this->stage(['kunjungan_id' => $package->rencana_kunjungan_id, 'paket_id' => $package->id, 'nama_karyawan' => 'NIK salah', 'nik' => $nik, 'nip' => 'NPK-01', 'jenis_kelamin' => '1', 'tanggal_lahir' => '1990-01-01', 'bagian' => 'Operasional']);
        $this->assertSame(1, $batch['stats']['failed']);
        $this->assertStringContainsString('nik harus 16 digit.', implode(' ', $batch['rows'][0]['errors']));
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 0);
        $this->assertDatabaseCount('karyawans', 0);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function stage(array $values): array
    {
        $book = new Spreadsheet;
        (new PatientSpreadsheet)->writeSheet($book->getActiveSheet()->setTitle('Pasien'), array_keys($values), [array_values($values)]);
        $path = 'import-contract-'.Str::uuid().'.xlsx';
        try {
            (new Xlsx($book))->save(Storage::disk('local')->path($path));
            $file = new UploadedFile(Storage::disk('local')->path($path), 'pasien.xlsx', null, null, true);

            return $this->postJson(route('pasien.import.store'), ['import_file' => $file])->assertOk()->json();
        } finally {
            $book->disconnectWorksheets();
            Storage::disk('local')->delete($path);
        }

    }
}
