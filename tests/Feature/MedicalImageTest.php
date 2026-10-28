<?php

namespace Tests\Feature;

use App\Jobs\GenerateMcuExport;
use App\McuPdf;
use App\McuReport;
use App\MedicalImage;
use App\Models\Karyawan;
use App\Models\McuExport;
use App\Models\McuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class MedicalImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_upload_is_resized_to_webp_and_the_original_is_retained_privately(): void
    {
        [$participant, $item] = $this->participant();
        $file = UploadedFile::fake()->image('hasil.png', 3200, 1800);
        $originalHash = hash_file('sha256', $file->getRealPath());

        $this->put(route('pemeriksaan.update', [$participant->rencana_kunjungan_id, $participant]), [
            'section' => 'kategori-'.$item->mcu_category_id, 'status' => 'draft', 'lock_version' => 0,
            'dynamic_files' => [$item->id => $file], 'dynamic_answers' => [$item->id => 'Hasil terlampir'],
        ])->assertSessionHasNoErrors();

        $attachment = $participant->fresh()->anamnesa->dynamic_attachments[$item->id];
        $disk = Storage::disk('local');
        $this->assertSame('image/webp', $attachment['mime']);
        $this->assertStringEndsWith('.webp', $attachment['path']);
        $dimensions = getimagesize($disk->path($attachment['path']));
        $this->assertSame([2560, 1440, 'image/webp'], [$dimensions[0], $dimensions[1], $dimensions['mime']]);
        $this->assertSame($originalHash, hash_file('sha256', $disk->path($attachment['original_path'])));
        $this->assertSame('private', $disk->getVisibility($attachment['original_path']));
        $this->assertSame('private', $disk->getVisibility($attachment['path']));
        $this->get(route('pemeriksaan.attachment', [$participant->rencana_kunjungan_id, $participant, 'dynamic-'.$item->id]))->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_supporting_images_are_also_normalized_without_discarding_the_source(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->paket->update(['active_items' => ['rontgen' => true]]);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));

        $this->put(route('pemeriksaan.update', [$participant->rencana_kunjungan_id, $participant]), ['section' => 'penunjang', 'status' => 'draft', 'lock_version' => 0,
            'images' => ['rontgen' => UploadedFile::fake()->image('rontgen.jpg')]])->assertSessionHasNoErrors();

        $row = $participant->fresh()->anamnesa->penunjang['rontgen'];
        $this->assertStringEndsWith('.webp', $row['attachment']);
        Storage::disk('local')->assertExists($row['original_attachment']);
        $this->assertSame('image/webp', (new McuReport)->sections($participant->fresh(), $participant->fresh()->anamnesa)[0]['attachments'][0]['mime']);
        $this->get(route('pemeriksaan.attachment', [$participant->rencana_kunjungan_id, $participant, 'rontgen']))->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_legacy_67_megapixel_attachment_exports_under_128mb_without_changing_source_or_final_snapshot(): void
    {
        [$participant, $item] = $this->participant();
        $path = 'mcu/'.$participant->id.'/dynamic/legacy.png';
        $bytes = $this->png(7087, 9449);
        Storage::disk('local')->put($path, $bytes);
        $participant->update(['is_closing' => true, 'status_pemeriksaan' => 'selesai']);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'lock_version' => 1,
            'package_items' => [], 'dynamic_schema' => $participant->paket->dynamic_schema,
            'dynamic_attachments' => [$item->id => ['path' => $path, 'mime' => 'image/png']]]);
        $before = $participant->anamnesa->getAttributes();
        $owner = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $export = McuExport::factory()->create(['user_id' => $owner->id, 'perusahaan_id' => $participant->perusahaan_id, 'total' => 1, 'manifest' => [['id' => $participant->id, 'version' => 1]]]);
        $memoryLimit = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '128M');
            (new GenerateMcuExport($export->id))->handle(new McuPdf(new McuReport), new McuReport);
        } finally {
            ini_set('memory_limit', $memoryLimit);
        }

        $this->assertSame('ready', $export->fresh()->status);
        $this->assertSame(1, $export->fresh()->processed);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->fresh()->path)));
        $this->assertStringStartsWith('%PDF-', $zip->getFromIndex(0));
        $zip->close();
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', Storage::disk('local')->path($path)));
        $this->assertSame($before, $participant->anamnesa->fresh()->getAttributes());
        $this->assertSame([], Storage::disk('local')->allFiles('mcu-exports/'.$export->id.'/temp'));
    }

    public function test_large_upload_is_normalized_without_decoding_it_in_the_request_process(): void
    {
        [$participant, $item] = $this->participant();
        $this->put(route('pemeriksaan.update', [$participant->rencana_kunjungan_id, $participant]), ['section' => 'kategori-'.$item->mcu_category_id,
            'status' => 'draft', 'lock_version' => 0, 'dynamic_files' => [$item->id => UploadedFile::fake()->createWithContent('scan.png', $this->png(4000, 4000))]])->assertSessionHasNoErrors();

        $attachment = $participant->fresh()->anamnesa->dynamic_attachments[$item->id];
        $dimensions = getimagesize(Storage::disk('local')->path($attachment['path']));
        $this->assertSame([2560, 2560], [$dimensions[0], $dimensions[1]]);
        $this->assertSame('image/webp', $dimensions['mime']);
        Storage::disk('local')->assertExists($attachment['original_path']);
    }

    public function test_excessive_pixel_count_is_rejected_before_storing_any_attachment(): void
    {
        [$participant, $item] = $this->participant();
        $before = Storage::disk('local')->allFiles('mcu/'.$participant->id);
        $this->put(route('pemeriksaan.update', [$participant->rencana_kunjungan_id, $participant]), ['section' => 'kategori-'.$item->mcu_category_id,
            'status' => 'draft', 'lock_version' => 0, 'dynamic_files' => [$item->id => UploadedFile::fake()->createWithContent('huge.png', $this->png(9000, 9000))]])->assertSessionHasErrors('dynamic_files.'.$item->id);

        $this->assertDatabaseCount('anamnesas', 0);
        $this->assertSame($before, Storage::disk('local')->allFiles('mcu/'.$participant->id));
    }

    public function test_a_late_validation_failure_removes_both_normalized_images_and_private_originals(): void
    {
        [$participant, $item] = $this->participant();
        $before = Storage::disk('local')->allFiles('mcu/'.$participant->id);

        $this->put(route('pemeriksaan.update', [$participant->rencana_kunjungan_id, $participant]), ['section' => 'kategori-'.$item->mcu_category_id,
            'status' => 'final', 'lock_version' => 0, 'dynamic_files' => [$item->id => UploadedFile::fake()->image('scan.png')]])->assertSessionHasErrors('confirmed');

        $this->assertDatabaseCount('anamnesas', 0);
        $this->assertSame($before, Storage::disk('local')->allFiles('mcu/'.$participant->id));
    }

    public function test_large_but_corrupt_images_fail_without_leaking_subprocess_paths_or_leaving_originals(): void
    {
        [$participant, $item] = $this->participant();
        $before = Storage::disk('local')->allFiles('mcu/'.$participant->id);
        $bytes = substr($this->png(4000, 4000), 0, 33);

        $response = $this->put(route('pemeriksaan.update', [$participant->rencana_kunjungan_id, $participant]), ['section' => 'kategori-'.$item->mcu_category_id,
            'status' => 'draft', 'lock_version' => 0, 'dynamic_files' => [$item->id => UploadedFile::fake()->createWithContent('broken.png', $bytes)]])->assertSessionHasErrors('dynamic_files.'.$item->id);

        $this->assertStringNotContainsString(base_path(), json_encode(session('errors')->getBag('default')->messages()));
        $this->assertDatabaseCount('anamnesas', 0);
        $this->assertSame($before, Storage::disk('local')->allFiles('mcu/'.$participant->id));
    }

    public function test_supporting_uploads_are_also_cleaned_when_finalization_is_rejected(): void
    {
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->paket->update(['active_items' => ['rontgen' => true]]);
        $before = Storage::disk('local')->allFiles('mcu/'.$participant->id);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));

        $this->put(route('pemeriksaan.update', [$participant->rencana_kunjungan_id, $participant]), ['section' => 'penunjang', 'status' => 'final', 'lock_version' => 0,
            'images' => ['rontgen' => UploadedFile::fake()->image('rontgen.jpg')]])->assertSessionHasErrors('confirmed');

        $this->assertDatabaseCount('anamnesas', 0);
        $this->assertSame($before, Storage::disk('local')->allFiles('mcu/'.$participant->id));
    }

    #[TestWith(['MM', 6, 60, 120])]
    #[TestWith(['II', 8, 60, 120])]
    #[TestWith(['MM', 9, 120, 60])]
    #[TestWith(['XX', 6, 120, 60])]
    #[TestWith(['MM', 6, 120, 60, 65535])]
    public function test_normalization_preserves_exif_orientation_without_upscaling(string $order, int $orientation, int $expectedWidth, int $expectedHeight, int $offset = 8): void
    {
        $disk = Storage::disk('local');
        $file = UploadedFile::fake()->image('rotated.jpg', 120, 60);
        $bytes = file_get_contents($file->getRealPath());
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        $exif = "Exif\0\0".$order.pack($short.$long.$short, 42, $offset, 1).pack($short.$short.$long.$short, 0x0112, 3, 1, $orientation)."\0\0".pack($long, 0);
        $disk->put('rotated.jpg', substr($bytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2));

        (new MedicalImage)->normalize($disk->path('rotated.jpg'), $disk->path('rotated.webp'), 'webp', 2560, 2560, 90);

        $dimensions = getimagesize($disk->path('rotated.webp'));
        $this->assertSame([$expectedWidth, $expectedHeight], [$dimensions[0], $dimensions[1]]);
    }

    public function test_invalid_legacy_image_fails_cleanly_without_orphaned_pdf_temporary_files(): void
    {
        [$participant] = $this->participant();
        $participant->update(['is_closing' => true, 'status_pemeriksaan' => 'selesai']);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        Storage::disk('local')->put($participant->foto, 'invalid image bytes');

        try {
            (new McuPdf(new McuReport))->render($participant);
            $this->fail('A corrupt medical image must not be silently omitted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('gambar JPG, PNG, atau WebP yang valid', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('mcu-pdf'));
    }

    /** @return array{Karyawan, McuItem} */
    private function participant(): array
    {
        $item = McuItem::factory()->create(['input_type' => 'text', 'allow_attachment' => true]);
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->paket->update(['active_items' => [], 'dynamic_schema' => [['id' => $item->mcu_category_id, 'name' => 'Pemeriksaan', 'items' => [$item->definition()]]]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        return [$participant, $item];
    }

    private function png(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $deflater = deflate_init(ZLIB_ENCODING_DEFLATE);
        $compressed = '';
        $row = "\0".str_repeat("\x40\x80\xc0", $width);
        for ($y = 0; $y < $height; $y++) {
            $compressed .= deflate_add($deflater, $row, $y === $height - 1 ? ZLIB_FINISH : ZLIB_NO_FLUSH);
        }

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', $compressed).$chunk('IEND', '');
    }
}
