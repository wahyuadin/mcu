<?php

namespace App\Http\Controllers;

use App\ExaminationLabel;
use App\McuCatalog;
use App\McuReport;
use App\Models\Anamnesa;
use App\Models\Dokter;
use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\RencanaKunjungan;
use App\ParticipantAnamnesaForm;
use App\PatientPhoto;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PemeriksaanController extends Controller
{
    public function edit(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta): View|RedirectResponse
    {
        $this->assertParticipant($kunjungan, $peserta);
        $peserta->load('paket', 'anamnesa.dokter', 'pasien', 'perusahaan');
        $exam = $peserta->anamnesa ?? new Anamnesa;
        $labelService = new ExaminationLabel;
        $labelTarget = $labelService->navigationTarget($peserta, $exam, $request->query('tab'), $request->query('subcategory'));
        $readonly = $kunjungan->is_closing || $peserta->is_closing || $exam->status === 'final' || in_array($peserta->status_pemeriksaan, ['batal', 'tidak_hadir'], true);
        if (! $readonly && (! $peserta->hasStoredPhoto() || $request->query('tab') === 'foto')) {
            return view('mcu.pemeriksaan-foto', ['kunjungan' => $kunjungan, 'peserta' => $peserta, 'hasPhoto' => $peserta->hasStoredPhoto(), 'labelTarget' => $labelTarget]);
        }
        $selectedDoctors = array_filter([$exam->dokter_id, $exam->dokter_fisik_id, ...array_column($exam->penunjang ?? [], 'doctor_id'), ...array_values($exam->category_doctors ?? [])]);
        $categoryGroups = $exam->categoryGroups($peserta);
        if ($request->query('tab') === 'dinamis') {
            return redirect()->route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => array_key_first($categoryGroups) ?? 'ringkasan']);
        }
        $progress = $exam->workflowProgress($peserta);
        $tabs = ['ringkasan' => 'Ringkasan', 'identitas' => 'Identitas MCU', 'anamnesa' => 'Anamnesis', 'fisik' => 'Fisik', 'laboratorium' => 'Laboratorium', 'penunjang' => 'Penunjang', 'kesimpulan' => 'Kesimpulan', 'review' => 'Review'];
        foreach (['laboratorium', 'penunjang'] as $section) {
            if (! $progress[$section]['included']) {
                unset($tabs[$section]);
            }
        }
        $tabs = array_merge(array_slice($tabs, 0, -2, true), array_map(fn (array $group): string => $group['name'], $categoryGroups), array_slice($tabs, -2, null, true));
        $tab = $request->query('tab', 'ringkasan');
        $examinations = ['identitas' => [
            'label' => 'Identitas MCU', 'description' => 'Data pasien, foto, QR identitas & kartu checkpoint',
            'status' => $exam->identityProgress($peserta)['status'], 'tab' => 'identitas', 'anchor' => null, 'skipped' => false,
        ]];
        foreach (['fisik' => collect(McuCatalog::physical())->collapse()->all(), 'anamnesa' => Paket::getDefaultItemCatalog()['anamnesa_riwayat'], 'laboratorium' => collect(McuCatalog::laboratory())->collapse()->all()] as $key => $items) {
            if ($progress[$key]['included']) {
                $count = count(array_filter(array_keys($items), fn (string $item): bool => $exam->includes($peserta, $item)));
                $examinations[$key] = [
                    'label' => $key === 'anamnesa' ? 'Anamnesis' : $progress[$key]['label'],
                    'description' => $count.' '.match ($key) {
                        'fisik' => 'item pemeriksaan', 'anamnesa' => 'item riwayat & kebiasaan', default => 'parameter pemeriksaan',
                    },
                    'status' => $progress[$key]['status'], 'tab' => $key, 'anchor' => null, 'skipped' => false,
                ];
            }
        }
        foreach ($exam->supportingProgress($peserta) as $key => $item) {
            $examinations[$key] = [
                'label' => $item['label'], 'description' => in_array($key, ['rontgen', 'ekg'], true) ? 'Hasil, gambar & pemeriksa' : 'Ringkasan pemeriksaan',
                'status' => $item['status'], 'tab' => 'penunjang', 'anchor' => $key, 'skipped' => $item['skipped'],
            ];
        }
        $completion = $exam->examinationCompletion($peserta);
        foreach ($categoryGroups as $key => $group) {
            $examinations[$key] = ['label' => $group['name'], 'description' => count($group['items']).' item pemeriksaan', 'status' => $progress[$key]['status'], 'tab' => $key, 'anchor' => null, 'skipped' => false];
        }

        $labelCategories = $tab === 'identitas' ? $labelService->categories($peserta, $exam) : [];

        return view('mcu.pemeriksaan', [
            'kunjungan' => $kunjungan, 'peserta' => $peserta, 'exam' => $exam, 'tabs' => $tabs,
            'categoryGroups' => $categoryGroups,
            'reviewSubmission' => $request->boolean('review_submission') && $tab === 'anamnesa' && ! $readonly && $peserta->anamnesa_submission && ! ($peserta->anamnesa_submission['reviewed_at'] ?? null),
            'tab' => is_string($tab) && array_key_exists($tab, $tabs) ? $tab : 'ringkasan',
            'progress' => $progress, 'finalizationErrors' => $exam->finalizationErrors($peserta),
            'examinations' => $examinations, 'completedExaminations' => $completion['completed'],
            'completionPercent' => $completion['percent'],
            'anamnesaGroups' => McuCatalog::anamnesa() + ['Riwayat tambahan (paket lama)' => [
                'asma' => ['label' => 'Asma / alergi (item lama)', 'type' => 'choice'],
                'ginjal' => ['label' => 'Penyakit ginjal (item lama)', 'type' => 'choice'],
            ]],
            'physicalGroups' => McuCatalog::physical(), 'laboratoryGroups' => McuCatalog::laboratory(),
            'supportingItems' => McuCatalog::supporting(), 'doctors' => Dokter::where(function ($query) use ($selectedDoctors): void {
                $query->where('is_active', true)->orWhereIn('id', $selectedDoctors);
            })->orderBy('nama_dokter')->get(),
            'readonly' => $readonly,
            'checkpoint' => $tab === 'identitas' ? $this->checkpointData($kunjungan, $peserta, $exam) : null,
            'labelCategories' => $labelCategories,
            'labelBarcodes' => $tab === 'identitas' ? $labelService->barcodes($peserta, $labelCategories) : null,
        ]);
    }

    public function labels(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta): Response|RedirectResponse
    {
        $this->assertParticipant($kunjungan, $peserta);
        $peserta->load('paket', 'anamnesa');
        $exam = $peserta->anamnesa ?? new Anamnesa;
        $readonly = $kunjungan->is_closing || $exam->status === 'final' || in_array($peserta->status_pemeriksaan, ['batal', 'tidak_hadir'], true);
        if (! $readonly && ! $peserta->hasStoredPhoto()) {
            return redirect()->route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'identitas']);
        }
        $categories = collect((new ExaminationLabel)->categories($peserta, $exam))->where('enabled', true)->keyBy('id');
        $data = $request->validate([
            'category' => ['required', 'uuid', Rule::in($categories->keys()->all())],
            'subcategories' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'subcategories.*' => ['required', 'uuid', 'distinct'],
        ]);
        $category = $categories[$data['category']];
        $allowed = array_column($category['subcategories'], 'id');
        $request->validate(['subcategories.*' => [Rule::in($allowed)]]);
        $selected = array_values(array_filter($category['subcategories'], fn (array $subcategory): bool => in_array($subcategory['id'], $data['subcategories'], true)));
        $selectedCategory = array_replace($category, ['subcategories' => $selected]);

        return response()->view('mcu.labels', [
            'category' => $category, 'subcategories' => $selected, 'peserta' => $peserta,
            'printedAt' => now()->format('d:m:Y H:i:s'),
            'barcodes' => (new ExaminationLabel)->barcodes($peserta, [$selectedCategory])[$category['id']],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function checkpoint(RencanaKunjungan $kunjungan, Karyawan $peserta): View|RedirectResponse
    {
        $this->assertParticipant($kunjungan, $peserta);
        $peserta->load('paket', 'anamnesa', 'perusahaan');
        $exam = $peserta->anamnesa ?? new Anamnesa;
        $readonly = $kunjungan->is_closing || $exam->status === 'final' || in_array($peserta->status_pemeriksaan, ['batal', 'tidak_hadir'], true);
        if (! $readonly && ! $peserta->hasStoredPhoto()) {
            return redirect()->route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'identitas']);
        }

        return view('mcu.checkpoint', [
            'kunjungan' => $kunjungan, 'peserta' => $peserta,
            'checkpoint' => $this->checkpointData($kunjungan, $peserta, $exam),
        ]);
    }

    /** @return array{identityUrl: string, scanUrl: string, qrDataUri: string, items: array<string, string>} */
    private function checkpointData(RencanaKunjungan $kunjungan, Karyawan $peserta, Anamnesa $exam): array
    {
        $identityUrl = route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'identitas']);
        $scanUrl = route('pemeriksaan.scan', [$kunjungan, $peserta]);
        $qr = new QrCode(data: $scanUrl, errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 320, margin: 24);
        $progress = $exam->workflowProgress($peserta);
        $items = [];
        foreach (['anamnesa' => 'Anamnesa', 'fisik' => 'Fisik', 'laboratorium' => 'Laboratorium'] as $key => $label) {
            if ($progress[$key]['included']) {
                $items[$key] = $label;
            }
        }
        foreach ($exam->supportingProgress($peserta) as $key => $item) {
            $items[$key] = $item['label'];
        }
        foreach ($exam->dynamicGroups($peserta) as $group) {
            $items['dynamic_'.$group['id']] = $group['name'];
        }

        return ['identityUrl' => $identityUrl, 'scanUrl' => $scanUrl, 'qrDataUri' => (new SvgWriter)->write($qr)->getDataUri(), 'items' => $items];
    }

    public function update(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta): RedirectResponse
    {
        $this->assertParticipant($kunjungan, $peserta);
        $exam = $peserta->anamnesa ?? new Anamnesa;
        $request->validate([
            'section' => ['sometimes', Rule::in(['identitas', 'anamnesa', 'fisik', 'laboratorium', 'penunjang', 'dinamis', 'kesimpulan', ...array_keys($exam->categoryGroups($peserta))])],
            'status' => ['required', Rule::in(['draft', 'selesai', 'final'])],
            'lock_version' => [Rule::requiredIf($request->filled('section')), 'integer', 'min:0'],
            'dynamic_files' => in_array($request->input('section'), ['dinamis', 'laboratorium', ...array_keys($exam->categoryGroups($peserta))], true) ? ['sometimes', 'array'] : ['prohibited'],
            'category_doctors' => array_key_exists($request->input('section', ''), $exam->categoryGroups($peserta)) ? ['sometimes', 'array'] : ['prohibited'],
        ]);
        $section = $request->input('section');
        $storedAttachments = [];
        try {
            DB::transaction(function () use ($request, $kunjungan, $peserta, $section, &$storedAttachments): void {
                $this->lockEditable($kunjungan, $peserta);
                $this->requirePhoto($peserta);
                $exam = $peserta->anamnesa()->first() ?? new Anamnesa(['karyawan_id' => $peserta->id, 'rencana_kunjungan_id' => $kunjungan->id]);
                abort_if($exam->status === 'final', 403, 'Hasil final hanya dapat diubah melalui revisi.');
                if ($section !== null && (int) $request->input('lock_version', -1) !== (int) ($exam->lock_version ?? 0)) {
                    throw ValidationException::withMessages(['pemeriksaan' => 'Data berubah di sesi lain. Muat ulang halaman sebelum menyimpan.']);
                }
                $exam->package_items ??= $peserta->paket->active_items ?? [];
                $exam->dynamic_schema ??= $exam->dynamicGroups($peserta);
                if ($section === 'identitas') {
                    $data = $request->validate([
                        'tanggal_mcu' => ['nullable', 'date'], 'nip' => ['nullable', 'string', 'max:50'],
                        'bagian' => ['nullable', 'string', 'max:255'], 'jabatan' => ['nullable', 'string', 'max:255'],
                    ]);
                    $peserta->update($data);
                } elseif ($section === 'anamnesa') {
                    if ($request->filled('submission_id')) {
                        $submission = $peserta->anamnesa_submission;
                        if (! $submission || ($submission['reviewed_at'] ?? null) || ! hash_equals($submission['id'], (string) $request->input('submission_id')) || $submission['groups'] !== ParticipantAnamnesaForm::groups($peserta)) {
                            throw ValidationException::withMessages(['pemeriksaan' => 'Jawaban peserta atau paket berubah. Muat ulang dan tinjau kembali.']);
                        }
                        $submission['reviewed_at'] = now()->toIso8601String();
                        $submission['reviewed_by'] = $request->user()->id;
                        $peserta->anamnesa_submission = $submission;
                        $peserta->save();
                    }
                    $this->saveHistory($request, $peserta, $exam);
                } elseif ($section === 'fisik') {
                    $this->savePhysical($request, $peserta, $exam);
                } elseif ($section === 'laboratorium') {
                    $this->saveLaboratory($request, $peserta, $exam, $storedAttachments);
                } elseif ($section === 'penunjang') {
                    $this->saveSupporting($request, $peserta, $exam);
                } elseif ($section === 'kesimpulan') {
                    $exam->fill($request->validate([
                        'catatan_dokter' => ['nullable', 'string', 'max:5000'], 'anjuran_saran' => ['nullable', 'string', 'max:5000'],
                        'status_kelaikan' => ['nullable', Rule::in(['Fit to Work', 'Fit with Note', 'Temporarily Unfit', 'Unfit'])],
                        'dokter_id' => ['nullable', $this->doctorRule($exam->dokter_id)],
                    ]));
                } elseif ($section === 'dinamis' || ($section !== null && str_starts_with($section, 'kategori-'))) {
                    $this->saveDynamic($request, $peserta, $exam, $section, $storedAttachments);
                } else {
                    $this->saveLegacy($request, $peserta, $exam);
                }
                if ($section && $section !== 'identitas') {
                    $states = $exam->section_status ?? [];
                    $states[$section] = $request->input('status') === 'selesai' ? 'selesai' : 'draft';
                    $exam->section_status = $states;
                    if ($request->input('status') === 'selesai') {
                        $missing = (str_starts_with($section, 'kategori-') ? $exam->categoryProgress($peserta) : $exam->progress($peserta))[$section]['missing'];
                        if ($missing !== []) {
                            throw ValidationException::withMessages(['kelengkapan' => 'Bagian belum lengkap: '.implode(', ', $missing)]);
                        }
                    }
                }
                if ($request->input('status') === 'final') {
                    $this->validateFinalization($request, $peserta, $exam);
                    $exam->finalized_at = now();
                    $exam->finalized_by = $request->user()->id;
                    $exam->status = 'final';
                } else {
                    $exam->status = 'draft';
                }
                $exam->lock_version = ($exam->lock_version ?? 0) + 1;
                $exam->save();
                $peserta->update(['status_pemeriksaan' => $exam->status === 'final' ? 'selesai' : ($exam->finalizationErrors($peserta) === [] ? 'siap_finalisasi' : 'proses')]);
            });

        } catch (\Throwable $exception) {
            foreach ($storedAttachments as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return redirect()->route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => $section === 'dinamis' ? 'review' : ($section ?? 'review')])->with('success', 'Hasil pemeriksaan tersimpan.');
    }

    public function finalize(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta): RedirectResponse
    {
        $this->assertParticipant($kunjungan, $peserta);
        $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($request, $kunjungan, $peserta): void {
            $this->lockEditable($kunjungan, $peserta);
            $this->requirePhoto($peserta);
            $exam = $peserta->anamnesa()->first();
            abort_unless($exam && $exam->status !== 'final', 403);
            if ((int) $request->input('lock_version', -1) !== $exam->lock_version) {
                throw ValidationException::withMessages(['pemeriksaan' => 'Data berubah. Muat ulang review sebelum finalisasi.']);
            }
            $this->validateFinalization($request, $peserta, $exam);
            $exam->update(['status' => 'final', 'finalized_at' => now(), 'finalized_by' => $request->user()->id, 'lock_version' => $exam->lock_version + 1]);
            $peserta->update(['status_pemeriksaan' => 'selesai']);
        });

        return back()->with('success', 'Hasil MCU difinalisasi dan dikunci.');
    }

    public function revise(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta): RedirectResponse
    {
        $this->assertParticipant($kunjungan, $peserta);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        DB::transaction(function () use ($data, $request, $kunjungan, $peserta): void {
            $this->lockEditable($kunjungan, $peserta);
            $exam = $peserta->anamnesa()->first();
            abort_unless($exam && $exam->status === 'final', 403);
            $peserta->load('perusahaan', 'paket');
            $selectedDoctors = array_filter([$exam->dokter_id, $exam->dokter_fisik_id, ...array_column($exam->penunjang ?? [], 'doctor_id'), ...array_values($exam->category_doctors ?? [])]);
            $revisions = $exam->revisions ?? [];
            $revisions[] = [
                'reason' => $data['reason'], 'operator_id' => $request->user()->id, 'operator' => $request->user()->name,
                'at' => now()->toIso8601String(), 'snapshot' => Arr::except($exam->toArray(), ['revisions']),
                'episode' => $peserta->toArray(),
                'doctors' => Dokter::withTrashed()->whereIn('id', $selectedDoctors)->pluck('nama_dokter', 'id')->all(),
            ];
            $exam->update(['revisions' => $revisions, 'status' => 'draft', 'finalized_at' => null, 'finalized_by' => null, 'lock_version' => $exam->lock_version + 1]);
            $peserta->update(['status_pemeriksaan' => 'proses']);
        });

        return back()->with('success', 'Revisi dibuka. Hasil sebelumnya disimpan dalam riwayat revisi.');
    }

    public function attachment(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta, string $item): StreamedResponse
    {
        $this->assertParticipant($kunjungan, $peserta);
        if (str_starts_with($item, 'dynamic-')) {
            $id = substr($item, 8);
            $exam = $peserta->anamnesa;
            abort_unless($exam, 404);
            $schema = $exam->dynamic_schema ?? [];
            $attachments = $exam->dynamic_attachments ?? [];
            if ($request->has('version')) {
                $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
                $revision = ($exam->revisions ?? [])[$data['version'] - 1]['snapshot'] ?? null;
                abort_unless($revision, 404);
                $schema = $revision['dynamic_schema'] ?? [];
                $attachments = $revision['dynamic_attachments'] ?? [];
            }
            $definition = collect($schema)->pluck('items')->flatten(1)->first(fn (array $definition): bool => $definition['id'] === $id);
            abort_unless($definition && ($definition['allow_attachment'] ?? false) && in_array($definition['type'], ['text', 'number', 'laboratory'], true), 404);
            $attachment = $attachments[$id] ?? null;
            $path = $attachment['path'] ?? null;
            abort_unless($path && str_starts_with($path, 'mcu/'.$peserta->id.'/dynamic/') && Storage::disk('local')->exists($path), 404);
            $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
            if (($attachment['mime'] ?? null) === 'application/pdf') {
                return Storage::disk('local')->download($path, 'lampiran-'.$id.'.pdf', $headers + ['Content-Type' => 'application/pdf']);
            }
            abort_unless(in_array($attachment['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

            return Storage::disk('local')->response($path, null, $headers + ['Content-Type' => $attachment['mime']]);
        }
        abort_unless($item === 'foto' || array_key_exists($item, McuCatalog::supporting()), 404);
        $path = $item === 'foto' ? $peserta->foto : ($peserta->anamnesa?->penunjang[$item]['attachment'] ?? null);
        if ($request->has('version')) {
            $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
            $revision = ($peserta->anamnesa?->revisions ?? [])[$data['version'] - 1] ?? null;
            abort_unless($revision, 404);
            $episode = $revision['episode'] ?? [];
            $path = $item === 'foto' ? (array_key_exists('foto', $episode) ? $episode['foto'] : $peserta->foto) : ($revision['snapshot']['penunjang'][$item]['attachment'] ?? null);
        }
        abort_unless($path && str_starts_with($path, 'mcu/'.$peserta->id.'/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function report(RencanaKunjungan $kunjungan, Karyawan $peserta, McuReport $report): View
    {
        $this->assertParticipant($kunjungan, $peserta);
        $peserta->load('anamnesa.dokter', 'anamnesa.dokterFisik', 'perusahaan', 'paket');

        $exam = $peserta->anamnesa ?? new Anamnesa;

        return view('mcu.laporan', ['peserta' => $peserta, 'exam' => $exam, 'sections' => $report->sections($peserta, $exam), 'doctors' => Dokter::withTrashed()->pluck('nama_dokter', 'id')]);
    }

    private function saveHistory(Request $request, Karyawan $participant, Anamnesa $exam): void
    {
        $rules = [];
        foreach (Paket::getDefaultItemCatalog()['anamnesa_riwayat'] as $key => $label) {
            if ($exam->includes($participant, $key)) {
                $definition = collect(McuCatalog::anamnesa())->collapse()->get($key);
                $rules['anamnesa.'.$key.'.answer'] = ['nullable', 'string', 'max:2000'];
                if (($definition['type'] ?? 'choice') === 'choice') {
                    $rules['anamnesa.'.$key.'.answer'][] = Rule::in(['Ya', 'Tidak']);
                }
                $rules['anamnesa.'.$key.'.note'] = ['nullable', 'string', 'max:2000'];
            }
        }
        $data = $request->validate($rules);
        $exam->anamnesa_data = array_replace($exam->anamnesa_data ?? [], $data['anamnesa'] ?? []);
    }

    /** @param list<string> $storedAttachments */
    private function saveDynamic(Request $request, Karyawan $participant, Anamnesa $exam, string $section, array &$storedAttachments): void
    {
        if (str_starts_with($section, 'kategori-')) {
            $groups = $exam->categoryGroups($participant);
            if (! isset($groups[$section])) {
                throw ValidationException::withMessages(['section' => 'Kategori ini tidak termasuk snapshot paket pasien. Muat ulang halaman pemeriksaan.']);
            }
            $groups = [$groups[$section]];
        } else {
            $groups = $exam->dynamicGroupsForSection($participant, $section);
        }
        $definitions = collect($groups)->pluck('items')->flatten(1)->filter(fn (array $item): bool => $item['type'] !== 'label')->keyBy('id');
        $rules = ['dynamic_answers' => $definitions->isEmpty() ? ['sometimes', 'array', 'size:0'] : ['sometimes', 'array:'.implode(',', $definitions->keys()->all())]];
        $doctorCategories = collect($groups)->filter(fn (array $group): bool => $group['has_responsible_doctor'] ?? true)->keyBy('id');
        $rules['category_doctors'] = $doctorCategories->isEmpty() ? ['prohibited'] : ['sometimes', 'array:'.implode(',', $doctorCategories->keys()->all())];
        foreach ($doctorCategories as $id => $group) {
            $rules['category_doctors.'.$id] = ['nullable', 'string', $this->doctorRule(($exam->category_doctors ?? [])[$id] ?? null)];
        }
        $uploadable = $definitions->filter(fn (array $item): bool => ($item['allow_attachment'] ?? false) && in_array($item['type'], ['text', 'number', 'laboratory'], true));
        $rules['dynamic_files'] = $uploadable->isEmpty() ? ['sometimes', 'array', 'size:0'] : ['sometimes', 'array:'.implode(',', $uploadable->keys()->all())];
        foreach ($uploadable as $id => $item) {
            $file = $request->file('dynamic_files.'.$id);
            $rules['dynamic_files.'.$id] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'extensions:jpg,jpeg,png,webp,pdf', 'max:10240'];
            if ($file instanceof UploadedFile && $file->getMimeType() !== 'application/pdf') {
                $rules['dynamic_files.'.$id][] = 'image';
                $rules['dynamic_files.'.$id][] = 'dimensions:min_width=1,min_height=1';
            }
        }
        foreach ($definitions as $id => $item) {
            $rules['dynamic_answers.'.$id] = match ($item['type']) {
                'radio' => ['nullable', Rule::in(['YA', 'TIDAK'])],
                'number' => ['nullable', 'numeric', 'between:-999999999,999999999'],
                'laboratory' => ($item['lab_config']['result_type'] ?? 'text') === 'number' ? ['nullable', 'numeric', 'between:-999999999,999999999'] : ['nullable', 'string', 'max:2000'],
                default => ['nullable', 'string', 'max:2000'],
            };
        }
        $data = $request->validate($rules);
        $exam->dynamic_answers = array_replace($exam->dynamic_answers ?? [], $data['dynamic_answers'] ?? []);
        $exam->category_doctors = array_replace($exam->category_doctors ?? [], $data['category_doctors'] ?? []);
        $attachments = $exam->dynamic_attachments ?? [];
        foreach ($uploadable as $id => $item) {
            if (! $request->hasFile('dynamic_files.'.$id)) {
                continue;
            }
            $file = $request->file('dynamic_files.'.$id);
            $path = $file->store('mcu/'.$participant->id.'/dynamic', 'local');
            if (! $path) {
                throw ValidationException::withMessages(['dynamic_files.'.$id => 'Lampiran gagal disimpan. Silakan coba kembali.']);
            }
            $storedAttachments[] = $path;
            $attachments[$id] = ['path' => $path, 'mime' => $file->getMimeType()];
        }
        $exam->dynamic_attachments = $attachments;
    }

    private function savePhysical(Request $request, Karyawan $participant, Anamnesa $exam): void
    {
        $rules = ['dokter_fisik_id' => ['nullable', $this->doctorRule($exam->dokter_fisik_id)]];
        foreach (McuCatalog::physical() as $items) {
            foreach ($items as $key => $label) {
                if (! $exam->includes($participant, $key) || $key === 'bmi') {
                    continue;
                }
                foreach (McuCatalog::physicalFields()[$key] ?? [$key] as $field) {
                    $rules[$field] = match ($field) {
                        'tinggi_badan', 'berat_badan', 'lingkar_pinggang' => ['nullable', 'numeric', 'gt:0', 'max:999'],
                        'suhu_badan' => ['nullable', 'numeric', 'gt:0', 'max:99'],
                        'nadi', 'pernapasan' => ['nullable', 'integer', 'min:1', 'max:300'],
                        'tekanan_darah' => ['nullable', 'regex:/^\d{2,3}\/\d{2,3}$/'],
                        default => ['nullable', 'string', 'max:255'],
                    };
                }
            }
        }
        $exam->fill($request->validate($rules));
        if ($exam->includes($participant, 'bmi')) {
            $calculation = Anamnesa::calculateBmi($exam->tinggi_badan === null ? null : (float) $exam->tinggi_badan, $exam->berat_badan === null ? null : (float) $exam->berat_badan);
            $exam->fill(['bmi' => $calculation['bmi'], 'kategori_bmi' => $calculation['kategori']]);
        }
    }

    /** @param list<string> $storedAttachments */
    private function saveLaboratory(Request $request, Karyawan $participant, Anamnesa $exam, array &$storedAttachments): void
    {
        $this->saveDynamic($request, $participant, $exam, 'laboratorium', $storedAttachments);
        $rules = [];
        foreach (McuCatalog::laboratory() as $items) {
            foreach ($items as $key => $item) {
                if (! $exam->includes($participant, $key)) {
                    continue;
                }
                foreach (['result', 'unit', 'reference', 'reason'] as $field) {
                    $rules['laboratorium.'.$key.'.'.$field] = ['nullable', 'string', 'max:500'];
                }
                $rules['laboratorium.'.$key.'.flag'] = ['nullable', Rule::in(['auto', 'Normal', 'Tinggi', 'Rendah', 'Tidak Normal'])];
                $rules['laboratorium.'.$key.'.skipped'] = ['nullable', 'boolean'];
            }
        }
        $data = $request->validate($rules)['laboratorium'] ?? [];
        foreach ($data as &$row) {
            $row['flag_mode'] = $row['flag'] ?? 'auto';
            $row['skipped'] = (bool) ($row['skipped'] ?? false);
            if ($row['skipped']) {
                $row['result'] = $row['flag'] = null;
            } elseif (($row['flag'] ?? 'auto') === 'auto') {
                $row['flag'] = McuCatalog::laboratoryFlag($row['result'] ?? null, $row['reference'] ?? null);
            }
        }
        $exam->laboratorium = array_replace($exam->laboratorium ?? [], $data);
    }

    private function saveSupporting(Request $request, Karyawan $participant, Anamnesa $exam): void
    {
        $rules = [];
        foreach (McuCatalog::supporting() as $key => $item) {
            if (! $exam->includes($participant, $key)) {
                continue;
            }
            foreach (array_keys($item['fields']) as $field) {
                $rules['penunjang.'.$key.'.'.$field] = ['nullable', 'string', 'max:3000'];
            }
            $rules['penunjang.'.$key.'.doctor_id'] = ['nullable', $this->doctorRule($exam->penunjang[$key]['doctor_id'] ?? null)];
            $rules['penunjang.'.$key.'.flag'] = ['nullable', Rule::in(['Normal', 'Tidak Normal'])];
            $rules['penunjang.'.$key.'.skipped'] = ['nullable', 'boolean'];
            $rules['penunjang.'.$key.'.reason'] = ['nullable', 'string', 'max:1000'];
            $rules['images.'.$key] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'];
        }
        $data = $request->validate($rules);
        $rows = $exam->penunjang ?? [];
        foreach ($data['penunjang'] ?? [] as $key => $row) {
            $row['skipped'] = (bool) ($row['skipped'] ?? false);
            $rows[$key] = array_replace($rows[$key] ?? [], $row);
        }
        $exam->penunjang = $rows;
        if ($request->input('status') === 'selesai' && $exam->progress($participant)['penunjang']['missing'] !== []) {
            throw ValidationException::withMessages(['kelengkapan' => 'Penunjang belum lengkap: '.implode(', ', $exam->progress($participant)['penunjang']['missing'])]);
        }
        foreach (array_keys(McuCatalog::supporting()) as $key) {
            if ($exam->includes($participant, $key) && $request->hasFile('images.'.$key)) {
                $rows[$key]['attachment'] = $this->storeImage($request, 'images.'.$key, $participant);
            }
        }
        $exam->penunjang = $rows;
    }

    private function saveLegacy(Request $request, Karyawan $participant, Anamnesa $exam): void
    {
        $this->savePhysical($request, $participant, $exam);
        $rules = [];
        foreach (Paket::getDefaultItemCatalog()['anamnesa_riwayat'] as $key => $label) {
            if ($exam->includes($participant, $key)) {
                $rules['history.'.$key] = ['nullable', 'boolean'];
            }
        }
        $data = $request->validate($rules);
        $exam->riwayat_penyakit_dahulu = array_replace($exam->riwayat_penyakit_dahulu ?? [], $data['history'] ?? []);
    }

    private function validateFinalization(Request $request, Karyawan $participant, Anamnesa $exam): void
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $errors = $exam->finalizationErrors($participant);
        if ($errors !== []) {
            throw ValidationException::withMessages(['kelengkapan' => $errors]);
        }
    }

    private function storeImage(Request $request, string $field, Karyawan $participant): string
    {
        $path = $request->file($field)->store('mcu/'.$participant->id, 'local');
        if (! $path) {
            throw ValidationException::withMessages([$field => 'Gambar gagal disimpan. Silakan coba lagi.']);
        }

        return $path;
    }

    public function savePhoto(Request $request, RencanaKunjungan $kunjungan, Karyawan $peserta, PatientPhoto $photos): RedirectResponse
    {
        $this->assertParticipant($kunjungan, $peserta);
        $request->validate(['foto_pasien' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']], [
            'foto_pasien.required' => 'Pilih atau ambil foto pasien terlebih dahulu.',
            'foto_pasien.uploaded' => 'Foto ditolak oleh server. Pastikan upload_max_filesize PHP minimal 5 MB.',
            'foto_pasien.max' => 'Ukuran foto sumber maksimal 5 MB.',
            'foto_pasien.image' => 'File harus berupa gambar yang valid.',
            'foto_pasien.mimes' => 'Gunakan JPG, PNG, atau WebP.',
        ]);
        $path = null;
        try {
            DB::transaction(function () use ($request, $kunjungan, $peserta, $photos, &$path): void {
                $this->lockEditable($kunjungan, $peserta);
                abort_if($peserta->anamnesa?->status === 'final', 403, 'Foto hasil final hanya dapat diganti setelah revisi dibuka.');
                $path = $photos->store($request->file('foto_pasien'), $peserta);
                $peserta->update(['foto' => $path]);
                if ($peserta->anamnesa) {
                    $peserta->anamnesa->increment('lock_version');
                }
            });
        } catch (\Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        $target = (new ExaminationLabel)->navigationTarget($peserta, $peserta->anamnesa ?? new Anamnesa, $request->input('return_tab'), $request->input('return_subcategory'));

        return redirect()->route('pemeriksaan.edit', [$kunjungan, $peserta, ...($target ?? ['tab' => 'identitas'])])->with('success', $target ? 'Foto tersimpan sebagai WebP. Bagian pemeriksaan dari label dibuka.' : 'Foto tersimpan sebagai WebP. Silakan periksa identitas pasien sebelum melanjutkan pemeriksaan.');
    }

    private function requirePhoto(Karyawan $participant): void
    {
        if (! $participant->hasStoredPhoto()) {
            throw ValidationException::withMessages(['foto_pasien' => 'Foto pasien wajib disimpan sebelum mengisi atau memfinalisasi pemeriksaan.']);
        }
    }

    private function doctorRule(?string $selectedDoctor): Exists
    {
        return Rule::exists('dokters', 'id')->whereNull('deleted_at')->where(function (Builder $query) use ($selectedDoctor): void {
            $query->where(function (Builder $query) use ($selectedDoctor): void {
                $query->where('is_active', true);
                if ($selectedDoctor !== null) {
                    $query->orWhere('id', $selectedDoctor);
                }
            });
        });
    }

    private function lockEditable(RencanaKunjungan $visit, Karyawan $participant): void
    {
        $locked = RencanaKunjungan::whereKey($visit->id)->lockForUpdate()->firstOrFail();
        $participant->refresh();
        abort_if($locked->is_closing || $participant->is_closing || in_array($participant->status_pemeriksaan, ['batal', 'tidak_hadir'], true), 403);
    }

    private function assertParticipant(RencanaKunjungan $kunjungan, Karyawan $peserta): void
    {
        abort_unless($peserta->rencana_kunjungan_id === $kunjungan->id, 404);
        abort_unless($kunjungan->pakets()->whereKey($peserta->paket_id)->exists(), 404);
    }
}
