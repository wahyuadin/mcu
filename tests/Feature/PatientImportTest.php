<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\PatientImportBatch;
use App\Models\RencanaKunjungan;
use App\Models\User;
use App\PatientSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PatientImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_has_example_patient_and_current_master_lookup_sheets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create();
        $package = Paket::factory()->master()->create();
        $closed = RencanaKunjungan::factory()->create(['is_closing' => true]);
        $this->get(route('pasien.index'))->assertOk()->assertSee('data-patient-import-drop', false)->assertSee('Upload Data')->assertSee('Unduh template .xlsx');
        $response = $this->get(route('pasien.import.template'))->assertOk()->assertDownload('template-import-pasien.xlsx');
        $book = $this->readBook($response->streamedContent());
        $this->assertSame(['Pasien', 'Master Kunjungan MCU', 'Master Paket', 'Jenis Kelamin'], $book->getSheetNames());
        $this->assertSame(PatientSpreadsheet::HEADERS, $book->getSheet(0)->rangeToArray('A1:L1')[0]);
        $sample = $book->getSheet(0);
        $this->assertSame('Wahyu Adi Nugroho', $sample->getCell('A2')->getValue());
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{15}$/', $sample->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sample->getCell('B2')->getDataType());
        $this->assertSame('NYK-19423', $sample->getCell('C2')->getValue());
        $this->assertSame('1', $sample->getCell('D2')->getValue());
        $this->assertSame('2005-08-24', $sample->getCell('E2')->getValue());
        $this->assertMatchesRegularExpression('/^08[0-9]{10}$/', $sample->getCell('H2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sample->getCell('H2')->getDataType());
        $this->assertSame($visit->id, $sample->getCell('J2')->getValue());
        $this->assertSame($package->id, $sample->getCell('K2')->getValue());
        $this->assertSame('Bandung', $sample->getCell('L2')->getValue());
        $this->assertSame('@', $book->getSheet(0)->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame($visit->id, $book->getSheet(1)->getCell('A2')->getValue());
        $this->assertStringNotContainsString($closed->id, json_encode($book->getSheet(1)->toArray()));
        $this->assertSame($package->id, $book->getSheet(2)->getCell('A2')->getValue());
        $this->assertSame([
            ['jenis_kelamin', 'keterangan'],
            ['1', 'Laki-laki'],
            ['2', 'Perempuan'],
        ], $book->getSheet(3)->rangeToArray('A1:B3'));
        $this->assertSame($package->nama_paket, $book->getSheet(2)->getCell('B2')->getValue());
        $this->assertNull($book->getSheet(2)->getCell('E1')->getValue());
        $this->assertNull($book->getSheet(2)->getCell('E2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_gender_reference_remains_available_without_any_master_packages(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $book = $this->readBook($this->get(route('pasien.import.template'))->assertOk()->streamedContent());

        $this->assertCount(4, $book->getSheetNames());
        $this->assertNull($book->getSheet(2)->getCell('A2')->getValue());
        $this->assertNull($book->getSheet(0)->getCell('J2')->getValue());
        $this->assertNull($book->getSheet(0)->getCell('K2')->getValue());
        $this->assertSame([['1', 'Laki-laki'], ['2', 'Perempuan']], $book->getSheet(3)->rangeToArray('A2:B3'));
        $book->disconnectWorksheets();
    }

    public function test_numeric_and_text_gender_codes_are_normalized_and_unknown_codes_are_skipped(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $package = Paket::factory()->master()->create();
        $rows = [];
        foreach ([1, 2, '1', '2', 'L', 'P', 0, 3, ''] as $index => $gender) {
            $row = $this->row('Pasien '.$index, $visit, $package);
            $row[3] = $gender;
            $rows[] = $row;
        }

        $batch = $this->stage($rows);

        $this->assertSame(6, $batch['stats']['ready']);
        $this->assertSame(3, $batch['stats']['failed']);
        $this->assertSame(['L', 'P', 'L', 'P', 'L', 'P'], array_column(array_column(array_slice($batch['rows'], 0, 6), 'data'), 'jenis_kelamin'));
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 6)->assertJsonPath('stats.failed', 3);
        foreach (['L', 'P', 'L', 'P', 'L', 'P'] as $index => $gender) {
            $this->assertDatabaseHas('karyawans', ['nama_karyawan' => 'Pasien '.$index, 'jenis_kelamin' => $gender]);
            $this->assertDatabaseHas('pasiens', ['nama' => 'Pasien '.$index, 'jenis_kelamin' => $gender]);
        }
        $this->assertDatabaseCount('karyawans', 6);
        $this->assertDatabaseCount('pasiens', 6);
        $rows[0][3] = 'L';
        $rows[1][3] = 'P';
        $retry = $this->stage(array_slice($rows, 0, 6));
        $this->assertSame(6, $retry['stats']['existing']);
        $this->assertSame(0, $retry['stats']['ready']);
    }

    public function test_filled_example_template_round_trips_birthplace_and_rejects_oversized_birthplaces(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $package = Paket::factory()->master()->create();
        $file = $this->saveBook(app(PatientSpreadsheet::class)->template());

        $batch = $this->postJson(route('pasien.import.store'), ['import_file' => $file])->assertOk()->assertJsonPath('stats.ready', 1)->assertJsonPath('rows.0.data.tempat_lahir', 'Bandung')->json();
        $this->assertDatabaseCount('pasiens', 0);
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1);

        $this->assertDatabaseHas('pasiens', ['nama' => 'Wahyu Adi Nugroho', 'tempat_lahir' => 'Bandung', 'jenis_kelamin' => 'L']);
        $this->assertSame('2005-08-24', Karyawan::where('nama_karyawan', 'Wahyu Adi Nugroho')->firstOrFail()->pasien->tanggal_lahir->toDateString());
        $this->assertDatabaseHas('karyawans', ['nama_karyawan' => 'Wahyu Adi Nugroho', 'tempat_lahir' => 'Bandung', 'nip' => 'NYK-19423', 'paket_id' => $package->id, 'rencana_kunjungan_id' => $visit->id]);
        $row = $this->row('Tempat terlalu panjang', $visit, $package);
        $row[] = str_repeat('x', 256);
        $invalid = $this->stage([$row]);
        $this->assertSame(1, $invalid['stats']['failed']);
        $this->assertStringContainsString('tempat lahir maksimal 255 karakter', implode(' ', $invalid['rows'][0]['errors']));
        $this->assertDatabaseCount('pasiens', 1);
    }

    public function test_upload_only_previews_and_confirmation_preserves_text_dates_and_per_row_placement(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $second = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $package = Paket::factory()->master()->create();
        $another = Paket::factory()->master()->create();
        $batch = $this->stage([
            ['Ayu', '0012345678901234', 'EMP-001', 'P', '1990-01-01', 'Produksi', 'Operator', '081234567890', '', $visit->id, $package->id],
            [],
            ['Budi', '', 'EMP-002', 'L', Date::PHPToExcel(new \DateTime('1991-02-03')), '', '', '', '2026-10-09', $second->id, $another->id],
        ]);
        $this->assertSame(2, $batch['stats']['ready']);
        $this->assertSame(0, $batch['version']);
        $this->assertSame(4, $batch['rows'][1]['number']);
        $this->assertDatabaseCount('pasiens', 0);
        $this->assertDatabaseCount('karyawans', 0);
        $this->assertFalse($visit->pakets()->whereKey($package->id)->exists());
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 2);
        $ayu = Karyawan::where('nama_karyawan', 'Ayu')->firstOrFail();
        $this->assertSame('0012345678901234', $ayu->nik);
        $this->assertSame('081234567890', $ayu->telepon);
        $this->assertSame(now()->toDateString(), $ayu->tanggal_mcu->toDateString());
        $this->assertSame($visit->perusahaan_id, $ayu->perusahaan_id);
        $this->assertSame($package->id, $ayu->paket_id);
        $this->assertStringStartsWith('MCU-', $ayu->nomor_mcu);
        $this->assertNull($ayu->foto);
        $budi = Karyawan::where('nama_karyawan', 'Budi')->firstOrFail();
        $this->assertSame('1991-02-03', $budi->tanggal_lahir->toDateString());
        $this->assertSame($second->id, $budi->rencana_kunjungan_id);
        $this->assertTrue($visit->pakets()->whereKey($package->id)->exists());
        $this->assertTrue($second->pakets()->whereKey($another->id)->exists());
    }

    public function test_partial_import_report_preserves_failed_source_and_retry_does_not_duplicate_success(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $package = Paket::factory()->master()->create();
        $rows = [$this->row('Valid', $visit, $package), $this->row('Invalid', $visit, $package, 'X')];
        $batch = $this->stage($rows);
        $this->assertSame(1, $batch['stats']['ready']);
        $this->assertSame(1, $batch['stats']['failed']);
        $result = $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1)->assertJsonPath('stats.failed', 1)->json();
        $this->assertDatabaseCount('pasiens', 1);
        $report = $this->get($result['report_url'])->assertOk();
        $book = $this->readBook($report->streamedContent());
        $this->assertSame(['Pasien', 'Berhasil', 'Gagal'], $book->getSheetNames());
        $this->assertSame('Invalid', $book->getSheetByName('Pasien')->getCell('A2')->getValue());
        $this->assertSame('3', $book->getSheetByName('Gagal')->getCell('A2')->getValue());
        $this->assertSame('Invalid', $book->getSheetByName('Gagal')->getCell('B2')->getValue());
        $this->assertStringContainsString('harus 1 (Laki-laki) atau 2 (Perempuan)', $book->getSheetByName('Gagal')->getCell('N2')->getValue());
        $this->assertStringStartsWith('MCU-', $book->getSheetByName('Berhasil')->getCell('N2')->getValue());
        $book->disconnectWorksheets();
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1);
        $rows[1][3] = 'P';
        $retry = $this->stage($rows);
        $this->assertSame(1, $retry['stats']['existing']);
        $this->postJson($retry['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1)->assertJsonPath('stats.existing', 1);
        $this->assertDatabaseCount('karyawans', 2);
        $this->assertDatabaseCount('pasiens', 2);
    }

    public function test_confirmation_revalidates_closed_visit_deleted_package_and_quota(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $package = Paket::factory()->master()->create();
        $batch = $this->stage([$this->row('Satu', $visit, $package), $this->row('Dua', $visit, $package)]);
        $visit->update(['kuota_peserta' => 1]);
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1)->assertJsonPath('stats.failed', 1);
        $batch = $this->stage([$this->row('Tiga', $visit, $package)]);
        $visit->update(['is_closing' => true]);
        $package->delete();
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.failed', 1);
        $this->assertDatabaseCount('karyawans', 1);
    }

    public function test_duplicates_do_not_merge_identity_and_invalid_first_row_does_not_reserve_identifiers(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $existing = Karyawan::factory()->create(['nik' => '3171010101900001', 'nip' => 'EMP-01']);
        $visit = $existing->rencanaKunjungan;
        $visit->update(['kuota_peserta' => 0]);
        $package = $existing->paket;
        $rows = [$this->row('Invalid', $visit, $package, 'X'), $this->row('Valid', $visit, $package), $this->row('Duplicate', $visit, $package), $this->row('Existing NIK', $visit, $package), $this->row('Existing NIP', $visit, $package)];
        foreach ([0, 1, 2] as $index) {
            $rows[$index][1] = '3171010101900002';
        }
        $rows[3][1] = $existing->nik;
        $rows[4][2] = $existing->nip;
        $batch = $this->stage($rows);
        $this->assertSame(1, $batch['stats']['ready']);
        $this->assertSame(4, $batch['stats']['failed']);
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1);
        $this->assertDatabaseCount('pasiens', 2);
        $this->assertSame($existing->nama_karyawan, $existing->pasien->fresh()->nama);
    }

    public function test_custom_columns_can_be_mapped_and_stale_or_duplicate_mapping_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create();
        $package = Paket::factory()->master()->create();
        $batch = $this->stage([['Nama', 'L', $visit->id, $package->id]], ['Nama', 'Gender', 'Visit', 'Package']);
        $mapping = array_fill_keys(PatientSpreadsheet::HEADERS, null);
        $mapping = [...$mapping, 'nama_karyawan' => 'Nama', 'jenis_kelamin' => 'Gender', 'kunjungan_id' => 'Visit', 'paket_id' => 'Package'];
        $this->patchJson($batch['mapping_url'], ['mapping' => $mapping, 'version' => 0])->assertOk()->assertJsonPath('stats.ready', 1)->assertJsonPath('version', 1);
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->patchJson($batch['mapping_url'], ['mapping' => [...$mapping, 'nip' => 'Nama'], 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('mapping');
        $this->postJson($batch['confirm_url'], ['version' => 1])->assertOk()->assertJsonPath('stats.succeeded', 1);
        $this->patchJson($batch['mapping_url'], ['mapping' => $mapping, 'version' => 1])->assertConflict();
    }

    public function test_invalid_formula_numeric_nik_and_future_birth_date_are_row_errors(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create();
        $package = Paket::factory()->master()->create();
        $row = $this->row('=1+1', $visit, $package);
        $row[1] = 3171010101900001;
        $row[4] = '2099-01-01';
        $batch = $this->stage([$row]);
        $this->assertSame(1, $batch['stats']['failed']);
        $this->assertStringContainsString('rumus', implode(' ', $batch['rows'][0]['errors']));
        $this->assertStringContainsString('NIK harus disimpan sebagai teks', implode(' ', $batch['rows'][0]['errors']));
        $this->assertStringContainsString('sebelum hari ini', implode(' ', $batch['rows'][0]['errors']));
        $this->assertDatabaseCount('pasiens', 0);
    }

    public function test_cached_lookup_ids_are_used_without_evaluating_formulas(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create();
        $package = Paket::factory()->master()->create();
        $book = app(PatientSpreadsheet::class)->template();
        $sheet = $book->getSheetByName('Pasien');
        $sheet->setCellValue('A2', 'Lookup')->setCellValue('D2', 'L');
        $sheet->setCellValue('J2', '=\'Master Kunjungan MCU\'!A2');
        $sheet->setCellValue('K2', '=\'Master Paket\'!A2');
        $file = $this->saveBook($book);
        $batch = $this->postJson(route('pasien.import.store'), ['import_file' => $file])->assertOk()->json();
        $this->assertSame(1, $batch['stats']['ready'], json_encode($batch['rows']));
        $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1);
        $this->assertDatabaseHas('karyawans', ['nama_karyawan' => 'Lookup', 'paket_id' => $package->id]);
    }

    public function test_unreadable_empty_macro_oversized_and_duplicate_header_files_save_nothing_and_offer_report(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([$this->upload([]), $this->upload(array_fill(0, 501, ['Nama'])), $this->upload([['Nama']], ['nama', 'nama']), UploadedFile::fake()->create('bad.csv', 1, 'text/csv'), UploadedFile::fake()->create('large.xlsx', 5121)] as $file) {
            $response = $this->postJson(route('pasien.import.store'), ['import_file' => $file])->assertUnprocessable()->assertJsonPath('stats.failed', 1);
            $this->get($response->json('report_url'))->assertOk();
        }
        $file = $this->upload([['Nama']]);
        $zip = new \ZipArchive;
        $zip->open($file->getRealPath());
        $zip->addFromString('xl/vbaProject.bin', 'macro');
        $zip->close();
        $this->postJson(route('pasien.import.store'), ['import_file' => $file])->assertUnprocessable()->assertJsonPath('stats.failed', 1);
        $this->postJson(route('pasien.import.store'), [])->assertUnprocessable();
        $this->assertDatabaseCount('pasiens', 0);
    }

    public function test_batches_and_reports_are_owner_only_expire_and_are_pruned(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $batch = PatientImportBatch::factory()->create(['user_id' => $owner->id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson(route('pasien.import.show', $batch))->assertNotFound();
        $this->postJson(route('pasien.import.confirm', $batch), ['version' => 0])->assertNotFound();
        $this->get(route('pasien.import.report', $batch))->assertNotFound();
        $this->actingAs($owner);
        $this->get(route('pasien.import.report', $batch))->assertConflict();
        $batch->update(['expires_at' => now()->subSecond()]);
        $this->getJson(route('pasien.import.show', $batch))->assertGone();
        $this->postJson(route('pasien.import.confirm', $batch), ['version' => 0])->assertGone();
        $this->get(route('pasien.import.report', $batch))->assertGone();
        $this->artisan('model:prune', ['--model' => [PatientImportBatch::class]])->assertSuccessful();
        $this->assertDatabaseMissing('patient_import_batches', ['id' => $batch->id]);
    }

    public function test_failed_row_storage_rolls_back_identity_and_package_binding_without_losing_other_rows(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create(['kuota_peserta' => 0]);
        $package = Paket::factory()->master()->create();
        $failedPackage = Paket::factory()->master()->create();
        $batch = $this->stage([$this->row('Sukses', $visit, $package), $this->row('Gagal simpan', $visit, $failedPackage)]);
        Event::listen('eloquent.created: '.Karyawan::class, function (Karyawan $participant): void {
            if ($participant->nama_karyawan === 'Gagal simpan') {
                throw new \RuntimeException('Simulated storage failure');
            }
        });
        try {
            $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->assertJsonPath('stats.succeeded', 1)->assertJsonPath('stats.failed', 1);
        } finally {
            Event::forget('eloquent.created: '.Karyawan::class);
        }
        $this->assertDatabaseCount('karyawans', 1);
        $this->assertDatabaseCount('pasiens', 1);
        $this->assertDatabaseCount('patient_import_receipts', 1);
        $this->assertFalse($visit->pakets()->whereKey($failedPackage->id)->exists());
        $this->assertTrue($visit->pakets()->whereKey($package->id)->exists());
    }

    public function test_sensitive_preview_is_encrypted_and_report_writes_formula_like_text_as_literal_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create();
        $package = Paket::factory()->master()->create();
        $row = $this->row('Nama Rahasia', $visit, $package, 'X');
        $row[6] = '=HYPERLINK("https://example.com","click")';
        $batch = $this->stage([$row]);
        $source = DB::table('patient_import_batches')->where('id', $batch['id'])->value('source_rows');
        $this->assertStringNotContainsString('Nama Rahasia', $source);
        $result = $this->postJson($batch['confirm_url'], ['version' => 0])->assertOk()->json();
        $book = $this->readBook($this->get($result['report_url'])->assertOk()->streamedContent());
        $this->assertSame(DataType::TYPE_STRING, $book->getSheetByName('Gagal')->getCell('H2')->getDataType());
        $this->assertSame($row[6], $book->getSheetByName('Gagal')->getCell('H2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_uncached_lookup_is_a_readable_row_error_and_never_executes_on_the_server(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $visit = RencanaKunjungan::factory()->create();
        $package = Paket::factory()->master()->create();
        $book = app(PatientSpreadsheet::class)->template();
        $sheet = $book->getSheet(0);
        $sheet->setCellValue('A2', 'Uncached')->setCellValue('D2', 'L')->setCellValue('J2', '=\'Master Kunjungan MCU\'!A2')->setCellValue('K2', $package->id);
        $batch = $this->postJson(route('pasien.import.store'), ['import_file' => $this->saveBook($book, false)])->assertOk()->assertJsonPath('stats.failed', 1)->json();
        $this->assertStringContainsString('tempel hasil sebagai nilai', implode(' ', $batch['rows'][0]['errors']));
        $this->assertNull($batch['rows'][0]['data']['kunjungan_id']);
        $this->assertDatabaseCount('pasiens', 0);
    }

    public function test_guests_and_operators_cannot_use_import_endpoints(): void
    {
        $this->get(route('pasien.import.template'))->assertRedirect(route('login'));
        $this->post(route('pasien.import.store'), [])->assertRedirect(route('login'));
        $batch = PatientImportBatch::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->get(route('pasien.index'))->assertOk()->assertDontSee('data-patient-import-open', false);
        $this->get(route('pasien.import.template'))->assertForbidden();
        $this->post(route('pasien.import.store'), [])->assertForbidden();
        $this->getJson(route('pasien.import.show', $batch))->assertForbidden();
        $this->patchJson(route('pasien.import.update', $batch), [])->assertForbidden();
        $this->postJson(route('pasien.import.confirm', $batch), [])->assertForbidden();
        $this->get(route('pasien.import.report', $batch))->assertForbidden();
    }

    /** @return list<string> */
    private function row(string $name, RencanaKunjungan $visit, Paket $package, string $gender = 'L'): array
    {
        return [$name, '', '', $gender, '', '', '', '', '', $visit->id, $package->id];
    }

    /** @param list<array<int, mixed>> $rows
     * @param  list<string>|null  $headers
     * @return array<string, mixed>
     */
    private function stage(array $rows, ?array $headers = null): array
    {
        return $this->postJson(route('pasien.import.store'), ['import_file' => $this->upload($rows, $headers)])->assertOk()->json();
    }

    /** @param list<array<int, mixed>> $rows
     * @param  list<string>|null  $headers
     */
    private function upload(array $rows, ?array $headers = null): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Pasien');
        $sheet->fromArray($headers ?? PatientSpreadsheet::HEADERS, null, 'A1');
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                if (is_string($value) && ! str_starts_with($value, '=')) {
                    $sheet->setCellValueExplicit([$column + 1, $index + 2], $value, DataType::TYPE_STRING);
                } elseif (is_numeric($value)) {
                    $sheet->setCellValueExplicit([$column + 1, $index + 2], $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValue([$column + 1, $index + 2], $value);
                }
            }
        }

        return $this->saveBook($book);
    }

    private function saveBook(Spreadsheet $book, bool $calculate = true): UploadedFile
    {
        $path = Storage::disk('local')->path('import-'.Str::uuid().'.xlsx');
        (new Xlsx($book))->setPreCalculateFormulas($calculate)->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'pasien.xlsx', null, null, true);
    }

    private function readBook(string $contents): Spreadsheet
    {
        $path = 'report-'.Str::uuid().'.xlsx';
        Storage::disk('local')->put($path, $contents);

        return (new XlsxReader)->load(Storage::disk('local')->path($path));
    }
}
