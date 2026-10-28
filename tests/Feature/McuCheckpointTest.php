<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\User;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McuCheckpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_displays_photo_qr_and_direct_print_button_without_checkpoint_information_panel(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi', 'rontgen']);
        $this->get(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']))
            ->assertOk()->assertSee('Cetak PDF')
            ->assertSee('data-checkpoint-print', false)
            ->assertSee('data-print-url="'.route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]).'"', false)
            ->assertSee('Foto peserta '.$participant->nama_karyawan)->assertSee('QR menuju pemeriksaan pasien')
            ->assertDontSee('QR identitas & kartu checkpoint')
            ->assertDontSee('Paraf:')->assertDontSee('Pratinjau / Cetak PDF');
    }

    public function test_direct_print_card_contains_local_qr_and_only_package_checkpoints(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi', 'tinggi_badan', 'rontgen']);
        $identityUrl = route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']);
        $response = $this->get(route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]))
            ->assertOk()
            ->assertViewHas('checkpoint', fn (array $checkpoint): bool => $checkpoint['identityUrl'] === $identityUrl
                && $checkpoint['items'] === ['anamnesa' => 'Anamnesa', 'fisik' => 'Fisik', 'rontgen' => 'Rontgen thoraks']);
        $qrDataUri = $response->viewData('checkpoint')['qrDataUri'];
        $scanUrl = $response->viewData('checkpoint')['scanUrl'];
        $this->assertSame('/scan-mcu/'.$participant->id, parse_url($scanUrl, PHP_URL_PATH));
        $this->assertNull(parse_url($scanUrl, PHP_URL_QUERY));
        $expectedQr = new QrCode(data: $scanUrl, encoding: new Encoding('ISO-8859-1'), errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 320, margin: 24);
        $this->assertSame((new SvgWriter)->write($expectedQr)->getDataUri(), $qrDataUri);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $qrDataUri);
        $svg = base64_decode(substr($qrDataUri, strpos($qrDataUri, ',') + 1), true);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<path', $svg);
        $response->assertSee($qrDataUri, false);
    }

    public function test_scanned_url_opens_summary_without_redirecting_to_a_different_path(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi']);
        $url = '/'.$participant->rencana_kunjungan_id.'/peserta/'.$participant->id.'/pemeriksaan';
        $this->get($url)->assertOk()->assertViewIs('mcu.pemeriksaan')->assertViewHas('tab', 'ringkasan')
            ->assertViewHas('peserta', fn (Karyawan $peserta): bool => $peserta->is($participant));
    }

    public function test_scanned_url_keeps_login_role_and_visit_access_checks(): void
    {
        $participant = $this->participant(['ekg']);
        $url = route('pemeriksaan.scan', [$participant->rencanaKunjungan, $participant]);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'unknown']))->get($url)->assertForbidden();
        $other = Karyawan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'petugas']))
            ->get(route('pemeriksaan.scan', [$other->rencanaKunjungan, $participant]))->assertNotFound();
    }

    public function test_short_qr_link_preserves_local_host_and_opens_the_correct_participant(): void
    {
        $participant = $this->participant(['hipertensi']);
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $response = $this->get('http://127.0.0.1:8000/kunjungan-mcu/'.$participant->rencana_kunjungan_id.'/peserta/'.$participant->id.'/pemeriksaan?tab=identitas')->assertOk();
        $url = $response->viewData('checkpoint')['scanUrl'];
        $this->assertSame('127.0.0.1', parse_url($url, PHP_URL_HOST));
        $this->assertSame(8000, parse_url($url, PHP_URL_PORT));
        $this->assertSame('/scan-mcu/'.$participant->id, parse_url($url, PHP_URL_PATH));
        $this->assertLessThan(80, strlen($url));
        $response->assertSee('href="'.$url.'"', false);
        $target = route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant]);
        $this->get($url)->assertRedirect($target);
        $this->get($target)->assertOk()->assertViewHas('peserta', fn (Karyawan $peserta): bool => $peserta->is($participant));
    }

    public function test_short_and_truncated_legacy_scan_links_keep_authorization_and_visit_checks(): void
    {
        $participant = $this->participant(['ekg']);
        $short = route('pemeriksaan.qr', $participant);
        $legacy = route('pemeriksaan.scan-legacy', [$participant->rencanaKunjungan, $participant]);
        foreach ([$short, $legacy] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create(['role' => 'unknown']));
        foreach ([$short, $legacy] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $this->get($legacy)->assertOk()->assertViewHas('peserta', fn (Karyawan $peserta): bool => $peserta->is($participant));
        $other = Karyawan::factory()->create();
        $this->get(route('pemeriksaan.scan-legacy', [$other->rencanaKunjungan, $participant]))->assertNotFound();
        $participant->delete();
        $this->get($short)->assertNotFound();
    }

    public function test_print_card_has_patient_data_logo_and_a5_landscape_layout(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['hipertensi', 'ekg']);
        $identityUrl = route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']);
        $this->get(route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]))
            ->assertOk()->assertViewIs('mcu.checkpoint')->assertSee($participant->nama_karyawan)
            ->assertSee($participant->nomor_mcu)->assertSee($participant->paket->nama_paket)
            ->assertSee('size: A5 landscape', false)->assertSee('width: 210mm', false)->assertSee('height: 148mm', false)
            ->assertSee('alt="Nayaka"', false)->assertSee($identityUrl)->assertSee('Cetak / Simpan PDF')
            ->assertSee('Paraf:')->assertDontSee('KARTU CHECKPOINT MCU')->assertDontSee("window.addEventListener('load'", false)
            ->assertViewHas('checkpoint', fn (array $checkpoint): bool => array_keys($checkpoint['items']) === ['anamnesa', 'ekg']);
    }

    public function test_checkpoint_uses_examination_package_snapshot_when_master_package_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['ekg']);
        $participant->anamnesa()->create([
            'rencana_kunjungan_id' => $participant->rencana_kunjungan_id,
            'package_items' => ['hipertensi' => true, 'rontgen' => true],
        ]);
        $this->get(route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]))
            ->assertOk()->assertViewHas('checkpoint', fn (array $checkpoint): bool => array_keys($checkpoint['items']) === ['anamnesa', 'rontgen']);
    }

    public function test_print_checkpoint_does_not_bypass_photo_requirement(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();
        $this->get(route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]))
            ->assertRedirect(route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'identitas']));
    }

    public function test_historical_final_checkpoint_can_be_printed_without_a_photo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = Karyawan::factory()->create();
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        $this->get(route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]))
            ->assertOk()->assertSee('photo-placeholder');
    }

    public function test_checkpoint_requires_operator_access_and_correct_visit(): void
    {
        $participant = $this->participant(['ekg']);
        $url = route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'unknown']))->get($url)->assertForbidden();
        $other = Karyawan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'petugas']))
            ->get(route('pemeriksaan.checkpoint', [$other->rencanaKunjungan, $participant]))->assertNotFound();
    }

    public function test_patient_names_are_escaped_on_print_card(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'petugas']));
        $participant = $this->participant(['ekg']);
        $participant->update(['nama_karyawan' => '<script>alert(1)</script>']);
        $this->get(route('pemeriksaan.checkpoint', [$participant->rencanaKunjungan, $participant]))
            ->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    }

    /** @param list<string> $items */
    private function participant(array $items): Karyawan
    {
        $package = Paket::factory()->create(['active_items' => array_fill_keys($items, true)]);

        return Karyawan::factory()->withPhoto()->create(['paket_id' => $package->id]);
    }
}
