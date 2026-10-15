<?php

namespace Tests\Feature;

use App\Jobs\GenerateMcuExport;
use App\Models\Karyawan;
use App\Models\McuExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class HrdDownloadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_report_polling_do_not_use_csv_or_zip_limits(): void
    {
        Queue::fake();
        $participant = $this->releasedParticipant();
        $hrd = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $this->actingAs($hrd);
        for ($poll = 0; $poll < 15; $poll++) {
            $this->getJson(route('dashboard.data'))->assertOk();
            $this->getJson(route('hrd.data'))->assertOk();
        }

        $this->get(route('hrd.recap'))->assertOk()->assertDownload('Rekap_MCU.csv')->assertHeader('X-RateLimit-Remaining', '9');
        $this->postJson(route('hrd.exports.store'), ['all' => true])->assertAccepted()->assertJsonPath('total', 1)->assertHeader('X-RateLimit-Remaining', '4');

        $this->assertDatabaseCount('mcu_exports', 1);
        Queue::assertPushed(GenerateMcuExport::class, 1);
    }

    public function test_csv_still_limits_ten_downloads_per_minute_per_account_and_recovers_after_waiting(): void
    {
        $participant = $this->releasedParticipant();
        $first = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $second = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $this->actingAs($first);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->get(route('hrd.recap'))->assertOk();
        }

        $this->getJson(route('hrd.recap'))->assertTooManyRequests()->assertHeader('Retry-After');

        $this->actingAs($second)->get(route('hrd.recap'))->assertOk()->assertHeader('X-RateLimit-Remaining', '9');
        $this->travel(61)->seconds();
        $this->actingAs($first)->get(route('hrd.recap'))->assertOk();
    }

    public function test_zip_still_limits_five_attempts_without_blocking_csv_or_another_account(): void
    {
        Queue::fake();
        $participant = $this->releasedParticipant();
        $first = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $second = User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]);
        $this->actingAs($first);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('hrd.exports.store'), ['all' => false, 'ids' => [(string) Str::uuid()]])->assertUnprocessable();
        }

        $this->postJson(route('hrd.exports.store'), ['all' => true])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertDatabaseCount('mcu_exports', 0);
        $this->get(route('hrd.recap'))->assertOk();
        $this->actingAs($second)->postJson(route('hrd.exports.store'), ['all' => true])->assertAccepted();
        $this->assertSame($second->id, McuExport::sole()->user_id);
        Queue::assertPushed(GenerateMcuExport::class, 1);
    }

    public function test_excel_csv_has_a_bom_separator_crlf_and_round_trips_quoted_unicode_multiline_cells(): void
    {
        $label = "Produksi; \"Utama\"\nJakarta — 日本";
        $participant = Karyawan::factory()->create(['bagian' => $label]);
        $participant->perusahaan->update(['dashboard_layout' => [['source' => 'department', 'title' => 'Departemen', 'chart' => 'table', 'metric' => 'count', 'width' => 1, 'height' => 1]]]);
        $this->actingAs(User::factory()->create(['role' => 'hrd', 'perusahaan_id' => $participant->perusahaan_id]));

        $response = $this->get(route('hrd.recap'))->assertOk()->assertDownload('Rekap_MCU.csv')->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFsep=;\r\n", $csv);
        $this->assertStringEndsWith("\r\n", $csv);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        fgets($stream);
        $this->assertSame(['Kategori', 'Pemeriksaan', 'Kelompok', 'Jumlah', 'Persentase', 'Statistik', 'Nilai statistik', 'Satuan', 'Rujukan'], fgetcsv($stream, null, ';', '"', ''));
        $row = fgetcsv($stream, null, ';', '"', '');
        fclose($stream);
        $this->assertCount(9, $row);
        $this->assertSame($label, $row[2]);
        $this->assertSame('1', $row[3]);
    }

    private function releasedParticipant(): Karyawan
    {
        $participant = Karyawan::factory()->create(['status_pemeriksaan' => 'selesai', 'is_closing' => true]);
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final', 'package_items' => [], 'dynamic_schema' => []]);

        return $participant;
    }
}
