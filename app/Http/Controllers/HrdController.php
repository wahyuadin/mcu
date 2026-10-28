<?php

namespace App\Http\Controllers;

use App\HrdAnalytics;
use App\Jobs\GenerateMcuExport;
use App\McuReport;
use App\Models\Dokter;
use App\Models\HrdDashboardPreference;
use App\Models\Karyawan;
use App\Models\McuExport;
use App\Models\Perusahaan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrdController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        if ($request->user()->role === 'admin' && Perusahaan::doesntExist()) {
            return redirect()->route('dashboard');
        }
        $company = $this->company($request);

        return redirect()->route('dashboard', $request->user()->role === 'admin' ? ['company' => $company->id] : []);
    }

    public function data(Request $request, HrdAnalytics $analytics): JsonResponse
    {
        $company = $this->company($request);
        $filters = $this->filters($request, $company);
        $preference = HrdDashboardPreference::where('user_id', $request->user()->id)->where('perusahaan_id', $company->id)->first();
        $layout = $preference?->widgets ?? $company->dashboard_layout;
        $key = 'hrd:v3:'.$company->id.':'.hash('sha256', json_encode([$filters, $layout]));
        $result = Cache::remember($key, 3, function () use ($analytics, $company, $filters, $layout): array {
            $dataset = $analytics->dataset($company, $filters);
            if (empty($filters['visit']) && empty($filters['visit_status']) && empty($filters['period']) && empty($filters['package'])) {
                Cache::put('hrd-company-sources:v2:'.$company->id, $dataset['sources'], 3);
            }
            if (! empty($filters['cross_source']) && ! isset($dataset['sources'][$filters['cross_source']])) {
                throw ValidationException::withMessages(['cross_source' => 'Sumber analisis tidak tersedia pada filter ini.']);
            }

            return $analytics->summarize($dataset, $filters, $layout) + ['options' => $company->rencanaKunjungans()->with('pakets')->latest()->get()->map(fn ($visit): array => [
                'id' => $visit->id, 'number' => $visit->nomor_kunjungan, 'period' => $visit->periode_kunjungan,
                'packages' => $visit->pakets->map(fn ($package): array => ['id' => $package->id, 'name' => $package->nama_paket])->all(),
            ])->all()];
        });
        $result['builder_sources'] = array_values($this->builderSources($request, $company, $analytics));
        $result['personal_layout'] = $preference?->widgets !== null;
        $export = McuExport::where('user_id', $request->user()->id)->where('perusahaan_id', $company->id)->where('expires_at', '>', now())->latest()->orderByDesc('id')->first();

        return response()->json($result + ['export' => $export ? $this->exportState($export) : null, 'updated_at' => now()->toIso8601String()])->header('Cache-Control', 'no-store, private');
    }

    public function layout(Request $request, HrdAnalytics $analytics): JsonResponse
    {
        Gate::authorize('manage-mcu');
        $company = $this->company($request);
        $sources = $analytics->builderSources($analytics->dataset($company, [])['sources']);
        $widgets = $this->validatedWidgets($request, $sources);
        $company->update(['dashboard_layout' => $widgets === [] ? null : $widgets]);

        return response()->json(['message' => 'Susunan dashboard disimpan.']);
    }

    public function preview(Request $request, HrdAnalytics $analytics): JsonResponse
    {
        $company = $this->company($request);
        $request->validate(['use_default' => ['sometimes', 'boolean']]);
        $filters = $this->filters($request, $company);
        $dataset = $analytics->dataset($company, $filters);
        $sources = $this->builderSources($request, $company, $analytics);
        $widgets = $this->validatedWidgets($request, $sources);

        return response()->json($analytics->summarize($dataset, $filters, $request->boolean('use_default') ? $company->dashboard_layout : $widgets))->header('Cache-Control', 'no-store, private');
    }

    public function preferences(Request $request, HrdAnalytics $analytics): JsonResponse
    {
        $company = $this->company($request);
        $request->validate(['use_default' => ['sometimes', 'boolean'], 'user_id' => ['prohibited'], 'perusahaan_id' => ['prohibited']]);
        $widgets = $this->validatedWidgets($request, $this->builderSources($request, $company, $analytics));
        HrdDashboardPreference::withTrashed()->updateOrCreate(
            ['user_id' => $request->user()->id, 'perusahaan_id' => $company->id],
            ['widgets' => $request->boolean('use_default') ? null : $widgets, 'deleted_at' => null],
        );

        return response()->json(['message' => 'Dashboard pribadi disimpan. Dashboard rekan tidak berubah.'])->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, array<string, mixed>> */
    private function builderSources(Request $request, Perusahaan $company, HrdAnalytics $analytics): array
    {
        $sources = Cache::remember('hrd-company-sources:v2:'.$company->id, 3, fn (): array => $analytics->dataset($company, [])['sources']);

        return $request->user()->role === 'admin' ? $analytics->builderSources($sources) : $sources;
    }

    /** @param array<string, array<string, mixed>> $sources
     * @return list<array<string, mixed>>
     */
    private function validatedWidgets(Request $request, array $sources): array
    {
        $sourceIds = array_keys($sources);
        $dimensions = array_keys(array_filter($sources, fn (array $source): bool => in_array($source['kind'], ['choice', 'laboratory'], true)));
        $filterSources = array_keys(array_filter($sources, fn (array $source): bool => ! in_array($source['kind'], ['kpi', 'ranking'], true)));
        $data = $request->validate([
            'widgets' => ['present', 'array', 'list', 'max:200'],
            'widgets.*' => ['array:source,title,chart,metric,dimension,filter_source,filter_value,width,height'],
            'widgets.*.source' => ['required', 'string', Rule::in($sourceIds)],
            'widgets.*.dimension' => ['nullable', 'string', Rule::in($dimensions)],
            'widgets.*.filter_source' => ['nullable', 'string', Rule::in($filterSources)],
            'widgets.*.filter_value' => ['nullable', 'string', 'max:3000'],
            'widgets.*.title' => ['required', 'string', 'max:255'],
            'widgets.*.chart' => ['required', Rule::in(['bar', 'stacked', 'pie', 'donut', 'table', 'indicator', 'number'])],
            'widgets.*.metric' => ['required', Rule::in(['count', 'percent', 'average', 'min', 'max'])],
            'widgets.*.width' => ['required', 'integer', 'min:1', 'max:3'],
            'widgets.*.height' => ['required', 'integer', 'min:1', 'max:2'],
        ]);
        foreach ($data['widgets'] as $index => $widget) {
            $source = $sources[$widget['source']];
            if ($source['kind'] === 'kpi' && ($widget['chart'] !== 'number' || $widget['metric'] !== 'count' || ! empty($widget['dimension']) || ! empty($widget['filter_source']))) {
                throw ValidationException::withMessages(['widgets.'.$index.'.chart' => 'KPI menggunakan kartu angka dan jumlah, tanpa pengelompokan atau filter khusus kartu.']);
            }
            if (in_array($widget['metric'], ['average', 'min', 'max'], true) && $source['kind'] !== 'number') {
                throw ValidationException::withMessages(['widgets.'.$index.'.metric' => 'Rata-rata, minimum, dan maksimum hanya tersedia untuk sumber angka.']);
            }
            if ($widget['chart'] === 'indicator' && $source['kind'] !== 'laboratory') {
                throw ValidationException::withMessages(['widgets.'.$index.'.chart' => 'Indikator normal/tidak normal hanya tersedia untuk pemeriksaan dengan aturan atau penanda medis.']);
            }
            if (in_array($widget['chart'], ['pie', 'donut', 'stacked'], true) && in_array($widget['metric'], ['average', 'min', 'max'], true)) {
                throw ValidationException::withMessages(['widgets.'.$index.'.chart' => 'Gunakan bar, tabel, atau kartu angka untuk rata-rata/minimum/maksimum.']);
            }
            if ($source['kind'] === 'ranking' && (! empty($widget['dimension']) || ! in_array($widget['metric'], ['count', 'percent'], true))) {
                throw ValidationException::withMessages(['widgets.'.$index.'.dimension' => 'Peringkat temuan menggunakan jumlah atau persentase per pemeriksaan, tanpa dimensi tambahan.']);
            }
        }

        return $data['widgets'];
    }

    public function export(Request $request, HrdAnalytics $analytics): JsonResponse
    {
        $company = $this->company($request);
        $filters = $this->filters($request, $company);
        $data = $request->validate(['all' => ['required', 'boolean'], 'ids' => ['required_if:all,0', 'array', 'list', 'max:1000'], 'ids.*' => ['required', 'uuid', 'distinct']]);
        $dataset = $analytics->dataset($company, $filters);
        $selected = collect($dataset['records'])->filter(fn (array $row): bool => $analytics->matches($row, $filters) && $row['released'] && ($data['all'] || in_array($row['id'], $data['ids'] ?? [], true)))->pluck('id')->all();
        if ($selected === [] || count($selected) > 1000 || (! $data['all'] && count($selected) !== count($data['ids'] ?? []))) {
            throw ValidationException::withMessages(['ids' => 'Pilih 1–1000 peserta final yang sudah dirilis dari perusahaan dan filter yang sama.']);
        }
        $export = DB::transaction(function () use ($selected, $company, $request): McuExport {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'admin' || ($actor->role === 'hrd' && $actor->perusahaan_id === $company->id), 404);
            if (McuExport::where('user_id', $actor->id)->whereIn('status', ['queued', 'processing'])->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['export' => 'Masih ada ekspor yang diproses. Tunggu hingga selesai.']);
            }
            $participants = Karyawan::released()->where('perusahaan_id', $company->id)->whereIn('id', $selected)->with('anamnesa')->get();
            if ($participants->count() !== count($selected)) {
                throw ValidationException::withMessages(['ids' => 'Status peserta berubah. Muat ulang pilihan.']);
            }
            $export = McuExport::create(['user_id' => $actor->id, 'perusahaan_id' => $company->id, 'status' => 'queued', 'total' => count($selected), 'processed' => 0,
                'manifest' => $participants->map(fn (Karyawan $participant): array => ['id' => $participant->id, 'version' => $participant->anamnesa->lock_version])->all(), 'expires_at' => now()->addDay()]);
            GenerateMcuExport::dispatch($export->id);

            return $export;
        });

        return response()->json($this->exportState($export), 202)->header('Cache-Control', 'no-store, private');
    }

    public function participant(Request $request, string $participant, McuReport $reports): JsonResponse
    {
        $company = $this->company($request);
        $episode = Karyawan::released()->where('perusahaan_id', $company->id)->with('anamnesa', 'paket')->findOrFail($participant);
        $doctors = Dokter::withTrashed()->pluck('nama_dokter', 'id');
        $sections = array_map(fn (array $section): array => [
            'title' => $section['title'], 'kind' => $section['kind'], 'groups' => $section['groups'], 'doctor' => $doctors[$section['doctor_id'] ?? ''] ?? null,
            'attachments' => array_map(fn (array $attachment): array => ['label' => $attachment['label'], 'mime' => $attachment['mime']], $section['attachments']),
        ], $reports->sections($episode, $episode->anamnesa));

        return response()->json(['id' => $episode->id, 'name' => $episode->nama_karyawan, 'number' => $episode->nomor_mcu, 'department' => $episode->bagian,
            'fitness' => $episode->anamnesa->status_kelaikan, 'conclusion' => $episode->anamnesa->catatan_dokter, 'advice' => $episode->anamnesa->anjuran_saran,
            'sections' => $sections])->header('Cache-Control', 'no-store, private');
    }

    public function exportStatus(Request $request, McuExport $export): JsonResponse
    {
        abort_unless($export->accessibleBy($request->user()), 404);

        return response()->json($this->exportState($export))->header('Cache-Control', 'no-store, private');
    }

    public function download(Request $request, McuExport $export): StreamedResponse
    {
        abort_unless($export->accessibleBy($request->user()), 404);
        abort_if($export->expires_at->isPast(), 410);
        abort_unless($export->status === 'ready' && $export->path === 'mcu-exports/'.$export->id.'/reports.zip' && Storage::disk('local')->exists($export->path), 409);
        $participants = Karyawan::released()->where('perusahaan_id', $export->perusahaan_id)->whereIn('id', array_column($export->manifest, 'id'))->with('anamnesa:id,karyawan_id,lock_version')->get()->keyBy('id');
        abort_unless($participants->count() === $export->total, 409);
        foreach ($export->manifest as $entry) {
            abort_unless($participants->get($entry['id'])?->anamnesa->lock_version === $entry['version'], 409);
        }

        return Storage::disk('local')->download($export->path, 'Laporan_MCU_'.$export->id.'.zip', ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function recap(Request $request, HrdAnalytics $analytics): StreamedResponse
    {
        $company = $this->company($request);
        $filters = $this->filters($request, $company);
        $layout = HrdDashboardPreference::where('user_id', $request->user()->id)->where('perusahaan_id', $company->id)->first()?->widgets ?? $company->dashboard_layout;
        $dataset = $analytics->dataset($company, $filters);
        $rows = $analytics->summarize($dataset, $filters, $layout)['widgets'];

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBFsep=;\r\n");
            fputcsv($stream, ['Kategori', 'Pemeriksaan', 'Kelompok', 'Jumlah', 'Persentase', 'Statistik', 'Nilai statistik', 'Satuan', 'Rujukan'], ';', '"', '', "\r\n");
            foreach ($rows as $widget) {
                foreach ($widget['rows'] as $row) {
                    $text = array_map(fn (string $value): string => preg_match('/^[\s\x00-\x1f]*[=+@\-]|^[\t\r\n]/u', $value) ? "'".$value : $value, [$widget['group'], $widget['title'], $row['label'], $widget['unit'] ?? '', $widget['reference'] ?? '']);
                    fputcsv($stream, [$text[0], $text[1], $text[2], $row['count'], $row['percent'], $widget['metric'], $row['value'] ?? '', $text[3], $text[4]], ';', '"', '', "\r\n");
                }
            }
            fclose($stream);
        }, 'Rekap_MCU.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function exportState(McuExport $export): array
    {
        $status = $export->expires_at->isPast() ? 'expired' : $export->status;

        return ['id' => $export->id, 'status' => $status, 'total' => $export->total, 'processed' => $export->processed,
            'status_url' => route('hrd.exports.status', $export), 'download_url' => $status === 'ready' ? route('hrd.exports.download', $export) : null,
            'message' => match ($status) {
                'failed' => 'Ekspor gagal; tidak ada ZIP parsial. Silakan coba kembali.', 'expired' => 'ZIP kedaluwarsa. Buat ekspor baru.', default => ''
            }];
    }

    private function company(Request $request): Perusahaan
    {
        $data = $request->validate(['company' => ['nullable', 'uuid']]);
        if ($request->user()->role === 'hrd') {
            abort_if(isset($data['company']) && $data['company'] !== $request->user()->perusahaan_id, 404);

            return $request->user()->perusahaan()->firstOrFail();
        }

        return isset($data['company']) ? Perusahaan::findOrFail($data['company']) : Perusahaan::orderBy('nama_perusahaan')->firstOrFail();
    }

    private function filters(Request $request, Perusahaan $company): array
    {
        $data = $request->validate(['visit' => ['nullable', 'uuid'], 'visit_status' => ['nullable', Rule::in(['draft', 'ongoing', 'completed', 'closed'])], 'package' => ['nullable', 'uuid'], 'period' => ['nullable', 'string', 'max:255'], 'q' => ['nullable', 'string', 'max:255'],
            'cross_source' => ['nullable', 'string', 'max:160'], 'cross_value' => ['nullable', 'string', 'max:3000'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'released' => ['nullable', 'boolean'], 'workspace' => ['nullable', 'boolean']]);
        if (! empty($data['visit'])) {
            abort_unless($company->rencanaKunjungans()->whereKey($data['visit'])->exists(), 404);
        }
        if (! empty($data['package'])) {
            abort_unless($company->rencanaKunjungans()->when($data['visit'] ?? null, fn ($query, $id) => $query->whereKey($id))->whereHas('pakets', fn ($query) => $query->whereKey($data['package']))->exists(), 404);
        }

        return $data;
    }
}
