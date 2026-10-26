<?php

namespace App\Http\Controllers;

use App\CategoryResults;
use App\Models\CategoryResultImport;
use App\Models\McuCategory;
use App\Models\RencanaKunjungan;
use App\PatientSpreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CategoryResultImportController extends Controller
{
    public function categories(RencanaKunjungan $kunjungan, CategoryResults $imports): JsonResponse
    {
        abort_if($kunjungan->is_closing || $kunjungan->status === 'completed', 403, 'Kunjungan sudah selesai atau terkunci.');
        $participants = $imports->participants($kunjungan);
        $categories = McuCategory::where('is_active', true)->where('import_enabled', true)->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (McuCategory $category): bool => $participants->contains(fn ($participant): bool => $imports->definitions($participant, $category) !== []));

        return response()->json(['categories' => $categories->map(fn (McuCategory $category): array => [
            'id' => $category->id, 'name' => $category->name, 'template_url' => route('category-import.template', [$kunjungan, $category]), 'upload_url' => route('category-import.store', [$kunjungan, $category]),
        ])->values()])->header('Cache-Control', 'no-store, private');
    }

    public function template(RencanaKunjungan $kunjungan, McuCategory $category, CategoryResults $imports): StreamedResponse
    {
        $imports->assertAvailable($kunjungan, $category);

        return $this->download($imports->template($kunjungan, $category), 'template-hasil-'.Str::slug($category->name).'.xlsx');
    }

    public function store(Request $request, RencanaKunjungan $kunjungan, McuCategory $category, CategoryResults $imports, PatientSpreadsheet $spreadsheets): JsonResponse
    {
        $imports->assertAvailable($kunjungan, $category);
        $request->validate(['import_file' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120']]);
        $source = $spreadsheets->read($request->file('import_file'), 'Hasil', 512, 5000, 3);
        $preview = $imports->preview($kunjungan, $category, $source);
        $batch = CategoryResultImport::create([
            'user_id' => $request->user()->id, 'rencana_kunjungan_id' => $kunjungan->id, 'mcu_category_id' => $category->id,
            'file_name' => mb_substr($request->file('import_file')->getClientOriginalName(), 0, 255), 'source' => $source,
            'digest' => $preview['digest'], 'status' => 'preview', 'expires_at' => now()->addHour(),
        ]);

        return $this->payload($batch, $preview['rows']);
    }

    public function confirm(Request $request, RencanaKunjungan $kunjungan, McuCategory $category, CategoryResultImport $batch, CategoryResults $imports): JsonResponse
    {
        $this->authorizeBatch($request, $kunjungan, $category, $batch);
        $data = $request->validate(['digest' => ['required', 'string', 'size:64'], 'confirmed' => ['accepted']]);
        $batch = $imports->confirm($batch, $data['digest']);

        return $this->payload($batch, $batch->results);
    }

    public function report(Request $request, RencanaKunjungan $kunjungan, McuCategory $category, CategoryResultImport $batch, CategoryResults $imports, PatientSpreadsheet $spreadsheets): StreamedResponse
    {
        $this->authorizeBatch($request, $kunjungan, $category, $batch);
        $imports->assertAvailable($kunjungan, $category);
        $rows = $batch->status === 'complete' ? $batch->results : $imports->preview($kunjungan, $category, $batch->source)['rows'];
        $book = new Spreadsheet;
        $spreadsheets->writeSheet($book->getActiveSheet()->setTitle('Hasil import'), ['Baris', 'Nomor MCU', 'Nama', 'Status', 'Data lama dipertahankan', 'Kesalahan'],
            array_map(fn (array $row): array => [$row['number'], $row['nomor_mcu'], $row['name'], $row['status'], $row['retained'], implode(' ', $row['errors'])], $rows));

        return $this->download($book, 'laporan-import-hasil-'.$batch->id.'.xlsx');
    }

    private function authorizeBatch(Request $request, RencanaKunjungan $visit, McuCategory $category, CategoryResultImport $batch): void
    {
        abort_unless($batch->user_id === $request->user()->id && $batch->rencana_kunjungan_id === $visit->id && $batch->mcu_category_id === $category->id, 404);
        abort_if($batch->expires_at->isPast(), 410, 'Preview kedaluwarsa. Upload ulang file.');
    }

    /** @param list<array<string, mixed>> $rows */
    private function payload(CategoryResultImport $batch, array $rows): JsonResponse
    {
        foreach ($rows as &$row) {
            $row['changes'] = array_map(fn (array $update): array => ['label' => $update['item']['label'].($update['note'] ? ' — catatan' : ''), 'value' => $update['value']], $row['updates'] ?? []);
            unset($row['updates']);
        }
        unset($row);
        $counts = array_count_values(array_column($rows, 'status'));
        $parameters = [$batch->rencana_kunjungan_id, $batch->mcu_category_id, $batch];

        return response()->json(['id' => $batch->id, 'file_name' => $batch->file_name, 'status' => $batch->status, 'digest' => $batch->digest, 'rows' => $rows,
            'stats' => ['total' => count($rows), 'ready' => $counts['ready'] ?? 0, 'failed' => $counts['failed'] ?? 0, 'skipped' => $counts['skipped'] ?? 0, 'succeeded' => $counts['succeeded'] ?? 0],
            'confirm_url' => route('category-import.confirm', $parameters), 'report_url' => route('category-import.report', $parameters),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function download(Spreadsheet $book, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($book): void {
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private']);
    }
}
