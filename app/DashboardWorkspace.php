<?php

namespace App;

use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardWorkspace
{
    public function __construct(private HrdAnalytics $analytics) {}

    public function company(Request $request, bool $allowLegacyReadOnly = false): ?Perusahaan
    {
        if ($allowLegacyReadOnly && $request->user()->role === 'dokter' && ! $request->filled('company')) {
            return null;
        }
        abort_unless($request->user()->can('view-patients') || $request->user()->can('view-hrd'), 403);
        $data = $request->validate(['company' => ['nullable', 'uuid']]);
        if ($request->user()->role === 'hrd') {
            Gate::authorize('view-hrd');
            abort_if(isset($data['company']) && $data['company'] !== $request->user()->perusahaan_id, 404);

            return $request->user()->perusahaan()->firstOrFail();
        }
        if (isset($data['company'])) {
            Gate::authorize('view-hrd');

            return Perusahaan::findOrFail($data['company']);
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function operationalSources(): array
    {
        $sources = [];
        foreach (['perusahaan' => 'Perusahaan', 'kunjungan_aktif' => 'Kunjungan Aktif', 'peserta' => 'Total Peserta', 'selesai' => 'Pemeriksaan Selesai', 'belum' => 'Belum Mulai', 'proses' => 'Dalam Pemeriksaan', 'siap_finalisasi' => 'Siap Finalisasi'] as $id => $title) {
            $sources[] = ['id' => $id, 'title' => $title, 'kind' => 'kpi', 'group' => 'Operasional MCU', 'medical' => false];
        }
        $sources[] = ['id' => 'kunjungan_terbaru', 'title' => 'Kunjungan MCU Terbaru', 'kind' => 'operation_table', 'group' => 'Operasional MCU', 'medical' => false];

        return $sources;
    }

    /** @return list<array<string, mixed>> */
    public function defaultLayout(): array
    {
        return array_map(fn (array $source): array => ['source' => $source['id'], 'title' => $source['title'], 'chart' => $source['kind'] === 'kpi' ? 'number' : 'table',
            'metric' => 'count', 'dimension' => '', 'filter_source' => '', 'filter_value' => '', 'width' => $source['kind'] === 'kpi' ? 1 : 3, 'height' => 1],
            array_values(array_filter($this->operationalSources(), fn (array $source): bool => in_array($source['id'], ['perusahaan', 'kunjungan_aktif', 'peserta', 'selesai', 'kunjungan_terbaru'], true))));
    }

    /** @return array<string, mixed> */
    public function operationalData(Request $request, ?Perusahaan $company): array
    {
        $filters = $this->filters($request, $company);
        $visitsQuery = $this->visits($company, $filters);
        $counts = Karyawan::query()->when($company, fn ($query) => $query->where('perusahaan_id', $company->id))
            ->whereIn('rencana_kunjungan_id', (clone $visitsQuery)->select('id'))
            ->selectRaw('status_pemeriksaan, COUNT(*) AS total')->groupBy('status_pemeriksaan')->pluck('total', 'status_pemeriksaan');
        $stats = ['perusahaan' => ! empty($filters['visit']) || ! empty($filters['visit_status']) ? (clone $visitsQuery)->distinct()->count('perusahaan_id') : ($company ? 1 : Perusahaan::count()),
            'kunjungan_aktif' => (clone $visitsQuery)->where('is_closing', false)->count(),
            'peserta' => (int) $counts->sum(), 'selesai' => (int) ($counts['selesai'] ?? 0), 'belum' => (int) ($counts['belum'] ?? 0),
            'proses' => (int) ($counts['proses'] ?? 0), 'siap_finalisasi' => (int) ($counts['siap_finalisasi'] ?? 0)];
        $visits = $visitsQuery->with('perusahaan:id,nama_perusahaan')->withCount('karyawans')->latest()->orderByDesc('id')->limit(5)->get()->map(fn (RencanaKunjungan $visit): array => [
            'number' => $visit->nomor_kunjungan, 'company' => $visit->perusahaan->nama_perusahaan, 'period' => $visit->periode_kunjungan, 'participants' => $visit->karyawans_count, 'quota' => $visit->kuota_peserta,
            'url' => $request->user()->role === 'hrd' ? '#hrd-participants' : ($request->user()->can('manage-mcu') ? route('rencana-kunjungan.show', $visit) : route('kunjungan-mcu.show', $visit)),
            'action_label' => $request->user()->role === 'hrd' ? 'Laporan' : ($request->user()->can('manage-mcu') ? 'Kelola' : 'Buka'),
        ])->all();

        return ['stats' => $stats, 'recent_visits' => $visits];
    }

    /** @return array<string, mixed> */
    private function dataset(?Perusahaan $company, array $filters = []): array
    {
        return Cache::remember('dashboard-dataset:v2:'.($company?->id ?? 'all').':'.hash('sha256', json_encode($filters)), 3, fn (): array => $this->analytics->dataset($company, $filters));
    }

    /** @return array<string, mixed> */
    public function filters(Request $request, ?Perusahaan $company): array
    {
        $filters = $request->validate(['visit' => ['nullable', 'uuid'], 'visit_status' => ['nullable', Rule::in(['draft', 'ongoing', 'completed', 'closed'])]]);
        if (! empty($filters['visit'])) {
            abort_unless($this->visits($company, [])->whereKey($filters['visit'])->exists(), 404);
        }

        return $filters;
    }

    /** @param array<string, mixed> $filters */
    private function visits(?Perusahaan $company, array $filters): Builder
    {
        return RencanaKunjungan::query()->when($company, fn (Builder $query): Builder => $query->where('perusahaan_id', $company->id))
            ->dashboardStatus($filters['visit_status'] ?? null)->when($filters['visit'] ?? null, fn (Builder $query, string $id): Builder => $query->whereKey($id));
    }

    /** @return list<array<string, mixed>> */
    public function visitOptions(?Perusahaan $company): array
    {
        return $this->visits($company, [])->latest()->orderByDesc('id')->get(['id', 'perusahaan_id', 'nomor_kunjungan', 'periode_kunjungan', 'status', 'is_closing'])
            ->map(fn (RencanaKunjungan $visit): array => ['id' => $visit->id, 'company_id' => $visit->perusahaan_id, 'number' => $visit->nomor_kunjungan,
                'period' => $visit->periode_kunjungan, 'status' => $visit->is_closing ? 'closed' : $visit->status])->all();
    }

    /** @return array<string, array<string, mixed>> */
    public function sources(Request $request, ?Perusahaan $company): array
    {
        $sources = collect($this->operationalSources())->keyBy('id')->all();
        if ($request->user()->can('view-hrd')) {
            $scope = $request->user()->role === 'admin' ? null : $company;
            $medical = Cache::remember('dashboard-sources:v1:'.($scope?->id ?? 'all'), 30, fn (): array => $this->dataset($scope)['sources']);
            $sources += $request->user()->role === 'admin' ? $this->analytics->builderSources($medical) : $medical;
        }

        return $sources;
    }

    /** @param list<array<string, mixed>>|null $layout
     * @return array<string, mixed>
     */
    public function data(Request $request, ?Perusahaan $company, ?array $layout): array
    {
        $operation = $this->operationalData($request, $company);
        $configuration = $layout ?? $this->defaultLayout();
        $operationalSources = collect($this->operationalSources())->keyBy('id');
        $analytical = array_values(array_filter($configuration, fn (array $widget): bool => ! isset($operationalSources[$widget['source']])));
        abort_if($analytical !== [] && ! $request->user()->can('view-hrd'), 403);
        $scopeFilters = $this->filters($request, $company);
        $filters = $request->validate(['cross_source' => ['nullable', 'string', 'max:160'], 'cross_value' => ['nullable', 'string', 'max:3000']]);
        $analytics = $analytical !== [] ? $this->analytics->summarize($this->dataset($company, $scopeFilters), $filters + $scopeFilters, $analytical)['widgets'] : [];
        $widgets = [];
        foreach ($configuration as $config) {
            if (isset($operationalSources[$config['source']])) {
                $widgets[] = [...$config, 'kind' => $operationalSources[$config['source']]['kind'], 'group' => 'Operasional MCU',
                    'total' => $operation['stats'][$config['source']] ?? count($operation['recent_visits']), 'rows' => $config['source'] === 'kunjungan_terbaru' ? $operation['recent_visits'] : []];
            } else {
                $widgets[] = array_shift($analytics);
            }
        }

        return $operation + ['layout' => $configuration, 'widgets' => $widgets, 'sources' => array_values($this->sources($request, $company)), 'visit_options' => $this->visitOptions($company), 'filters' => $scopeFilters,
            'company' => $company ? ['id' => $company->id, 'name' => $company->nama_perusahaan] : null, 'updated_at' => now()->toIso8601String()];
    }

    /** @return list<array<string, mixed>> */
    public function validatedWidgets(Request $request, ?Perusahaan $company): array
    {
        $sources = $this->sources($request, $company);
        $dimensions = array_keys(array_filter($sources, fn (array $source): bool => in_array($source['kind'], ['choice', 'laboratory'], true)));
        $filterSources = array_keys(array_filter($sources, fn (array $source): bool => ! in_array($source['kind'], ['kpi', 'ranking', 'operation_table'], true)));
        $data = $request->validate([
            'user_id' => ['prohibited'], 'role' => ['prohibited'], 'perusahaan_id' => ['prohibited'], 'use_default' => ['sometimes', 'boolean'],
            'widgets' => ['present', 'array', 'list', 'max:200'], 'widgets.*' => ['array:source,title,chart,metric,dimension,filter_source,filter_value,width,height'],
            'widgets.*.source' => ['required', 'string', Rule::in(array_keys($sources))], 'widgets.*.title' => ['required', 'string', 'max:255'],
            'widgets.*.chart' => ['required', Rule::in(['number', 'table', 'bar', 'stacked', 'pie', 'donut', 'indicator'])],
            'widgets.*.metric' => ['required', Rule::in(['count', 'percent', 'average', 'min', 'max'])],
            'widgets.*.dimension' => ['nullable', 'string', Rule::in($dimensions)], 'widgets.*.filter_source' => ['nullable', 'string', Rule::in($filterSources)],
            'widgets.*.filter_value' => ['nullable', 'string', 'max:3000'], 'widgets.*.width' => ['required', 'integer', 'between:1,3'], 'widgets.*.height' => ['required', 'integer', 'between:1,2'],
        ]);
        foreach ($data['widgets'] as $index => $widget) {
            $kind = $sources[$widget['source']]['kind'];
            $fixed = in_array($kind, ['kpi', 'operation_table'], true);
            if (($fixed && ($widget['chart'] !== ($kind === 'kpi' ? 'number' : 'table') || $widget['metric'] !== 'count' || ! empty($widget['dimension']) || ! empty($widget['filter_source'])))
                || ($widget['chart'] === 'indicator' && $kind !== 'laboratory')
                || (in_array($widget['metric'], ['average', 'min', 'max'], true) && ($kind !== 'number' || in_array($widget['chart'], ['pie', 'donut', 'stacked'], true)))
                || ($kind === 'ranking' && ! empty($widget['dimension']))) {
                throw ValidationException::withMessages(['widgets.'.$index.'.chart' => 'Visualisasi, statistik, atau pengelompokan tidak sesuai dengan sumber data yang dipilih.']);
            }
        }

        return $data['widgets'];
    }
}
