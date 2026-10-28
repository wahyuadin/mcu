<?php

namespace Tests\Feature;

use App\McuPdf;
use App\McuReport;
use App\Models\Karyawan;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HrdPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_short_long_and_image_reports_render_from_the_same_snapshot_without_changing_browser_print(): void
    {
        $this->freezeTime();
        $number = McuItem::factory()->create(['label' => 'Tinggi badan (cm)', 'input_type' => 'number']);
        $radio = McuItem::factory()->create(['label' => 'Riwayat hipertensi', 'input_type' => 'radio', 'yes_report' => 'Ada riwayat', 'no_report' => 'Tidak ada riwayat']);
        $text = McuItem::factory()->create(['label' => 'Catatan pemeriksaan', 'input_type' => 'text', 'allow_attachment' => true]);
        $lab = McuItem::factory()->create(['label' => 'Hemoglobin', 'input_type' => 'number', 'lab_config' => ['result_type' => 'number', 'rule' => 'range', 'min' => 12, 'max' => 16, 'unit' => 'g/dL', 'reference' => '12–16', 'normal_report' => 'Normal', 'abnormal_report' => 'Tidak normal']]);
        $schema = [['id' => $text->category->id, 'name' => 'Hasil Pemeriksaan', 'order' => 0, 'has_responsible_doctor' => false, 'items' => [$number->definition(), $radio->definition(), $text->definition()]],
            ['id' => $lab->category->id, 'name' => 'Laboratorium', 'order' => 1, 'has_responsible_doctor' => false, 'items' => [$lab->definition()]]];
        $package = Paket::factory()->create(['nama_paket' => 'Paket MCU Terpadu', 'active_items' => ['bmi' => true], 'dynamic_schema' => $schema]);
        $package->perusahaan->update(['nama_perusahaan' => 'Perusahaan Demo — Pengujian']);
        $package->rencanaKunjungan->update(['jumlah_peserta_direncanakan' => 529]);
        $participant = Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id, 'nama_karyawan' => 'Peserta Demo', 'bagian' => 'Produksi', 'status_pemeriksaan' => 'selesai', 'is_closing' => true]);
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => ['bmi' => true], 'dynamic_schema' => $schema,
            'dynamic_answers' => [$number->id => 170, $radio->id => 'TIDAK', $text->id => 'Catatan singkat', $lab->id => 14.5], 'status_kelaikan' => 'Fit to Work', 'catatan_dokter' => 'Hasil pemeriksaan dalam batas normal.', 'anjuran_saran' => 'Olahraga teratur dan pemeriksaan berkala.', 'bmi' => 22.5, 'kategori_bmi' => 'Normal']);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Admin Demo']));
        $dashboard = $this->get(route('dashboard', ['company' => $participant->perusahaan_id]))->assertOk()->assertSee('Edit langsung di kanvas', false)->assertSee('data-preview-url', false);
        $workspace = $this->getJson(route('dashboard.data', ['company' => $participant->perusahaan_id]))->assertOk();
        $data = $this->getJson(route('hrd.data', ['company' => $participant->perusahaan_id]))->assertOk();
        $browser = $this->get(route('pemeriksaan.report', [$participant->rencana_kunjungan_id, $participant]))->assertOk()->assertSee('data-report-print', false)->assertSee('Catatan singkat');
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id, 'name' => 'HRD Demo']));
        $readonly = $this->get(route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => 'kesimpulan', 'from_dashboard' => 1]))->assertOk()->assertSee('Hanya baca')->assertDontSee('data-exam-form', false);
        $csv = $this->get(route('hrd.recap'))->assertOk()->assertDownload('Rekap_MCU.csv');
        $renderer = new McuPdf(new McuReport);
        $short = $renderer->render($participant);
        $output = getenv('MCU_PRESENTATION_OUTPUT');
        if (is_string($output) && $output !== '') {
            File::ensureDirectoryExists($output);
            File::put($output.'/short.pdf', $short);
        }
        $this->assertStringStartsWith('%PDF-', $short);
        $this->assertStringContainsString('/MediaBox [0.000 0.000 595.280 841.890]', $short);
        preg_match_all('/\/Type\s*\/Page\b/', $short, $pages);
        $this->assertCount(5, $pages[0]);
        $longValue = str_repeat('Hasil panjang dengan rincian lengkap. ', 90).'PENANDA_AKHIR_CATATAN';
        $imagePath = UploadedFile::fake()->image('lampiran.png', 700, 1000)->store('mcu/'.$participant->id.'/dynamic', 'local');
        $exam->update(['dynamic_answers' => array_replace($exam->dynamic_answers, [$text->id => $longValue]), 'dynamic_attachments' => [$text->id => ['path' => $imagePath, 'mime' => 'image/png']], 'catatan_dokter' => str_repeat('Kesimpulan panjang untuk pengujian. ', 100).'PENANDA_AKHIR_KESIMPULAN']);
        $participant->unsetRelation('anamnesa');
        $long = $renderer->render($participant);
        $this->assertStringStartsWith('%PDF-', $long);
        $this->assertGreaterThan(strlen($short), strlen($long));
        $this->assertSame([], Storage::disk('local')->allFiles('mcu-pdf'));

        $output = getenv('MCU_PRESENTATION_OUTPUT');
        if (is_string($output) && $output !== '') {
            File::ensureDirectoryExists($output);
            File::put($output.'/dashboard.html', $dashboard->getContent());
            File::put($output.'/data.json', $data->getContent());
            File::put($output.'/workspace.json', $workspace->getContent());
            File::put($output.'/browser-report.html', $browser->getContent());
            File::put($output.'/readonly-examination.html', $readonly->getContent());
            File::put($output.'/participant-photo.webp', Storage::disk('local')->get($participant->foto));
            File::put($output.'/Rekap_MCU.csv', $csv->streamedContent());
            File::put($output.'/short.pdf', $short);
            File::put($output.'/long.pdf', $long);
        }
    }
}
