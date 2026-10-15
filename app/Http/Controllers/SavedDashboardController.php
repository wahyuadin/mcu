<?php

namespace App\Http\Controllers;

use App\DashboardLibrary;
use App\DashboardWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedDashboardController extends Controller
{
    public function __construct(private DashboardWorkspace $workspace, private DashboardLibrary $library) {}

    public function store(Request $request): JsonResponse
    {
        $company = $this->workspace->company($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $widgets = $this->workspace->validatedWidgets($request, $company);
        $dashboard = $this->library->create($request->user(), $data['name'], $widgets);

        return response()->json(['message' => 'Dashboard baru disimpan.', 'dashboard' => $dashboard], 201)->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $dashboard): JsonResponse
    {
        $company = $this->workspace->company($request);
        $this->library->find($this->library->state($request->user()), $dashboard);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'version' => ['required', 'integer', 'min:1'],
            'user_id' => ['prohibited'], 'role' => ['prohibited'], 'perusahaan_id' => ['prohibited']]);
        $changes = array_intersect_key($data, ['name' => true]);
        if ($request->has('widgets')) {
            $changes['widgets'] = $this->workspace->validatedWidgets($request, $company);
        }
        abort_if($changes === [], 422, 'Tidak ada perubahan dashboard.');
        $result = $this->library->update($request->user(), $dashboard, (int) $data['version'], $changes);

        return response()->json(['message' => 'Dashboard disimpan.', 'dashboard' => $result])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, string $dashboard): JsonResponse
    {
        $this->workspace->company($request);
        $this->library->find($this->library->state($request->user()), $dashboard);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'user_id' => ['prohibited'], 'role' => ['prohibited'], 'perusahaan_id' => ['prohibited']]);
        $this->library->delete($request->user(), $dashboard, (int) $data['version']);

        return response()->json(['message' => 'Dashboard dihapus.'])->header('Cache-Control', 'no-store, private');
    }

    public function select(Request $request): JsonResponse
    {
        $this->workspace->company($request);
        $data = $request->validate(['dashboard' => ['required', 'string', 'max:100'], 'user_id' => ['prohibited'], 'role' => ['prohibited'], 'perusahaan_id' => ['prohibited']]);
        $this->library->select($request->user(), $data['dashboard']);

        return response()->json(['message' => 'Dashboard dipilih.'])->header('Cache-Control', 'no-store, private');
    }
}
