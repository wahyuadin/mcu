<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicAnamnesaTest extends TestCase
{
    use RefreshDatabase;

    public function test_visit_button_modal_and_print_page_use_qr_code_labels(): void
    {
        $participant = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('kunjungan-mcu.index'))->assertOk()
            ->assertSee('▦</span>QR Code</button>', false)
            ->assertSee('QR Code Anamnesa')->assertSee('title="QR Code formulir anamnesa"', false)
            ->assertDontSee('▦</span>Barcode</button>', false);
        $this->post(route('anamnesa.barcode', $participant->rencanaKunjungan))->assertOk()
            ->assertSee('QR Code Anamnesa')->assertSee('Cetak QR Code')->assertDontSee('Barcode Anamnesa');
    }

    public function test_barcode_requires_operator_access_and_reuses_encrypted_token(): void
    {
        $participant = $this->participant();
        $url = route('anamnesa.barcode', $participant->rencanaKunjungan);
        $this->post($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->post($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url)->assertOk()->assertSee('QR Code Anamnesa')->assertSee('Pratinjau formulir')->assertDontSee('Nonaktifkan barcode lama');
        $token = $participant->rencanaKunjungan->fresh()->anamnesa_token;
        $this->assertSame(64, strlen($token));
        $this->post($url)->assertOk()->assertSee(route('anamnesa.public', $token), false);
        $this->assertSame($token, $participant->rencanaKunjungan->fresh()->anamnesa_token);
        $this->assertNotSame($token, DB::table('rencana_kunjungans')->where('id', $participant->rencana_kunjungan_id)->value('anamnesa_token'));
        $this->assertArrayNotHasKey('anamnesa_token', $participant->rencanaKunjungan->fresh()->toArray());
        $this->get(route('kunjungan-mcu.index'))->assertOk()->assertSee('data-barcode-form', false)->assertSee('Buka peserta & hasil', false)->assertSee('type="submit" data-visit-action', false);
    }

    public function test_public_form_is_accessible_without_login_and_does_not_list_participants(): void
    {
        [$participant, $token] = $this->publicVisit();
        $this->get(route('anamnesa.public', $token))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('placeholder="Masukkan NIK KTP atau NIK/NIP karyawan"', false)->assertSee('Pilih tanggal, bulan, dan tahun lahir Anda.')
            ->assertSee('Identifikasi peserta')->assertDontSee($participant->nama_karyawan)->assertDontSee('data-public-anamnesa', false)->assertDontSee('name="password"', false);
        $this->get(route('anamnesa.public', str_repeat('a', 64)))->assertNotFound();
        $this->get(route('anamnesa.public', 'invalid'))->assertNotFound();
        $this->post(route('anamnesa.submit', $token), $this->answers())->assertForbidden();
    }

    public function test_completed_visit_disables_barcode_and_closes_public_form(): void
    {
        [$participant, $token] = $this->publicVisit();
        $participant->rencanaKunjungan->update(['status' => 'completed', 'is_closing' => false]);
        $this->get(route('anamnesa.public', $token))->assertStatus(410);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('kunjungan-mcu.index'))->assertOk()->assertSee('aria-haspopup="dialog" disabled', false);
        $this->post(route('anamnesa.barcode', $participant->rencanaKunjungan))->assertForbidden();
    }

    public function test_identity_is_visit_scoped_and_wrong_identity_does_not_reveal_a_name(): void
    {
        [$participant, $token] = $this->publicVisit();
        $other = $this->participant();
        $this->post(route('anamnesa.identify', $token), ['nip' => $other->nip, 'tanggal_lahir' => $other->tanggal_lahir->format('Y-m-d')])->assertSessionHasErrors('nip');
        $this->post(route('anamnesa.identify', $token), ['nip' => $participant->nip, 'tanggal_lahir' => '1900-01-01'])->assertSessionHasErrors('nip');
        $this->identify($participant, $token);
        $this->get(route('anamnesa.public', $token))->assertOk()->assertSee('data-public-anamnesa', false)->assertSee('Keluhan sekarang')->assertSee('Hepatitis')
            ->assertDontSee('checked', false)->assertDontSee('Tekanan darah')->assertSee($participant->nama_karyawan);
    }

    public function test_ambiguous_identity_is_rejected_instead_of_picking_a_patient(): void
    {
        [$participant, $token] = $this->publicVisit();
        Karyawan::factory()->create(['perusahaan_id' => $participant->perusahaan_id, 'rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $participant->paket_id, 'nip' => $participant->nip, 'tanggal_lahir' => $participant->tanggal_lahir]);
        $this->post(route('anamnesa.identify', $token), ['nip' => $participant->nip, 'tanggal_lahir' => $participant->tanggal_lahir->format('Y-m-d')])->assertSessionHasErrors('nip');
    }

    public function test_submission_is_encrypted_idempotent_and_does_not_create_or_overwrite_exam(): void
    {
        [$participant, $token] = $this->publicVisit();
        $this->identify($participant, $token);
        $this->post(route('anamnesa.submit', $token), $this->answers())->assertSessionHasNoErrors()->assertRedirect(route('anamnesa.public', $token));
        $submission = $participant->fresh()->anamnesa_submission;
        $this->assertSame('Tidak ada keluhan', $submission['answers']['keluhan_saat_ini']['answer']);
        $this->assertNull($submission['reviewed_at']);
        $this->assertDatabaseCount('anamnesas', 0);
        $raw = DB::table('karyawans')->where('id', $participant->id)->value('anamnesa_submission');
        $this->assertStringNotContainsString('Tidak ada keluhan', $raw);
        $this->assertArrayNotHasKey('anamnesa_submission', $participant->fresh()->toArray());
        $this->post(route('anamnesa.submit', $token), ['confirmed' => 1, 'answers' => ['keluhan_saat_ini' => ['answer' => 'Diubah']]])->assertSessionHasNoErrors();
        $this->assertSame($submission, $participant->fresh()->anamnesa_submission);
        $this->get(route('anamnesa.public', $token))->assertOk()->assertSee('Anamnesa berhasil dikirim')->assertDontSee('Tidak ada keluhan');
        $this->post(route('anamnesa.reset', $token))->assertRedirect(route('anamnesa.public', $token));
        $this->get(route('anamnesa.public', $token))->assertOk()->assertSee('Identifikasi peserta');
    }

    public function test_missing_answers_invalid_choices_and_extra_fields_are_rejected(): void
    {
        [$participant, $token] = $this->publicVisit();
        $this->identify($participant, $token);
        $this->post(route('anamnesa.submit', $token), ['answers' => []])->assertSessionHasErrors(['confirmed', 'answers']);
        $data = $this->answers();
        $data['answers']['hepatitis']['answer'] = 'Normal';
        $this->post(route('anamnesa.submit', $token), $data)->assertSessionHasErrors('answers.hepatitis.answer');
        $data = $this->answers();
        $data['answers']['hemoglobin'] = ['answer' => '14'];
        $this->post(route('anamnesa.submit', $token), $data)->assertSessionHasErrors('answers');
        $this->assertNull($participant->fresh()->anamnesa_submission);
    }

    public function test_closed_visit_revoked_token_and_expired_session_cannot_submit(): void
    {
        [$participant, $token] = $this->publicVisit();
        $this->identify($participant, $token);
        $this->travel(31)->minutes();
        $this->post(route('anamnesa.submit', $token), $this->answers())->assertForbidden();
        $this->travelBack();
        $participant->rencanaKunjungan->update(['is_closing' => true]);
        $this->get(route('anamnesa.public', $token))->assertStatus(410);
        $this->post(route('anamnesa.submit', $token), $this->answers())->assertStatus(410);
        $participant->rencanaKunjungan->update(['is_closing' => false]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->delete(route('anamnesa.disable', $participant->rencanaKunjungan))->assertRedirect(route('kunjungan-mcu.index'));
        $this->get(route('anamnesa.public', $token))->assertNotFound();
        $this->assertNull($participant->fresh()->anamnesa_submission);
    }

    public function test_package_changes_require_identification_again_and_final_exam_is_protected(): void
    {
        [$participant, $token] = $this->publicVisit();
        $this->identify($participant, $token);
        $participant->paket->update(['active_items' => ['hepatitis' => true]]);
        $this->post(route('anamnesa.submit', $token), $this->answers())->assertSessionHasErrors('form');
        $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'status' => 'final']);
        $this->post(route('anamnesa.identify', $token), ['nip' => $participant->nip, 'tanggal_lahir' => $participant->tanggal_lahir->format('Y-m-d')])->assertForbidden();
        $this->assertNull($participant->fresh()->anamnesa_submission);
    }

    public function test_operator_reviews_and_saves_through_existing_locking_without_automatic_overwrite(): void
    {
        [$participant, $token] = $this->publicVisit();
        $exam = $participant->anamnesa()->create(['rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'anamnesa_data' => ['keluhan_saat_ini' => ['answer' => 'Hasil petugas', 'note' => null]], 'lock_version' => 0]);
        $this->identify($participant, $token);
        $this->post(route('anamnesa.submit', $token), $this->answers())->assertSessionHasNoErrors();
        $this->assertSame('Hasil petugas', $exam->fresh()->anamnesa_data['keluhan_saat_ini']['answer']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $submission = $participant->fresh()->anamnesa_submission;
        $url = route('pemeriksaan.edit', [$participant->rencanaKunjungan, $participant, 'tab' => 'anamnesa', 'review_submission' => 1]);
        $this->get($url)->assertOk()->assertSee('Tidak ada keluhan')->assertSee('name="submission_id"', false)->assertSee('Hasil pemeriksaan belum diubah');
        $update = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($update, ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 99, 'submission_id' => $submission['id'], 'anamnesa' => $submission['answers']])->assertSessionHasErrors('pemeriksaan');
        $this->assertNull($participant->fresh()->anamnesa_submission['reviewed_at']);
        $this->put($update, ['section' => 'anamnesa', 'status' => 'selesai', 'lock_version' => 0, 'submission_id' => $submission['id'], 'anamnesa' => $submission['answers']])->assertSessionHasNoErrors();
        $this->assertSame('Tidak ada keluhan', $exam->fresh()->anamnesa_data['keluhan_saat_ini']['answer']);
        $this->assertNotNull($participant->fresh()->anamnesa_submission['reviewed_at']);
        $this->assertSame(1, $exam->fresh()->lock_version);
    }

    public function test_identity_attempts_are_rate_limited(): void
    {
        [$participant, $token] = $this->publicVisit();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('anamnesa.identify', $token), ['nip' => 'unknown', 'tanggal_lahir' => '2000-01-01'])->assertSessionHasErrors('nip');
        }
        $this->post(route('anamnesa.identify', $token), ['nip' => 'unknown', 'tanggal_lahir' => '2000-01-01'])->assertStatus(429);
    }

    public function test_public_logo_is_scoped_to_token_and_never_exposes_other_private_files(): void
    {
        Storage::fake('local');
        [$participant, $token] = $this->publicVisit();
        $participant->perusahaan->update(['logo_path' => 'perusahaan/example.webp']);
        Storage::disk('local')->put('perusahaan/example.webp', 'company-logo');
        $this->get(route('anamnesa.logo', $token))->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('anamnesa.public', $token))->assertOk()->assertSee(route('anamnesa.logo', $token), false)->assertDontSee(route('perusahaan.logo', $participant->perusahaan), false);
        $participant->perusahaan->update(['logo_path' => 'mcu/private-medical.webp']);
        Storage::disk('local')->put('mcu/private-medical.webp', 'private');
        $this->get(route('anamnesa.logo', $token))->assertNotFound();
    }

    public function test_identification_always_returns_to_form_even_when_logo_was_previous_request(): void
    {
        Storage::fake('local');
        [$participant, $token] = $this->publicVisit();
        $participant->perusahaan->update(['logo_path' => 'perusahaan/identification.webp']);
        Storage::disk('local')->put('perusahaan/identification.webp', 'logo');
        $form = route('anamnesa.public', $token);
        $logo = route('anamnesa.logo', $token);
        $this->get($form)->assertOk()->assertSee('<span>NIK KTP atau NIK/NIP karyawan <span class="text-red-700">*</span></span>', false);
        $this->get($logo)->assertOk();
        $this->from($logo)->post(route('anamnesa.identify', $token), [])->assertSessionHasErrors(['nip', 'tanggal_lahir'])->assertRedirect($form);
        $this->from($logo)->post(route('anamnesa.identify', $token), ['nip' => 'wrong', 'tanggal_lahir' => '2000-01-01'])->assertSessionHasErrors('nip')->assertRedirect($form);
        $this->from($logo)->post(route('anamnesa.identify', $token), ['nip' => $participant->nip, 'tanggal_lahir' => $participant->tanggal_lahir->format('Y-m-d')])->assertSessionHasNoErrors()->assertRedirect($form);
        $this->get($form)->assertOk()->assertSee('data-public-anamnesa', false)->assertSee('Keluhan sekarang');
        $this->from($logo)->post(route('anamnesa.submit', $token), [])->assertSessionHasErrors('confirmed')->assertRedirect($form);
    }

    public function test_verification_cannot_be_reused_for_another_visit_and_invalid_status_blocks_submission(): void
    {
        [$participant, $token] = $this->publicVisit();
        $other = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('anamnesa.barcode', $other->rencanaKunjungan))->assertOk();
        $otherToken = $other->rencanaKunjungan->fresh()->anamnesa_token;
        $this->post(route('logout'));
        $this->identify($participant, $token);
        $this->post(route('anamnesa.submit', $otherToken), $this->answers())->assertForbidden();
        $participant->update(['status_pemeriksaan' => 'batal']);
        $this->post(route('anamnesa.submit', $token), $this->answers())->assertForbidden();
        $this->assertNull($participant->fresh()->anamnesa_submission);
        $this->assertNull($other->fresh()->anamnesa_submission);
    }

    public function test_identification_accepts_registered_ktp_nik_and_trims_identifier_spaces(): void
    {
        [$participant, $token] = $this->publicVisit();
        $participant->update(['nik' => '0012345678901234', 'nip' => ' EMP-007 ']);
        $url = route('anamnesa.identify', $token);
        $birthdate = $participant->tanggal_lahir->format('Y-m-d');
        $this->post($url, ['nip' => ' 0012345678901234 ', 'tanggal_lahir' => $birthdate])->assertSessionHasNoErrors()->assertRedirect(route('anamnesa.public', $token));
        $this->get(route('anamnesa.public', $token))->assertOk()->assertSee('data-public-anamnesa', false);
        $this->post(route('anamnesa.reset', $token));
        $this->post($url, ['nip' => 'EMP-007', 'tanggal_lahir' => $birthdate])->assertSessionHasNoErrors();
        $this->post($url, ['nip' => '12345678901234', 'tanggal_lahir' => $birthdate])->assertSessionHasErrors('nip');
    }

    public function test_ktp_nik_matching_remains_visit_scoped_and_rejects_ambiguous_identifiers(): void
    {
        [$participant, $token] = $this->publicVisit();
        $participant->update(['nik' => '0012345678901234']);
        $other = $this->participant();
        $other->update(['nik' => '0098765432109876']);
        $url = route('anamnesa.identify', $token);
        $this->post($url, ['nip' => $other->nik, 'tanggal_lahir' => $other->tanggal_lahir->format('Y-m-d')])->assertSessionHasErrors('nip');
        $this->post($url, ['nip' => $participant->nik, 'tanggal_lahir' => '1900-01-01'])->assertSessionHasErrors('nip');
        Karyawan::factory()->create(['perusahaan_id' => $participant->perusahaan_id, 'rencana_kunjungan_id' => $participant->rencana_kunjungan_id, 'paket_id' => $participant->paket_id, 'nip' => $participant->nik, 'tanggal_lahir' => $participant->tanggal_lahir]);
        $this->post($url, ['nip' => $participant->nik, 'tanggal_lahir' => $participant->tanggal_lahir->format('Y-m-d')])->assertSessionHasErrors('nip');
    }

    public function test_spa_identification_submission_and_reset_return_next_screen_without_redirects(): void
    {
        [$participant, $token] = $this->publicVisit();
        $this->postJson(route('anamnesa.identify', $token), ['nip' => $participant->nip, 'tanggal_lahir' => $participant->tanggal_lahir->format('Y-m-d')])->assertOk()
            ->assertSee('data-anamnesa-app', false)->assertSee('data-answer-input', false)->assertDontSee('data-complaint-tags', false)->assertSee($participant->nama_karyawan);
        $data = $this->answers();
        $data['complaint_tags'] = ['Pusing', 'Nyeri punggung'];
        $this->postJson(route('anamnesa.submit', $token), $data)->assertOk()->assertSee('Anamnesa berhasil dikirim');
        $this->assertSame('Pusing; Nyeri punggung', $participant->fresh()->anamnesa_submission['answers']['keluhan_saat_ini']['answer']);
        $this->assertDatabaseCount('anamnesas', 0);
        $this->postJson(route('anamnesa.reset', $token))->assertOk()->assertSee('Identifikasi peserta')->assertDontSee($participant->nama_karyawan);
    }

    public function test_spa_validation_returns_field_errors_without_losing_verified_session(): void
    {
        [$participant, $token] = $this->publicVisit();
        $this->postJson(route('anamnesa.identify', $token), ['nip' => 'wrong', 'tanggal_lahir' => '2000-01-01'])->assertUnprocessable()->assertJsonValidationErrors('nip');
        $this->identify($participant, $token);
        $this->postJson(route('anamnesa.submit', $token), ['confirmed' => 1, 'answers' => []])->assertUnprocessable()->assertJsonValidationErrors('answers');
        $this->get(route('anamnesa.public', $token))->assertOk()->assertSee('data-public-anamnesa', false);
        $this->assertNull($participant->fresh()->anamnesa_submission);
    }

    public function test_public_form_uses_single_line_complaint_and_medication_inputs_and_taller_notes(): void
    {
        [$participant, $token] = $this->publicVisit();
        $participant->paket->update(['active_items' => ['keluhan_saat_ini' => true, 'hepatitis' => true, 'riwayat_obat' => true]]);
        $this->identify($participant, $token);
        $response = $this->get(route('anamnesa.public', $token))->assertOk()
            ->assertSee('type="text" id="answer-keluhan_saat_ini"', false)->assertSee('type="text" id="answer-riwayat_obat"', false)
            ->assertDontSee('<textarea id="answer-keluhan_saat_ini"', false)->assertDontSee('<textarea id="answer-riwayat_obat"', false)
            ->assertDontSee('data-complaint-tags', false)->assertDontSee('data-tag-add', false)
            ->assertSee('placeholder="Tuliskan keluhan Anda, atau Tidak ada keluhan"', false)
            ->assertSee('placeholder="Tuliskan riwayat obat-obatan Anda, atau Tidak ada"', false)
            ->assertSee('placeholder="Tambahkan rincian atau sejak kapan (opsional)"', false)
            ->assertSee('data-anamnesa-confirm', false)->assertSee('data-confirm-cancel', false)->assertSee('data-confirm-accept', false)
            ->assertSee('aria-labelledby="review-title"', false)->assertSee('<h2 id="review-title"', false)->assertDontSee('<legend class="text-lg font-semibold">Periksa jawaban', false);
        $this->assertSame(3, substr_count($response->getContent(), 'style="height: 83px;"'));
        $data = $this->answers();
        $data['answers']['keluhan_saat_ini']['answer'] = 'Pusing saat bangun tidur';
        $data['answers']['riwayat_obat'] = ['answer' => 'Amlodipine 5 mg', 'note' => 'Sejak tahun lalu'];
        $this->postJson(route('anamnesa.submit', $token), $data)->assertOk()->assertSee('Anamnesa berhasil dikirim');
        $submission = $participant->fresh()->anamnesa_submission;
        $this->assertSame('Pusing saat bangun tidur', $submission['answers']['keluhan_saat_ini']['answer']);
        $this->assertSame('Amlodipine 5 mg', $submission['answers']['riwayat_obat']['answer']);
    }

    public function test_complaint_tags_reject_conflicting_empty_duplicate_and_oversized_values(): void
    {
        [$participant, $token] = $this->publicVisit();
        $this->identify($participant, $token);
        $url = route('anamnesa.submit', $token);
        foreach ([[], ['Tidak ada keluhan', 'Pusing'], ['Pusing', 'pusing'], [str_repeat('A', 101)], array_fill(0, 21, 'Pusing')] as $tags) {
            $data = $this->answers();
            $data['complaint_tags'] = $tags;
            $this->postJson($url, $data)->assertUnprocessable();
        }
        $this->assertNull($participant->fresh()->anamnesa_submission);
        $data = $this->answers();
        $data['complaint_tags'] = ['Tidak ada keluhan'];
        $this->postJson($url, $data)->assertOk()->assertSee('Anamnesa berhasil dikirim');
        $this->assertSame('Tidak ada keluhan', $participant->fresh()->anamnesa_submission['answers']['keluhan_saat_ini']['answer']);
    }

    public function test_review_requires_current_submission_and_failed_validation_does_not_mark_it_reviewed(): void
    {
        [$participant, $token] = $this->publicVisit();
        $this->identify($participant, $token);
        $this->post(route('anamnesa.submit', $token), $this->answers())->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $submission = $participant->fresh()->anamnesa_submission;
        $url = route('pemeriksaan.update', [$participant->rencanaKunjungan, $participant]);
        $this->put($url, ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0, 'submission_id' => 'wrong', 'anamnesa' => $submission['answers']])->assertSessionHasErrors('pemeriksaan');
        $this->put($url, ['section' => 'anamnesa', 'status' => 'selesai', 'lock_version' => 0, 'submission_id' => $submission['id'], 'anamnesa' => []])->assertSessionHasErrors('kelengkapan');
        $this->assertNull($participant->fresh()->anamnesa_submission['reviewed_at']);
        $this->assertDatabaseCount('anamnesas', 0);
        $participant->paket->update(['active_items' => ['keluhan_saat_ini' => true]]);
        $this->put($url, ['section' => 'anamnesa', 'status' => 'draft', 'lock_version' => 0, 'submission_id' => $submission['id'], 'anamnesa' => $submission['answers']])->assertSessionHasErrors('pemeriksaan');
    }

    private function participant(): Karyawan
    {
        $package = Paket::factory()->master()->create(['active_items' => ['keluhan_saat_ini' => true, 'hepatitis' => true]]);
        $participant = Karyawan::factory()->withPhoto()->create(['nip' => 'NIP-'.bin2hex(random_bytes(4)), 'tanggal_lahir' => '1988-04-17']);
        $participant->rencanaKunjungan->pakets()->attach($package);
        $participant->update(['paket_id' => $package->id]);

        return $participant->fresh();
    }

    /** @return array{Karyawan, string} */
    private function publicVisit(): array
    {
        $participant = $this->participant();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('anamnesa.barcode', $participant->rencanaKunjungan))->assertOk();
        $token = $participant->rencanaKunjungan->fresh()->anamnesa_token;
        $this->post(route('logout'));

        return [$participant, $token];
    }

    private function identify(Karyawan $participant, string $token): void
    {
        $this->post(route('anamnesa.identify', $token), ['nip' => $participant->nip, 'tanggal_lahir' => $participant->tanggal_lahir->format('Y-m-d')])->assertSessionHasNoErrors()->assertRedirect(route('anamnesa.public', $token));
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        return ['confirmed' => 1, 'answers' => ['keluhan_saat_ini' => ['answer' => 'Tidak ada keluhan', 'note' => ''], 'hepatitis' => ['answer' => 'Tidak', 'note' => '']]];
    }
}
