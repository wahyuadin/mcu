<?php

namespace Tests\Feature;

use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DynamicItemAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_allows_optional_attachments_only_for_text_and_number_and_snapshots_option(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = McuCategory::factory()->create();
        foreach (['text', 'number', 'radio'] as $type) {
            $this->post(route('mcu-item.store'), ['mcu_category_id' => $category->id, 'questions' => [[
                'label' => 'Item '.$type, 'input_type' => $type, 'is_required' => 1, 'is_active' => 1, 'allow_attachment' => 1, 'yes_report' => 'Ya', 'no_report' => 'Tidak',
            ]]])->assertSessionHasNoErrors();
            $item = McuItem::where('label', 'Item '.$type)->firstOrFail();
            $this->assertSame($type !== 'radio', $item->allow_attachment);
            $this->assertSame($type !== 'radio', $item->definition()['allow_attachment']);
            $this->get(route('mcu-item.edit', $item))->assertOk()->assertSee('Izinkan lampiran (opsional)');
        }
        $item = $category->items()->where('input_type', 'text')->firstOrFail();
        $this->post(route('paket.store'), ['nama_paket' => 'Paket lampiran', 'master_items' => [$item->id]])->assertSessionHasNoErrors();
        $this->assertTrue(Paket::where('nama_paket', 'Paket lampiran')->firstOrFail()->dynamic_schema[0]['items'][0]['allow_attachment']);
    }

    public function test_upload_is_optional_and_existing_file_is_preserved_when_only_answer_is_updated(): void
    {
        [$participant, $item] = $this->participant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $section = 'kategori-'.$item->mcu_category_id;
        $this->put($url, ['section' => $section, 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => 'Normal']])->assertSessionHasNoErrors();
        $this->assertSame([], $participant->fresh()->anamnesa->dynamic_attachments);
        $this->put($url, ['section' => $section, 'status' => 'selesai', 'lock_version' => 1, 'dynamic_answers' => [$item->id => 'Normal'], 'dynamic_files' => [$item->id => UploadedFile::fake()->image('thorax.jpg')]])->assertSessionHasNoErrors();
        $attachment = $participant->fresh()->anamnesa->dynamic_attachments[$item->id];
        Storage::disk('local')->assertExists($attachment['path']);
        $this->put($url, ['section' => $section, 'status' => 'selesai', 'lock_version' => 2, 'dynamic_answers' => [$item->id => 'Koreksi']])->assertSessionHasNoErrors();
        $this->assertSame($attachment, $participant->fresh()->anamnesa->dynamic_attachments[$item->id]);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => $section]))->assertOk()->assertSee('name="dynamic_files['.$item->id.']"', false)->assertSee('Lihat gambar tersimpan');
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Lampiran hasil Thorax');
    }

    public function test_pdf_upload_for_mapped_numeric_item_is_private_and_downloaded_as_attachment(): void
    {
        [$participant, $item] = $this->participant('number', true, true);
        $file = UploadedFile::fake()->createWithContent('hasil.pdf', "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $this->put(route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]), [
            'section' => 'kategori-'.$item->mcu_category_id, 'status' => 'selesai', 'lock_version' => 0,
            'dynamic_answers' => [$item->id => '14'], 'dynamic_files' => [$item->id => $file],
        ])->assertSessionHasNoErrors();
        $attachment = $participant->fresh()->anamnesa->dynamic_attachments[$item->id];
        $this->assertSame('application/pdf', $attachment['mime']);
        $url = route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'dynamic-'.$item->id]);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->get(route('pemeriksaan.report', [$participant->rencanaKunjungan, $participant]))->assertOk()->assertSee('Unduh lampiran PDF');
        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_uploads_outside_snapshot_category_or_disabled_item_are_rejected(): void
    {
        [$participant, $item] = $this->participant('text', false);
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'kategori-'.$item->mcu_category_id, 'status' => 'draft', 'lock_version' => 0, 'dynamic_files' => [$item->id => UploadedFile::fake()->image('image.jpg')]])->assertSessionHasErrors('dynamic_files');
        $item->update(['allow_attachment' => true]);
        $this->put($url, ['section' => 'kategori-'.$item->mcu_category_id, 'status' => 'draft', 'lock_version' => 0, 'dynamic_files' => [$item->id => UploadedFile::fake()->image('image.jpg')]])->assertSessionHasErrors('dynamic_files');
        $this->put($url, ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0, 'dynamic_files' => [$item->id => UploadedFile::fake()->image('image.jpg')]])->assertSessionHasErrors('dynamic_files');
        $this->assertDatabaseCount('anamnesas', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('mcu/'.$participant->id.'/dynamic'));
    }

    public function test_invalid_oversized_and_foreign_files_are_rejected_without_writing_files(): void
    {
        [$participant, $item] = $this->participant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        foreach ([UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->image('large.jpg')->size(10241), UploadedFile::fake()->createWithContent('image.jpg', '<?php echo "not an image";')] as $file) {
            $this->put($url, ['section' => 'kategori-'.$item->mcu_category_id, 'status' => 'draft', 'lock_version' => 0, 'dynamic_files' => [$item->id => $file]])->assertSessionHasErrors('dynamic_files.'.$item->id);
        }
        $foreign = McuItem::factory()->create(['allow_attachment' => true]);
        $this->put($url, ['section' => 'kategori-'.$item->mcu_category_id, 'status' => 'draft', 'lock_version' => 0, 'dynamic_files' => [$foreign->id => UploadedFile::fake()->image('image.jpg')]])->assertSessionHasErrors('dynamic_files');
        $this->assertDatabaseCount('anamnesas', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('mcu/'.$participant->id.'/dynamic'));
    }

    public function test_failed_completeness_and_stale_lock_do_not_leave_orphaned_files(): void
    {
        [$participant, $item] = $this->participant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'kategori-'.$item->mcu_category_id, 'status' => 'selesai', 'lock_version' => 0, 'dynamic_files' => [$item->id => UploadedFile::fake()->image('image.jpg')]])->assertSessionHasErrors('kelengkapan');
        $this->put($url, ['section' => 'kategori-'.$item->mcu_category_id, 'status' => 'draft', 'lock_version' => 1, 'dynamic_files' => [$item->id => UploadedFile::fake()->image('image.jpg')]])->assertSessionHasErrors('pemeriksaan');
        $this->assertDatabaseCount('anamnesas', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('mcu/'.$participant->id.'/dynamic'));
    }

    public function test_final_results_are_locked_and_revision_keeps_original_file_after_replacement_and_master_deletion(): void
    {
        [$participant, $item] = $this->participant();
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $section = 'kategori-'.$item->mcu_category_id;
        $this->put($url, ['section' => $section, 'status' => 'selesai', 'lock_version' => 0, 'dynamic_answers' => [$item->id => 'Normal'], 'dynamic_files' => [$item->id => UploadedFile::fake()->image('first.jpg')]])->assertSessionHasNoErrors();
        $old = $participant->fresh()->anamnesa->dynamic_attachments[$item->id];
        $doctor = Dokter::factory()->create(['is_active' => true]);
        $this->put($url, ['section' => 'kesimpulan', 'status' => 'selesai', 'lock_version' => 1, 'dokter_id' => $doctor->id, 'catatan_dokter' => 'Normal', 'anjuran_saran' => 'Kontrol berkala', 'status_kelaikan' => 'Fit to Work'])->assertSessionHasNoErrors();
        $this->post(route('pemeriksaan.finalize', [$participant->rencanaKunjungan, $participant]), ['confirmed' => 1, 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->put($url, ['section' => $section, 'status' => 'draft', 'lock_version' => 3, 'dynamic_files' => [$item->id => UploadedFile::fake()->image('forbidden.png')]])->assertForbidden();
        $this->post(route('pemeriksaan.revise', [$participant->rencanaKunjungan, $participant]), ['reason' => 'Koreksi lampiran hasil'])->assertSessionHasNoErrors();
        $this->put($url, ['section' => $section, 'status' => 'selesai', 'lock_version' => 4, 'dynamic_answers' => [$item->id => 'Koreksi'], 'dynamic_files' => [$item->id => UploadedFile::fake()->image('new.png')]])->assertSessionHasNoErrors();
        $new = $participant->fresh()->anamnesa->dynamic_attachments[$item->id];
        $this->assertNotSame($old['path'], $new['path']);
        Storage::disk('local')->assertExists($old['path']);
        Storage::disk('local')->assertExists($new['path']);
        $item->delete();
        $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'dynamic-'.$item->id]))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(route('pemeriksaan.attachment', [$participant->rencanaKunjungan, $participant, 'dynamic-'.$item->id, 'version' => 1]))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(route('pemeriksaan.laporan-riwayat.version', [$participant->rencanaKunjungan, $participant, 1]))->assertOk()->assertSee('Lampiran hasil Thorax')->assertSee('version=1', false)->assertDontSee(hash('sha256', $new['path']));
        $other = Karyawan::factory()->create();
        $this->get(route('pemeriksaan.attachment', [$other->rencanaKunjungan, $participant, 'dynamic-'.$item->id]))->assertNotFound();
    }

    /** @return array{Karyawan, McuItem} */
    private function participant(string $type = 'text', bool $allow = true, bool $mapped = false): array
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $item = McuItem::factory()->create(['input_type' => $type, 'label' => 'Thorax', 'allow_attachment' => $allow, 'lab_config' => $mapped ? ['result_type' => 'number', 'rule' => 'range', 'min' => 12, 'max' => 16, 'normal_values' => [], 'unit' => '', 'reference' => '12–16', 'normal_report' => '', 'abnormal_report' => ''] : null]);
        $package = Paket::factory()->master()->create(['active_items' => [], 'dynamic_schema' => [['id' => $item->category->id, 'name' => $item->category->name, 'order' => 1, 'items' => [$item->definition()]]]]);
        $participant = Karyawan::factory()->withPhoto()->create();
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);

        return [$participant->fresh(), $item];
    }
}
