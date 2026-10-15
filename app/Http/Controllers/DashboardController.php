<?php

namespace App\Http\Controllers;

use App\Models\DashboardPreference;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        $preference = DashboardPreference::where('user_id', $request->user()->id)->where('role', $request->user()->role)->first();

        return $this->response($request, $preference?->widgets, $preference?->widgets !== null);
    }

    public function preview(Request $request): JsonResponse
    {
        $widgets = $this->validatedWidgets($request);

        return $this->response($request, $request->boolean('use_default') ? null : $widgets, false);
    }

    public function preferences(Request $request): JsonResponse
    {
        $widgets = $this->validatedWidgets($request);
        DashboardPreference::withTrashed()->updateOrCreate(
            ['user_id' => $request->user()->id, 'role' => $request->user()->role],
            ['widgets' => $request->boolean('use_default') ? null : $widgets, 'deleted_at' => null],
        );

        return response()->json(['message' => 'Dashboard pribadi disimpan.'])->header('Cache-Control', 'no-store, private');
    }

    /** @return list<array<string, mixed>> */
    private function sources(): array
    {
        $sources = [];
        foreach (['perusahaan' => 'Perusahaan', 'kunjungan_aktif' => 'Kunjungan Aktif', 'peserta' => 'Total Peserta', 'selesai' => 'Pemeriksaan Selesai', 'belum' => 'Belum Mulai', 'proses' => 'Dalam Pemeriksaan', 'siap_finalisasi' => 'Siap Finalisasi'] as $id => $title) {
            $sources[] = ['id' => $id, 'title' => $title, 'kind' => 'kpi', 'group' => 'Operasional MCU'];
        }
        $sources[] = ['id' => 'kunjungan_terbaru', 'title' => 'Kunjungan MCU Terbaru', 'kind' => 'operation_table', 'group' => 'Operasional MCU'];

        return $sources;
    }

    /** @return list<array<string, mixed>> */
    private function defaultLayout(): array
    {
        return array_map(fn (array $source): array => ['source' => $source['id'], 'title' => $source['title'], 'chart' => $source['kind'] === 'kpi' ? 'number' : 'table',
            'metric' => 'count', 'dimension' => '', 'filter_source' => '', 'filter_value' => '', 'width' => $source['kind'] === 'kpi' ? 1 : 3, 'height' => 1],
            array_values(array_filter($this->sources(), fn (array $source): bool => in_array($source['id'], ['perusahaan', 'kunjungan_aktif', 'peserta', 'selesai', 'kunjungan_terbaru'], true))));
    }

    /** @param list<array<string, mixed>>|null $layout */
    private function response(Request $request, ?array $layout, bool $personal): JsonResponse
    {
        $request->validate(['company' => ['prohibited']]);
        $counts = Karyawan::selectRaw('status_pemeriksaan, COUNT(*) AS total')->groupBy('status_pemeriksaan')->pluck('total', 'status_pemeriksaan');
        $stats = ['perusahaan' => Perusahaan::count(), 'kunjungan_aktif' => RencanaKunjungan::where('is_closing', false)->count(), 'peserta' => (int) $counts->sum(),
            'selesai' => (int) ($counts['selesai'] ?? 0), 'belum' => (int) ($counts['belum'] ?? 0), 'proses' => (int) ($counts['proses'] ?? 0), 'siap_finalisasi' => (int) ($counts['siap_finalisasi'] ?? 0)];
        $visits = RencanaKunjungan::with('perusahaan')->withCount('karyawans')->latest()->limit(5)->get()->map(fn (RencanaKunjungan $visit): array => [
            'number' => $visit->nomor_kunjungan, 'company' => $visit->perusahaan->nama_perusahaan, 'period' => $visit->periode_kunjungan, 'participants' => $visit->karyawans_count, 'quota' => $visit->kuota_peserta,
            'url' => $request->user()->can('manage-mcu') ? route('rencana-kunjungan.show', $visit) : route('kunjungan-mcu.show', $visit),
            'action_label' => $request->user()->can('manage-mcu') ? 'Kelola' : 'Buka',
        ])->all();
        $configuration = $layout ?? $this->defaultLayout();
        $sources = collect($this->sources())->keyBy('id');
        $widgets = array_map(fn (array $config): array => [...$config, 'kind' => $sources[$config['source']]['kind'], 'group' => 'Operasional MCU',
            'total' => $stats[$config['source']] ?? count($visits), 'rows' => $config['source'] === 'kunjungan_terbaru' ? $visits : []], $configuration);

        return response()->json(['layout' => $configuration, 'widgets' => $widgets, 'sources' => $this->sources(), 'stats' => $stats, 'recent_visits' => $visits,
            'personal_layout' => $personal, 'updated_at' => now()->toIso8601String()])->header('Cache-Control', 'no-store, private');
    }

    /** @return list<array<string, mixed>> */
    private function validatedWidgets(Request $request): array
    {
        $data = $request->validate([
            'user_id' => ['prohibited'], 'role' => ['prohibited'], 'company' => ['prohibited'], 'use_default' => ['sometimes', 'boolean'],
            'widgets' => ['present', 'array', 'list', 'max:200'],
            'widgets.*' => ['array:source,title,chart,metric,dimension,filter_source,filter_value,width,height'],
            'widgets.*.source' => ['required', Rule::in(array_column($this->sources(), 'id'))], 'widgets.*.title' => ['required', 'string', 'max:255'],
            'widgets.*.chart' => ['required', Rule::in(['number', 'table'])], 'widgets.*.metric' => ['required', Rule::in(['count'])],
            'widgets.*.dimension' => ['prohibited'], 'widgets.*.filter_source' => ['prohibited'], 'widgets.*.filter_value' => ['prohibited'],
            'widgets.*.width' => ['required', 'integer', 'between:1,3'], 'widgets.*.height' => ['required', 'integer', 'between:1,2'],
        ]);
        foreach ($data['widgets'] as $index => $widget) {
            if ($widget['chart'] !== ($widget['source'] === 'kunjungan_terbaru' ? 'table' : 'number')) {
                throw ValidationException::withMessages(['widgets.'.$index.'.chart' => 'Visualisasi tidak sesuai dengan sumber operasional yang dipilih.']);
            }
        }

        return $data['widgets'];
    }
}
