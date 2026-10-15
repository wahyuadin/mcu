<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\RencanaKunjungan;
use App\ParticipantAnamnesaForm;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicAnamnesaController extends Controller
{
    public function barcode(RencanaKunjungan $kunjungan): Response
    {
        DB::transaction(function () use ($kunjungan): void {
            $kunjungan->refresh();
            $locked = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->is_closing || $locked->status === 'completed', 403, 'Kunjungan sudah ditutup.');
            if (! $locked->anamnesa_token) {
                $locked->anamnesa_token = bin2hex(random_bytes(32));
                $locked->anamnesa_token_hash = hash('sha256', $locked->anamnesa_token);
                $locked->save();
            }
            $kunjungan->setRawAttributes($locked->getAttributes(), true);
        });
        $url = route('anamnesa.public', $kunjungan->anamnesa_token);
        $qr = (new SvgWriter)->write(new QrCode(data: $url, size: 320, margin: 24))->getDataUri();

        return $this->page('mcu.anamnesa-barcode', ['kunjungan' => $kunjungan, 'url' => $url, 'qr' => $qr]);
    }

    public function disable(RencanaKunjungan $kunjungan): RedirectResponse
    {
        DB::transaction(function () use ($kunjungan): void {
            $visit = RencanaKunjungan::whereKey($kunjungan->id)->lockForUpdate()->firstOrFail();
            $visit->anamnesa_token = null;
            $visit->anamnesa_token_hash = null;
            $visit->save();
        });

        return redirect()->route('kunjungan-mcu.index')->with('success', 'Barcode lama dinonaktifkan. Buka Barcode untuk membuat tautan baru.');
    }

    public function show(Request $request, string $token): Response
    {
        $visit = $this->visit($token);
        $verification = $request->session()->get($this->sessionKey($token));
        $participant = null;
        $groups = [];
        if (is_array($verification) && ($verification['expires'] ?? 0) > now()->timestamp) {
            $participant = $visit->karyawans()->with('paket', 'anamnesa')->find($verification['participant']);
            if ($participant) {
                $this->editable($participant);
                $groups = ParticipantAnamnesaForm::groups($participant);
            }
        }

        return $this->page('mcu.anamnesa-public', [
            'kunjungan' => $visit, 'token' => $token, 'participant' => $participant,
            'groups' => $groups, 'sent' => $participant?->anamnesa_submission !== null,
        ]);
    }

    public function identify(Request $request, string $token): Response|RedirectResponse
    {
        try {
            $visit = $this->visit($token);
            $request->session()->forget($this->sessionKey($token));
            $data = $request->validate(['nip' => ['required', 'string', 'max:50'], 'tanggal_lahir' => ['required', 'date_format:Y-m-d']]);
            $identifier = trim($data['nip']);
            $matches = $visit->karyawans()->where(function (Builder $query) use ($identifier): void {
                $query->whereRaw('TRIM(nip) = ?', [$identifier])->orWhereRaw('TRIM(nik) = ?', [$identifier]);
            })->whereDate('tanggal_lahir', $data['tanggal_lahir'])->limit(2)->get();
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['nip' => 'Data belum dapat dicocokkan. Periksa NIK KTP atau NIK/NIP karyawan dan tanggal lahir, atau hubungi petugas.']);
            }
            $participant = $matches->first();
            $this->editable($participant);
            $groups = ParticipantAnamnesaForm::groups($participant);
            if ($groups === []) {
                throw ValidationException::withMessages(['nip' => 'Formulir anamnesa belum tersedia untuk paket ini. Hubungi petugas.']);
            }
            $request->session()->regenerate();
            $request->session()->put($this->sessionKey($token), ['participant' => $participant->id, 'expires' => now()->addMinutes(30)->timestamp, 'schema' => hash('sha256', json_encode($groups))]);

            return $request->expectsJson() ? $this->show($request, $token) : redirect()->route('anamnesa.public', $token);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('anamnesa.public', $token));
        }
    }

    public function submit(Request $request, string $token): Response|RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $token): void {
                $visit = $this->visit($token, true);
                $verification = $request->session()->get($this->sessionKey($token));
                abort_unless(is_array($verification) && ($verification['expires'] ?? 0) > now()->timestamp, 403, 'Sesi berakhir. Identifikasi peserta kembali.');
                $participant = $visit->karyawans()->whereKey($verification['participant'])->lockForUpdate()->firstOrFail();
                $this->editable($participant);
                if ($participant->anamnesa_submission !== null) {
                    return;
                }
                $groups = ParticipantAnamnesaForm::groups($participant);
                if (! hash_equals($verification['schema'], hash('sha256', json_encode($groups)))) {
                    $request->session()->forget($this->sessionKey($token));
                    throw ValidationException::withMessages(['form' => 'Paket berubah. Identifikasi kembali sebelum mengisi formulir.']);
                }
                $definitions = collect($groups)->collapse()->all();
                $tagData = $request->validate([
                    'complaint_tags' => isset($definitions['keluhan_saat_ini']) ? ['sometimes', 'array', 'list', 'min:1', 'max:20'] : ['prohibited'],
                    'complaint_tags.*' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
                ]);
                if (isset($tagData['complaint_tags'])) {
                    $tags = array_map('trim', $tagData['complaint_tags']);
                    if (count($tags) > 1 && in_array('tidak ada keluhan', array_map('mb_strtolower', $tags), true)) {
                        throw ValidationException::withMessages(['complaint_tags' => '“Tidak ada keluhan” tidak dapat digabung dengan keluhan lain.']);
                    }
                    $answers = $request->input('answers', []);
                    if (is_array($answers) && (! isset($answers['keluhan_saat_ini']) || is_array($answers['keluhan_saat_ini']))) {
                        $answers['keluhan_saat_ini']['answer'] = implode('; ', $tags);
                        $request->merge(['answers' => $answers]);
                    }
                }
                $rules = ['confirmed' => ['accepted'], 'answers' => ['required', 'array:'.implode(',', array_keys($definitions))]];
                foreach ($definitions as $key => $item) {
                    $rules['answers.'.$key] = ['required', 'array:answer,note'];
                    $rules['answers.'.$key.'.answer'] = ['required', 'string', 'max:2000'];
                    if ($item['type'] === 'choice') {
                        $rules['answers.'.$key.'.answer'][] = Rule::in(['Ya', 'Tidak']);
                    }
                    $rules['answers.'.$key.'.note'] = ['nullable', 'string', 'max:2000'];
                }
                $data = $request->validate($rules);
                $data['answers'] = array_map(fn (array $answer): array => array_replace(['note' => null], $answer), $data['answers']);
                $participant->anamnesa_submission = ['id' => bin2hex(random_bytes(16)), 'groups' => $groups, 'answers' => $data['answers'], 'submitted_at' => now()->toIso8601String(), 'reviewed_at' => null];
                $participant->save();
            });

            return $request->expectsJson() ? $this->show($request, $token) : redirect()->route('anamnesa.public', $token);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('anamnesa.public', $token));
        }
    }

    public function reset(Request $request, string $token): Response|RedirectResponse
    {
        $this->visit($token);
        $request->session()->forget($this->sessionKey($token));

        return $request->expectsJson() ? $this->show($request, $token) : redirect()->route('anamnesa.public', $token);
    }

    public function logo(string $token): StreamedResponse
    {
        $company = $this->visit($token)->perusahaan;
        abort_unless($company->logo_path && str_starts_with($company->logo_path, 'perusahaan/') && Storage::disk('local')->exists($company->logo_path), 404);

        return Storage::disk('local')->response($company->logo_path, null, ['Cache-Control' => 'no-store, private', 'Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function visit(string $token, bool $lock = false): RencanaKunjungan
    {
        $query = RencanaKunjungan::where('anamnesa_token_hash', hash('sha256', $token));
        $visit = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_if($visit->is_closing || $visit->status === 'completed', 410, 'Formulir kunjungan ini sudah ditutup.');

        return $visit;
    }

    private function editable(Karyawan $participant): void
    {
        abort_unless($participant->rencanaKunjungan->pakets()->whereKey($participant->paket_id)->exists(), 403, 'Paket belum tersedia. Hubungi petugas.');
        abort_if($participant->is_closing || in_array($participant->status_pemeriksaan, ['batal', 'tidak_hadir', 'selesai'], true) || $participant->anamnesa?->status === 'final', 403, 'Formulir tidak tersedia. Hubungi petugas.');
    }

    private function sessionKey(string $token): string
    {
        return 'public_anamnesa.'.hash('sha256', $token);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('X-Frame-Options', 'SAMEORIGIN');
    }
}
