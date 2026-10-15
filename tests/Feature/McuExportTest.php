<?php

namespace Tests\Feature;

use App\Jobs\GenerateMcuExport;
use App\McuPdf;
use App\McuReport;
use App\Models\Karyawan;
use App\Models\McuExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class McuExportTest extends TestCase
{
    use RefreshDatabase;

    private function releasedParticipant(): Karyawan
    {
        $participant = Karyawan::factory()->withPhoto()->create(['status_pemeriksaan' => 'selesai', 'is_closing' => true]);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => [], 'lock_version' => 1, 'status_kelaikan' => 'Fit to Work', 'catatan_dokter' => 'Sehat', 'anjuran_saran' => 'Olahraga teratur']);

        return $participant;
    }

    public function test_export_queues_released_company_participants_only(): void
    {
        Queue::fake();
        $participant = $this->releasedParticipant();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        Karyawan::factory()->create(['paket_id' => $participant->paket_id]);
        $response = $this->actingAs($hrd)->postJson(route('hrd.exports.store'), ['all' => true]);
        $response->assertAccepted()->assertJsonPath('total', 1)->assertJsonMissingPath('manifest')->assertJsonMissingPath('path');
        $export = McuExport::findOrFail($response->json('id'));
        $this->assertSame([['id' => $participant->id, 'version' => 1]], $export->manifest);
        Queue::assertPushed(GenerateMcuExport::class, fn ($job): bool => $job->exportId === $export->id && $job->connection === 'mcu-reports');
        $this->postJson(route('hrd.exports.store'), ['all' => true])->assertUnprocessable();
    }

    public function test_export_rejects_unreleased_and_foreign_selected_ids(): void
    {
        Queue::fake();
        $participant = $this->releasedParticipant();
        $unreleased = Karyawan::factory()->create(['paket_id' => $participant->paket_id]);
        $foreign = $this->releasedParticipant();
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]));
        $this->postJson(route('hrd.exports.store'), ['all' => false, 'ids' => [$unreleased->id]])->assertUnprocessable();
        $this->postJson(route('hrd.exports.store'), ['all' => false, 'ids' => [$foreign->id]])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('mcu_exports', 0);
    }

    public function test_pdf_and_zip_are_real_and_downloads_are_private(): void
    {
        $participant = $this->releasedParticipant();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $export = McuExport::factory()->create(['user_id' => $hrd->id, 'perusahaan_id' => $participant->perusahaan_id, 'total' => 1, 'manifest' => [['id' => $participant->id, 'version' => 1]]]);
        (new GenerateMcuExport($export->id))->handle(new McuPdf(new McuReport), new McuReport);
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $this->assertSame(1, $export->processed);
        Storage::disk('local')->assertExists($export->path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->path)));
        $this->assertSame(1, $zip->numFiles);
        $this->assertStringStartsWith('%PDF-', $zip->getFromIndex(0));
        $zip->close();
        $this->actingAs($hrd)->get(route('hrd.exports.download', $export))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]))->getJson(route('hrd.exports.status', $export))->assertNotFound();
    }

    public function test_changed_version_fails_without_partial_archive(): void
    {
        $participant = $this->releasedParticipant();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $export = McuExport::factory()->create(['user_id' => $hrd->id, 'perusahaan_id' => $participant->perusahaan_id, 'total' => 1, 'manifest' => [['id' => $participant->id, 'version' => 0]]]);
        try {
            (new GenerateMcuExport($export->id))->handle(new McuPdf(new McuReport), new McuReport);
            $this->fail('Changed version must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Tidak ada laporan parsial', $exception->getMessage());
        }
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('mcu-exports/'.$export->id));
    }

    public function test_expiry_and_reassignment_revoke_download(): void
    {
        $participant = $this->releasedParticipant();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $export = McuExport::factory()->create(['user_id' => $hrd->id, 'perusahaan_id' => $participant->perusahaan_id, 'expires_at' => now()->subMinute()]);
        $this->actingAs($hrd)->get(route('hrd.exports.download', $export))->assertGone();
        $hrd->update(['perusahaan_id' => null]);
        $this->getJson(route('hrd.exports.status', $export))->assertForbidden();
    }

    public function test_expired_files_are_pruned(): void
    {
        $export = McuExport::factory()->create(['expires_at' => now()->subDay()]);
        $path = 'mcu-exports/'.$export->id.'/reports.zip';
        Storage::disk('local')->put($path, 'zip');
        $this->artisan('model:prune', ['--model' => [McuExport::class]])->assertSuccessful();
        $this->assertModelMissing($export);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_large_export_resumes_in_chunks_and_publishes_only_the_complete_zip(): void
    {
        $participant = $this->releasedParticipant();
        $manifest = [['id' => $participant->id, 'version' => 1]];
        for ($index = 0; $index < 10; $index++) {
            $other = Karyawan::factory()->create(['paket_id' => $participant->paket_id, 'status_pemeriksaan' => 'selesai', 'is_closing' => true]);
            $other->anamnesa()->create(['rencana_kunjungan_id' => $other->rencana_kunjungan_id, 'status' => 'final', 'lock_version' => 1, 'package_items' => []]);
            $manifest[] = ['id' => $other->id, 'version' => 1];
        }
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $export = McuExport::factory()->create(['user_id' => $hrd->id, 'perusahaan_id' => $participant->perusahaan_id, 'total' => 11, 'manifest' => $manifest]);
        Queue::fake([GenerateMcuExport::class]);
        $pdf = new McuPdf(new McuReport);

        (new GenerateMcuExport($export->id))->handle($pdf, new McuReport);

        $this->assertSame(10, $export->fresh()->processed);
        $this->assertSame('processing', $export->fresh()->status);
        $this->assertNull($export->fresh()->path);
        $this->actingAs($hrd)->getJson(route('hrd.exports.download', $export))->assertConflict();
        Queue::assertPushed(GenerateMcuExport::class, 1);

        (new GenerateMcuExport($export->id))->handle($pdf, new McuReport);

        $export->refresh();
        $this->assertSame('ready', $export->status);
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($export->path));
        $this->assertSame(11, $zip->numFiles);
        $zip->close();
        $this->assertSame([], Storage::disk('local')->allFiles('mcu-exports/'.$export->id.'/parts'));
        (new GenerateMcuExport($export->id))->handle($pdf, new McuReport);
        $this->assertSame(11, $export->fresh()->processed);
    }

    public function test_original_pdf_attachment_is_included_separately_with_safe_unique_names(): void
    {
        $participant = $this->releasedParticipant();
        $participant->update(['nama_karyawan' => '../../Nama dengan / karakter ? *']);
        $attachment = (new McuPdf(new McuReport))->render($participant);
        $itemId = (string) Str::uuid();
        $path = 'mcu/'.$participant->id.'/dynamic/pendukung.pdf';
        Storage::disk('local')->put($path, $attachment);
        $participant->anamnesa->update(['dynamic_schema' => [['id' => (string) Str::uuid(), 'name' => 'Penunjang', 'order' => 0, 'has_responsible_doctor' => false, 'items' => [[
            'id' => $itemId, 'type' => 'text', 'label' => 'Hasil pendukung', 'required' => false, 'allow_attachment' => true,
        ]]]], 'dynamic_answers' => [$itemId => 'Tersedia'], 'dynamic_attachments' => [$itemId => ['path' => $path, 'mime' => 'application/pdf']]]);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $export = McuExport::factory()->create(['user_id' => $hrd->id, 'perusahaan_id' => $participant->perusahaan_id, 'total' => 1, 'manifest' => [['id' => $participant->id, 'version' => 1]]]);

        (new GenerateMcuExport($export->id))->handle(new McuPdf(new McuReport), new McuReport);

        $this->assertSame('ready', $export->fresh()->status);
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($export->fresh()->path));
        $this->assertSame(2, $zip->numFiles);
        $this->assertStringStartsWith('lampiran/', $zip->getNameIndex(1));
        $this->assertStringNotContainsString('..', $zip->getNameIndex(0));
        $this->assertSame($attachment, $zip->getFromIndex(1));
        $zip->close();
    }

    public function test_missing_file_and_revoked_participant_never_allow_a_partial_zip_download(): void
    {
        $participant = $this->releasedParticipant();
        $participant->update(['foto' => 'mcu/'.$participant->id.'/missing-photo.webp']);
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $export = McuExport::factory()->create(['user_id' => $hrd->id, 'perusahaan_id' => $participant->perusahaan_id, 'total' => 1, 'manifest' => [['id' => $participant->id, 'version' => 1]]]);
        try {
            (new GenerateMcuExport($export->id))->handle(new McuPdf(new McuReport), new McuReport);
            $this->fail('Missing photo must fail the complete export.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Tidak ada laporan parsial', $exception->getMessage());
        }
        $this->actingAs($hrd)->getJson(route('hrd.exports.download', $export))->assertConflict();
        $this->getJson(route('hrd.exports.status', $export))->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('download_url', null);
        $this->assertSame([], Storage::disk('local')->allFiles('mcu-exports/'.$export->id));
    }
}
