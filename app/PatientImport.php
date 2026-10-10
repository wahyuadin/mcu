<?php

namespace App;

use App\Models\Karyawan;
use App\Models\Paket;
use App\Models\Pasien;
use App\Models\PatientImportBatch;
use App\Models\PatientImportReceipt;
use App\Models\RencanaKunjungan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

class PatientImport
{
    /** @return list<array<string, mixed>> */
    public function preview(PatientImportBatch $batch): array
    {
        if ($batch->status === 'complete') {
            return $batch->results ?? [];
        }
        $rows = [];
        $mapping = $batch->mapping;
        $headers = $batch->headers;
        foreach ($batch->source_rows as $number => $cells) {
            $data = [];
            $errors = [];
            foreach (PatientSpreadsheet::HEADERS as $field) {
                $header = $mapping[$field] ?? null;
                $index = $header === null ? false : array_search($header, $headers, true);
                $cell = $index === false ? null : ($cells[$index] ?? null);
                $value = $cell['value'] ?? null;
                if ($cell && $cell['error']) {
                    $errors[] = $field.': '.$cell['error'];
                }
                if ($cell && $cell['type'] === DataType::TYPE_FORMULA) {
                    if (in_array($field, ['kunjungan_id', 'paket_id'], true) && filled($cell['cached'])) {
                        $value = $cell['cached'];
                    } else {
                        $errors[] = $field.': rumus tidak diperbolehkan, kecuali lookup ID dengan hasil tersimpan. Buka dan simpan file di Excel atau tempel hasil sebagai nilai.';
                        $value = null;
                    }
                }
                if ($field === 'nik' && $value !== null && $cell['type'] === DataType::TYPE_NUMERIC) {
                    $errors[] = 'NIK harus disimpan sebagai teks agar 16 digit tidak dibulatkan Excel.';
                }
                if (in_array($field, ['tanggal_lahir', 'tanggal_mcu'], true) && $cell && $cell['type'] === DataType::TYPE_NUMERIC && is_numeric($value)) {
                    try {
                        $value = Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
                    } catch (Throwable) {
                        $errors[] = $field.': tanggal Excel tidak valid.';
                        $value = null;
                    }
                }
                $data[$field] = filled($value) ? trim((string) $value) : null;
            }
            $data['jenis_kelamin'] = match ($data['jenis_kelamin']) {
                '1' => 'L',
                '2' => 'P',
                default => $data['jenis_kelamin'],
            };
            $validator = Validator::make($data, [
                'nama_karyawan' => ['required', 'string', 'max:255'], 'nik' => ['nullable', 'digits:16'],
                'nip' => ['nullable', 'string', 'max:50'], 'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
                'tanggal_lahir' => ['nullable', 'date_format:Y-m-d', 'before:today'], 'tanggal_mcu' => ['nullable', 'date_format:Y-m-d'],
                'tempat_lahir' => ['nullable', 'string', 'max:255'],
                'bagian' => ['nullable', 'string', 'max:255'], 'jabatan' => ['nullable', 'string', 'max:255'],
                'telepon' => ['nullable', 'string', 'max:255'], 'kunjungan_id' => ['required', 'uuid'], 'paket_id' => ['required', 'uuid'],
            ], ['required' => ':attribute wajib diisi.', 'in' => ':attribute harus 1 (Laki-laki) atau 2 (Perempuan). Kode L/P juga diterima.', 'uuid' => ':attribute harus berupa ID dari master.',
                'date_format' => ':attribute harus YYYY-MM-DD.', 'before' => ':attribute harus sebelum hari ini.',
                'digits' => ':attribute harus 16 digit.', 'max' => ':attribute maksimal :max karakter.']);
            $fingerprintData = $data;
            if ($fingerprintData['tempat_lahir'] === null) {
                unset($fingerprintData['tempat_lahir']);
            }
            $rows[] = ['number' => (int) $number, 'data' => $data, 'errors' => [...$errors, ...$validator->errors()->all()],
                'fingerprint' => hash_hmac('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR), config('app.key')),
                'status' => 'ready', 'nomor_mcu' => null, 'participant_id' => null, 'visit' => null, 'package' => null];
        }
        $visits = RencanaKunjungan::with('perusahaan')->withCount('karyawans')->whereIn('id', array_filter(array_column(array_column($rows, 'data'), 'kunjungan_id')))->get()->keyBy('id');
        $packages = Paket::whereIn('id', array_filter(array_column(array_column($rows, 'data'), 'paket_id')))->get()->keyBy('id');
        $niks = Pasien::whereIn('nik', array_filter(array_column(array_column($rows, 'data'), 'nik')))->pluck('nik')->all();
        $employees = Karyawan::whereIn('rencana_kunjungan_id', $visits->keys())->whereIn('nip', array_filter(array_column(array_column($rows, 'data'), 'nip')))->get(['rencana_kunjungan_id', 'nip']);
        $existingNips = $employees->mapWithKeys(fn (Karyawan $employee): array => [$employee->rencana_kunjungan_id.'|'.$employee->nip => true])->all();
        $receipts = PatientImportReceipt::with('karyawan')->whereIn('fingerprint', array_column($rows, 'fingerprint'))->get()->keyBy('fingerprint');
        $seenNiks = $seenNips = $seenRows = $reserved = [];
        foreach ($rows as &$row) {
            $data = $row['data'];
            $visit = $visits->get($data['kunjungan_id']);
            $package = $packages->get($data['paket_id']);
            $row['visit'] = $visit ? $visit->nomor_kunjungan.' · '.$visit->perusahaan->nama_perusahaan : null;
            $row['package'] = $package?->nama_paket;
            $receipt = $receipts->get($row['fingerprint']);
            if ($row['errors'] === [] && $receipt?->karyawan) {
                $row['status'] = 'existing';
                $row['nomor_mcu'] = $receipt->karyawan->nomor_mcu;
                $row['participant_id'] = $receipt->karyawan_id;

                continue;
            }
            if (! $visit || $visit->is_closing) {
                $row['errors'][] = 'Kunjungan tidak ditemukan atau sudah ditutup.';
            }
            if (! $package) {
                $row['errors'][] = 'Paket tidak ditemukan atau sudah dihapus.';
            }
            if ($receipt && ! $receipt->karyawan) {
                $row['errors'][] = 'Baris pernah diimport tetapi episode sudah dihapus. Periksa riwayat sebelum registrasi ulang.';
            }
            if ($data['nik'] && (in_array($data['nik'], $niks, true) || isset($seenNiks[$data['nik']]))) {
                $row['errors'][] = 'NIK sudah terdaftar atau duplikat dalam file. Identitas tidak digabung otomatis.';
            }
            $nipKey = $data['kunjungan_id'].'|'.$data['nip'];
            if ($data['nip'] && (isset($existingNips[$nipKey]) || isset($seenNips[$nipKey]))) {
                $row['errors'][] = 'Nomor karyawan sudah terdaftar pada kunjungan ini atau duplikat dalam file.';
            }
            if (isset($seenRows[$row['fingerprint']])) {
                $row['errors'][] = 'Data baris sama dengan baris '.$seenRows[$row['fingerprint']].'.';
            }
            if ($visit && $visit->kuota_peserta > 0 && $visit->karyawans_count + ($reserved[$visit->id] ?? 0) >= $visit->kuota_peserta) {
                $row['errors'][] = 'Kuota kunjungan sudah penuh.';
            }
            $row['status'] = $row['errors'] === [] ? 'ready' : 'failed';
            if ($row['status'] === 'ready') {
                $reserved[$visit->id] = ($reserved[$visit->id] ?? 0) + 1;
                $seenRows[$row['fingerprint']] = $row['number'];
                if ($data['nik']) {
                    $seenNiks[$data['nik']] = true;
                }
                if ($data['nip']) {
                    $seenNips[$nipKey] = true;
                }
            }
        }

        return $rows;
    }

    public function confirm(PatientImportBatch $batch, int $version): PatientImportBatch
    {
        $placements = $this->preview($batch);

        return DB::transaction(function () use ($batch, $version, $placements): PatientImportBatch {
            $batch = PatientImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status === 'complete') {
                return $batch;
            }
            if ($batch->version !== $version) {
                throw ValidationException::withMessages(['version' => 'Mapping berubah. Periksa ulang preview sebelum import.']);
            }
            RencanaKunjungan::whereIn('id', array_filter(array_column(array_column($placements, 'data'), 'kunjungan_id')))->orderBy('id')->lockForUpdate()->get();
            Paket::whereIn('id', array_filter(array_column(array_column($placements, 'data'), 'paket_id')))->orderBy('id')->lockForUpdate()->get();
            $rows = $this->preview($batch);
            foreach ($rows as &$row) {
                if ($row['status'] !== 'ready') {
                    continue;
                }
                try {
                    $participant = DB::transaction(function () use ($row): Karyawan {
                        $data = $row['data'];
                        $data['tanggal_mcu'] ??= now()->toDateString();
                        $visit = RencanaKunjungan::findOrFail($data['kunjungan_id']);
                        $visit->pakets()->syncWithoutDetaching([$data['paket_id']]);
                        unset($data['kunjungan_id']);
                        $participant = $visit->karyawans()->create([...$data, 'perusahaan_id' => $visit->perusahaan_id]);
                        PatientImportReceipt::create(['fingerprint' => $row['fingerprint'], 'karyawan_id' => $participant->id]);

                        return $participant;
                    });
                    $row['status'] = 'succeeded';
                    $row['nomor_mcu'] = $participant->nomor_mcu;
                    $row['participant_id'] = $participant->id;
                    $row['data']['tanggal_mcu'] = $participant->tanggal_mcu->toDateString();
                } catch (Throwable $exception) {
                    report($exception);
                    $row['status'] = 'failed';
                    $row['errors'][] = 'Penyimpanan baris gagal. Data baris ini tidak disimpan; silakan coba ulang.';
                }
            }
            $batch->update(['status' => 'complete', 'results' => $rows]);

            return $batch;
        });
    }
}
