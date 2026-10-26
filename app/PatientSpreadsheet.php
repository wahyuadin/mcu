<?php

namespace App;

use App\Models\Paket;
use App\Models\RencanaKunjungan;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

class PatientSpreadsheet
{
    public const MAX_ROWS = 500;

    public const HEADERS = ['nama_karyawan', 'nik', 'nip', 'jenis_kelamin', 'tanggal_lahir', 'bagian', 'jabatan', 'telepon', 'tanggal_mcu', 'kunjungan_id', 'paket_id', 'tempat_lahir'];

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Pasien');
        $visits = RencanaKunjungan::with('perusahaan')->where('is_closing', false)->orderBy('nomor_kunjungan')->get();
        $packages = Paket::orderBy('nama_paket')->get();
        $this->writeSheet($sheet, self::HEADERS, [[
            'Wahyu Adi Nugroho', (string) random_int(1000000000000000, 9999999999999999), 'NYK-19423', '1', '2005-08-24',
            'Operasional', '', '08'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT), '',
            $visits->isNotEmpty() ? $visits->random()->id : '', $packages->isNotEmpty() ? $packages->random()->id : '', 'Bandung',
        ]]);
        foreach (['B', 'C', 'H', 'J', 'K'] as $column) {
            $sheet->getStyle($column.'2:'.$column.(self::MAX_ROWS + 1))->getNumberFormat()->setFormatCode('@');
        }
        foreach (['E', 'I'] as $column) {
            $sheet->getStyle($column.'2:'.$column.(self::MAX_ROWS + 1))->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }
        $this->writeSheet($book->createSheet()->setTitle('Master Kunjungan MCU'), ['kunjungan_id', 'nomor_kunjungan', 'perusahaan', 'periode', 'tanggal_mulai', 'tanggal_selesai'],
            $visits->map(fn (RencanaKunjungan $visit): array => [$visit->id, $visit->nomor_kunjungan, $visit->perusahaan->nama_perusahaan, $visit->periode_kunjungan, $visit->tgl_mulai?->toDateString(), $visit->tgl_selesai?->toDateString()])->all());
        $packageSheet = $book->createSheet()->setTitle('Master Paket');
        $this->writeSheet($packageSheet, ['paket_id', 'nama_paket', 'deskripsi'],
            $packages->map(fn (Paket $package): array => [$package->id, $package->nama_paket, $package->deskripsi])->all());
        $this->writeSheet($book->createSheet()->setTitle('Jenis Kelamin'), ['jenis_kelamin', 'keterangan'], [
            ['1', 'Laki-laki'],
            ['2', 'Perempuan'],
        ]);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** @return array{headers: list<string>, rows: array<int, list<array{value: string|null, type: string, cached: string|null, error: string|null}>>} */
    public function read(UploadedFile $file, string $sheetName = 'Pasien', int $maxColumns = 20, int $maxRows = self::MAX_ROWS, int $dataStartRow = 2): array
    {
        $this->checkArchive($file);
        $book = null;
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true)->setReadEmptyCells(false)->setIgnoreRowsWithNoCells(true)->setLoadSheetsOnly($sheetName);
            $info = collect($reader->listWorksheetInfo($file->getRealPath()))->firstWhere('worksheetName', $sheetName);
            if (! $info || $info['totalRows'] > $maxRows + $dataStartRow - 1 || $info['totalColumns'] > $maxColumns || $info['totalRows'] * $info['totalColumns'] > 200000) {
                $this->fail('Gunakan sheet '.$sheetName.'. Maksimal '.$maxRows.' baris, '.$maxColumns.' kolom, dan 200.000 sel.');
            }
            $book = $reader->load($file->getRealPath());
            $sheet = $book->getSheetByName($sheetName);
            if (! $sheet) {
                $this->fail('Sheet '.$sheetName.' tidak ditemukan. Unduh dan gunakan template XLSX.');
            }
            $headers = [];
            for ($column = 1; $column <= $info['totalColumns']; $column++) {
                $cell = $sheet->getCell([$column, 1]);
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $this->fail('Judul kolom tidak boleh berupa rumus.');
                }
                $headers[] = trim((string) $cell->getValue());
            }
            if ($headers === [] || in_array('', $headers, true) || count(array_unique($headers)) !== count($headers) || max(array_map('strlen', $headers)) > 100) {
                $this->fail('Judul kolom harus terisi, unik, dan maksimal 100 karakter.');
            }
            $rows = [];
            for ($number = $dataStartRow; $number <= $sheet->getHighestDataRow(); $number++) {
                $row = [];
                foreach ($headers as $index => $field) {
                    $cell = $sheet->getCell([($index + 1), $number]);
                    $value = $cell->getValue();
                    $error = null;
                    if (! is_scalar($value) && $value !== null) {
                        $error = 'Nilai kompleks tidak diperbolehkan.';
                        $value = null;
                    }
                    $cached = $cell->getOldCalculatedValue();
                    if (strlen((string) $value) > 2000) {
                        $error = 'Nilai sel terlalu panjang (maksimal 2000 karakter).';
                    }
                    $value = trim((string) ($value ?? ''));
                    $row[] = ['value' => $value === '' ? null : mb_substr($value, 0, 2000), 'type' => $cell->getDataType(), 'cached' => is_scalar($cached) ? mb_substr((string) $cached, 0, 2000) : null, 'error' => $error];
                }
                if (count(array_filter($row, fn (array $cell): bool => $cell['value'] !== null || $cell['error'] !== null)) === 0) {
                    continue;
                }
                $rows[$number] = $row;
            }
            if ($rows === []) {
                $this->fail('File belum memiliki data pasien. Isi sheet Pasien mulai baris 2.');
            }

            return ['headers' => $headers, 'rows' => $rows];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('File XLSX tidak dapat dibaca. Pastikan file tidak rusak atau dilindungi kata sandi.');
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    /** @param list<string> $headers
     * @param  list<array<int, mixed>>  $rows
     */
    public function writeSheet(Worksheet $sheet, array $headers, array $rows): void
    {
        foreach ([$headers, ...$rows] as $number => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $number + 1], (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$last.'1');
        $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:'.$last.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF00008B');
        $sheet->getRowDimension(1)->setRowHeight(28);
        for ($column = 1; $column <= count($headers); $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($column === 1 ? 32 : 26);
        }
    }

    private function checkArchive(UploadedFile $file): void
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            $this->fail('File harus berupa XLSX asli. Gunakan template yang tersedia.');
        }
        try {
            if ($zip->numFiles > 2000) {
                $this->fail('Workbook terlalu kompleks. Salin data ke template baru.');
            }
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $total += $entry['size'];
                if ($total > 20 * 1024 * 1024 || str_contains(strtolower($entry['name']), 'vbaproject')) {
                    $this->fail('Workbook terlalu besar atau mengandung macro. Gunakan template XLSX tanpa macro.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['import_file' => $message]);
    }
}
