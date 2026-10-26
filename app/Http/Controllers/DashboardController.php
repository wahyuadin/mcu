<?php

namespace App\Http\Controllers;

use App\DashboardLibrary;
use App\DashboardWorkspace;
use App\Models\DashboardPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function data(Request $request, DashboardWorkspace $workspace, DashboardLibrary $library): JsonResponse
    {
        $company = $workspace->company($request);
        $state = $library->state($request->user());
        $request->validate(['dashboard' => ['nullable', 'string', 'max:100']]);
        $id = $request->input('dashboard') ?? $state['active'];
        $dashboard = $library->find($state, $id);

        return response()->json($workspace->data($request, $company, $dashboard['widgets'] ?? null) + [
            'personal_layout' => $dashboard !== null, 'active_dashboard' => $id, 'dashboard' => $dashboard ? array_diff_key($dashboard, ['widgets' => true, 'company_id' => true]) : null, 'default_dashboard' => $state['default_dashboard'],
            'dashboards' => array_map(fn (array $entry): array => array_diff_key($entry, ['widgets' => true, 'company_id' => true]), $state['dashboards']),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function preview(Request $request, DashboardWorkspace $workspace): JsonResponse
    {
        $company = $workspace->company($request);
        $widgets = $workspace->validatedWidgets($request, $company);

        return response()->json($workspace->data($request, $company, $request->boolean('use_default') ? null : $widgets))->header('Cache-Control', 'no-store, private');
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
