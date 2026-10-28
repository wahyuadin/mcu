<?php

namespace App\Http\Controllers;

use App\Models\PatientImportBatch;
use App\PatientImport;
use App\PatientSpreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientImportController extends Controller
{
    public function template(PatientSpreadsheet $spreadsheets): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheets): void {
            $book = $spreadsheets->template();
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'template-import-pasien.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private']);
    }

    public function store(Request $request, PatientSpreadsheet $spreadsheets, PatientImport $imports): JsonResponse
    {
        $source = ['headers' => [], 'rows' => []];
        $error = null;
        try {
            $request->validate([
                'import_file' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120'],
            ]);
            $source = $spreadsheets->read($request->file('import_file'));
        } catch (ValidationException $exception) {
            $error = implode(' ', array_merge(...array_values($exception->errors())));
        }
        $mapping = [];
        foreach (PatientSpreadsheet::HEADERS as $field) {
            $mapping[$field] = in_array($field, $source['headers'], true) ? $field : null;
        }
        $batch = PatientImportBatch::create([
            'user_id' => $request->user()->id, 'file_name' => mb_substr($request->file('import_file')?->getClientOriginalName() ?? 'Upload tidak tersedia', 0, 255),
            'headers' => $source['headers'], 'source_rows' => $source['rows'], 'mapping' => $mapping,
            'status' => $error ? 'complete' : 'preview', 'file_error' => $error,
            'results' => $error ? [['number' => 0, 'data' => array_fill_keys(PatientSpreadsheet::HEADERS, null), 'status' => 'failed', 'errors' => [$error], 'nomor_mcu' => null, 'participant_id' => null, 'visit' => null, 'package' => null]] : null,
            'expires_at' => now()->addDay(),
        ]);

        return response()->json($this->payload($batch, $imports), $error ? 422 : 200)->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, PatientImportBatch $batch, PatientImport $imports): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        return response()->json($this->payload($batch, $imports))->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, PatientImportBatch $batch, PatientImport $imports): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $rules = ['version' => ['required', 'integer', 'min:0'], 'mapping' => ['required', 'array:'.implode(',', PatientSpreadsheet::HEADERS)]];
        foreach (PatientSpreadsheet::HEADERS as $field) {
            $rules['mapping.'.$field] = [in_array($field, PatientSpreadsheet::REQUIRED_HEADERS, true) ? 'required' : 'nullable', Rule::in($batch->headers)];
        }
        $data = $request->validate($rules);
        $chosen = array_filter($data['mapping'], fn ($header): bool => $header !== null);
        if (count($chosen) !== count(array_unique($chosen))) {
            throw ValidationException::withMessages(['mapping' => 'Satu kolom sumber hanya boleh digunakan untuk satu field.']);
        }
        $batch = DB::transaction(function () use ($batch, $data): PatientImportBatch {
            $locked = PatientImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status === 'complete', 409, 'Import sudah selesai. Upload file baru untuk perbaikan.');
            if ($locked->version !== $data['version']) {
                throw ValidationException::withMessages(['version' => 'Preview berubah. Muat ulang sebelum mengubah mapping.']);
            }
            $locked->update(['mapping' => $data['mapping'], 'version' => $locked->version + 1]);

            return $locked;
        });

        return response()->json($this->payload($batch, $imports))->header('Cache-Control', 'no-store, private');
    }

    public function confirm(Request $request, PatientImportBatch $batch, PatientImport $imports): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        $batch = $imports->confirm($batch, $data['version']);

        return response()->json($this->payload($batch, $imports))->header('Cache-Control', 'no-store, private');
    }

    public function report(Request $request, PatientImportBatch $batch, PatientSpreadsheet $spreadsheets, PatientImport $imports): StreamedResponse
    {
        $this->authorizeBatch($request, $batch);
        $rows = $imports->preview($batch);
        abort_unless($batch->status === 'complete' || in_array('failed', array_column($rows, 'status'), true), 409, 'Laporan tersedia setelah crosscheck bermasalah atau import selesai.');

        return response()->streamDownload(function () use ($batch, $spreadsheets, $rows): void {
            $book = new Spreadsheet;
            $failed = $successful = $retry = $excluded = [];
            $sourceRows = $batch->source_rows;
            foreach ($rows as $row) {
                $values = array_map(fn (string $field) => $row['data'][$field] ?? null, PatientSpreadsheet::HEADERS);
                if ($row['status'] === 'failed') {
                    $original = array_map(fn (array $cell) => $cell['value'], $sourceRows[$row['number']] ?? []);
                    $failed[] = [$row['number'], ...array_pad($original, count($batch->headers), null), implode(' ', $row['errors'])];
                } elseif ($row['status'] === 'excluded') {
                    $excluded[] = [$row['number'], ...$values, implode(' ', $row['errors'])];
                } elseif (in_array($row['status'], ['succeeded', 'existing'], true)) {
                    $successful[] = [$row['number'], ...$values, $row['nomor_mcu'], $row['status'] === 'existing' ? 'Sudah diimport sebelumnya' : 'Berhasil'];
                }
                if ($row['number'] > 0 && in_array($row['status'], ['ready', 'failed', 'excluded'], true)) {
                    $retry[] = $values;
                }
            }
            $spreadsheets->writeSheet($book->getActiveSheet()->setTitle('Pasien'), PatientSpreadsheet::HEADERS, $retry);
            $spreadsheets->writeSheet($book->createSheet()->setTitle('Berhasil'), ['baris', ...PatientSpreadsheet::HEADERS, 'nomor_mcu', 'status'], $successful);
            $spreadsheets->writeSheet($book->createSheet()->setTitle('Gagal'), ['baris', ...$batch->headers, 'kesalahan'], $failed);
            $spreadsheets->writeSheet($book->createSheet()->setTitle('Tidak diikutkan'), ['baris', ...PatientSpreadsheet::HEADERS, 'alasan'], $excluded);
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'hasil-import-pasien-'.$batch->id.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private']);
    }

    private function authorizeBatch(Request $request, PatientImportBatch $batch): void
    {
        abort_unless($batch->user_id === $request->user()->id, 404);
        abort_if($batch->expires_at->isPast(), 410, 'Preview dan laporan sudah kedaluwarsa. Upload ulang file.');
    }

    /** @return array<string, mixed> */
    private function payload(PatientImportBatch $batch, PatientImport $imports): array
    {
        $rows = $imports->preview($batch);
        foreach ($rows as &$row) {
            unset($row['fingerprint']);
            $row['data'] = array_intersect_key($row['data'], array_flip(PatientSpreadsheet::HEADERS));
        }
        $counts = array_count_values(array_column($rows, 'status'));

        return ['id' => $batch->id, 'file_name' => $batch->file_name, 'status' => $batch->status, 'version' => $batch->version,
            'headers' => $batch->headers, 'mapping' => array_replace(array_fill_keys(PatientSpreadsheet::HEADERS, null), array_intersect_key($batch->mapping ?? [], array_flip(PatientSpreadsheet::HEADERS))), 'required_fields' => PatientSpreadsheet::REQUIRED_HEADERS, 'rows' => $rows, 'file_error' => $batch->file_error,
            'can_confirm' => $batch->status !== 'complete' && ($counts['failed'] ?? 0) === 0 && ($counts['ready'] ?? 0) > 0,
            'stats' => ['total' => count($rows), 'ready' => $counts['ready'] ?? 0, 'failed' => $counts['failed'] ?? 0, 'excluded' => $counts['excluded'] ?? 0, 'succeeded' => $counts['succeeded'] ?? 0, 'existing' => $counts['existing'] ?? 0],
            'show_url' => route('pasien.import.show', $batch), 'mapping_url' => route('pasien.import.update', $batch),
            'confirm_url' => route('pasien.import.confirm', $batch), 'report_url' => $batch->status === 'complete' || ($counts['failed'] ?? 0) > 0 ? route('pasien.import.report', $batch) : null];
    }
}
