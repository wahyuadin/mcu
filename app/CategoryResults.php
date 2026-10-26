<?php

namespace App;

use App\Models\Anamnesa;
use App\Models\CategoryResultImport;
use App\Models\Karyawan;
use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use App\Models\RencanaKunjungan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class CategoryResults
{
    public const IDENTITY_HEADERS = ['nomor_mcu', 'nip', 'nama'];

    public const NOT_APPLICABLE = 'TIDAK BERLAKU';

    public function assertAvailable(RencanaKunjungan $visit, McuCategory $category): void
    {
        abort_unless($category->is_active && $category->import_enabled, 403, 'Import Data kategori ini tidak aktif.');
        abort_if($visit->is_closing || $visit->status === 'completed', 403, 'Kunjungan sudah selesai atau terkunci.');
    }

    /** @return Collection<int, Karyawan> */
    public function participants(RencanaKunjungan $visit): Collection
    {
        return $visit->karyawans()->with('paket', 'anamnesa')->where('perusahaan_id', $visit->perusahaan_id)
            ->whereHas('paket', fn ($query) => $query->whereHas('rencanaKunjungans', fn ($query) => $query->whereKey($visit->id)))
            ->orderBy('id')->get();
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(Karyawan $participant, McuCategory $category): array
    {
        $exam = $participant->anamnesa ?? new Anamnesa;
        $definitions = [];
        foreach ($exam->dynamicGroups($participant) as $group) {
            if ($group['id'] !== $category->id) {
                continue;
            }
            foreach ($group['items'] as $item) {
                if ($item['type'] !== 'label') {
                    $definitions['d_'.$item['id']] = [...$item, 'storage' => 'dynamic', 'section' => $exam->groupSection($group)];
                }
            }
        }
        if ($category->isAnamnesis()) {
            foreach (ParticipantAnamnesaForm::groups($participant) as $items) {
                foreach ($items as $key => $item) {
                    if ($exam->includes($participant, $key)) {
                        $definitions['a_'.$key] = [...$item, 'id' => $key, 'type' => $item['type'] === 'choice' ? 'radio' : 'text', 'storage' => 'history', 'section' => 'anamnesa'];
                    }
                }
            }
        } elseif ($category->isPhysical()) {
            foreach (McuCatalog::physical() as $items) {
                foreach ($items as $key => $label) {
                    if ($key === 'bmi' || ! $exam->includes($participant, $key)) {
                        continue;
                    }
                    foreach (McuCatalog::physicalFields()[$key] ?? [$key] as $field) {
                        $definitions['p_'.$field] = ['id' => $field, 'label' => $label.' ('.$field.')', 'type' => in_array($field, ['tinggi_badan', 'berat_badan', 'lingkar_pinggang', 'suhu_badan', 'nadi', 'pernapasan'], true) ? 'number' : 'text', 'physical_key' => $field, 'storage' => 'physical', 'section' => 'fisik'];
                    }
                }
            }
        }

        return $definitions;
    }

    /** @return array<string, string> */
    public function columns(RencanaKunjungan $visit, McuCategory $category): array
    {
        $columns = [];
        foreach ($this->participants($visit) as $participant) {
            foreach ($this->definitions($participant, $category) as $key => $item) {
                $hint = match ($item['type']) {
                    'radio' => 'diisi Ya/Tidak',
                    'number' => 'diisi angka',
                    'laboratory' => ($item['lab_config']['result_type'] ?? 'text') === 'number' ? 'diisi angka' : 'diisi teks',
                    default => 'diisi teks',
                };
                $hint = match ($item['physical_key'] ?? null) {
                    'tinggi_badan' => 'diisi angka, contoh: 170',
                    'berat_badan' => 'diisi angka, contoh: 65',
                    'tekanan_darah' => 'contoh: 120/80',
                    default => $hint,
                };
                $columns[$key] ??= $item['label'].' ['.$hint.']';
                if ($item['storage'] !== 'physical') {
                    $columns['note:'.$key] ??= 'Catatan '.$item['label'].($item['type'] === 'radio' ? ' [opsional jika Ya]' : ' [opsional]');
                }
            }
        }

        return $columns;
    }

    public function template(RencanaKunjungan $visit, McuCategory $category): Spreadsheet
    {
        $columns = $this->columns($visit, $category);
        if ($columns === []) {
            $this->fail('Kategori ini belum terikat pada paket peserta kunjungan.');
        }
        $participants = $this->participants($visit);
        if ($participants->count() > 5000 || count($columns) + 3 > 512 || ($participants->count() + 2) * (count($columns) + 3) > 200000) {
            $this->fail('Template melebihi batas 5.000 peserta, 512 kolom, atau 200.000 sel. Pisahkan kategori atau kunjungan.');
        }
        $rows = [];
        foreach ($participants as $participant) {
            $definitions = $this->definitions($participant, $category);
            $row = [$participant->nomor_mcu, $participant->nip, $participant->nama_karyawan];
            foreach ($columns as $key => $header) {
                $definitionKey = str_starts_with($key, 'note:') ? substr($key, 5) : $key;
                $row[] = isset($definitions[$definitionKey]) ? '' : self::NOT_APPLICABLE;
            }
            $rows[] = $row;
        }
        $book = new Spreadsheet;
        $spreadsheets = new PatientSpreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Hasil');
        $spreadsheets->writeSheet($sheet, [...self::IDENTITY_HEADERS, ...array_values($columns)], $rows);
        $sheet->insertNewRowBefore(1);
        foreach ([...self::IDENTITY_HEADERS, ...array_keys($columns)] as $index => $key) {
            $sheet->setCellValueExplicit([$index + 1, 1], $key, DataType::TYPE_STRING);
        }
        $sheet->getRowDimension(1)->setVisible(false);
        $sheet->freezePane('D3');
        $sheet->setAutoFilter('A2:'.$sheet->getHighestDataColumn().'2');
        $sheet->getRowDimension(2)->setRowHeight(60);
        $sheet->getStyle('A2:'.$sheet->getHighestDataColumn().'2')->getAlignment()->setWrapText(true);
        $spreadsheets->writeSheet($book->createSheet()->setTitle('Petunjuk'), ['Ketentuan', 'Penjelasan'], [
            ['Kategori', $category->name], ['Identitas', 'Nomor MCU, NIP, dan nama sudah terisi. Jangan mengubah identitas atau baris mapping tersembunyi. Isi jawaban mulai baris 3.'],
            ['Jawaban radio', 'Isi YA atau TIDAK. Catatan radio hanya dipakai jika jawaban YA.'],
            ['Angka', 'Isi angka tanpa rumus. BMI dan kategori dihitung otomatis.'],
            ['Tidak berlaku', 'Biarkan TIDAK BERLAKU pada item yang tidak terikat di paket peserta.'],
            ['Data lama', 'Hanya bagian kosong yang diisi. Hasil tersimpan tidak ditimpa. Semua hasil import menjadi draft.'],
            ['Keamanan', 'Peserta harus memiliki foto episode. Hasil final, peserta batal/tidak hadir, dan kunjungan selesai/terkunci tidak dapat diimport.'],
        ]);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** @param array{headers: list<string>, rows: array<int, array>} $source
     * @return array{rows: list<array<string, mixed>>, digest: string, stats: array<string, int>}
     */
    public function preview(RencanaKunjungan $visit, McuCategory $category, array $source): array
    {
        $this->assertAvailable($visit, $category);
        $columns = [];
        foreach ($source['headers'] as $header) {
            if (in_array($header, self::IDENTITY_HEADERS, true)) {
                $columns[] = $header;
            } elseif (preg_match('/^(?:note:)?[dap]_[a-zA-Z0-9_-]+$/', $header)) {
                $columns[] = $header;
            } else {
                $this->fail('Kolom tidak dikenal: '.$header.'. Gunakan template kategori ini.');
            }
        }
        if (array_diff(self::IDENTITY_HEADERS, $columns) !== [] || count($columns) !== count(array_unique($columns))) {
            $this->fail('Kolom identitas tidak lengkap atau terdapat kode kolom duplikat.');
        }
        $participants = $this->participants($visit)->keyBy('nomor_mcu');
        $rows = $states = $seen = [];
        foreach ($source['rows'] as $number => $cells) {
            $values = array_combine($columns, array_column($cells, 'value'));
            $errors = [];
            foreach ($cells as $cell) {
                if ($cell['error'] || $cell['type'] === DataType::TYPE_FORMULA) {
                    $errors[] = $cell['error'] ?: 'Rumus Excel tidak diperbolehkan. Tempel nilai saja.';
                }
            }
            $participant = $participants->get($values['nomor_mcu']);
            $updates = [];
            $retained = 0;
            if (! $participant || ($values['nip'] !== $participant->nip || $values['nama'] !== $participant->nama_karyawan)) {
                $errors[] = 'Identitas peserta, kunjungan, atau kategori tidak sesuai template.';
            } elseif (isset($seen[$participant->id])) {
                $errors[] = 'Peserta duplikat dalam file.';
            } else {
                $seen[$participant->id] = true;
                $exam = $participant->anamnesa ?? new Anamnesa;
                $definitions = $this->definitions($participant, $category);
                $states[$participant->id] = [$participant->getRawOriginal(), $exam->getRawOriginal(), $participant->paket->getRawOriginal()];
                foreach ($values as $key => $value) {
                    if (in_array($key, self::IDENTITY_HEADERS, true) || blank($value)) {
                        continue;
                    }
                    $isNote = str_starts_with($key, 'note:');
                    $definitionKey = $isNote ? substr($key, 5) : $key;
                    $item = $definitions[$definitionKey] ?? null;
                    if (! $item) {
                        if ($value !== self::NOT_APPLICABLE) {
                            $errors[] = 'Item '.$key.' tidak terikat pada snapshot paket peserta.';
                        }

                        continue;
                    }
                    if ($isNote && $item['storage'] === 'physical') {
                        $errors[] = 'Kolom catatan ini tidak tersedia pada snapshot fisik lama.';

                        continue;
                    }
                    $answer = $values[$definitionKey] ?? null;
                    $answer = $item['type'] === 'radio' && $answer !== null ? mb_strtoupper($answer) : $answer;
                    $existing = $this->stored($exam, $item, false);
                    $effectiveAnswer = filled($existing) ? $existing : $answer;
                    if ($isNote && filled($existing) && filled($answer) && ($item['type'] === 'radio' ? mb_strtoupper((string) $existing) : (string) $existing) !== $answer) {
                        $retained++;

                        continue;
                    }
                    if ($isNote && $item['type'] === 'radio' && mb_strtoupper((string) $effectiveAnswer) !== 'YA') {
                        continue;
                    }
                    $normalized = ! $isNote && $item['type'] === 'radio' ? mb_strtoupper($value) : $value;
                    $validator = Validator::make(['value' => $normalized], ['value' => $isNote ? ['nullable', 'string', 'max:2000'] : McuItem::answerRules($item)]);
                    if ($validator->fails()) {
                        $errors[] = $item['label'].': '.implode(' ', $validator->errors()->all());
                    } elseif ($isNote && blank($effectiveAnswer)) {
                        $errors[] = $item['label'].': isi jawaban sebelum menambahkan catatan.';
                    } elseif (filled($this->stored($exam, $item, $isNote))) {
                        $retained++;
                    } else {
                        $updates[] = ['item' => $item, 'note' => $isNote, 'value' => $normalized];
                    }
                }
                if ($updates !== [] && (! $participant->hasStoredPhoto() || $participant->is_closing || $exam->status === 'final' || in_array($participant->status_pemeriksaan, ['batal', 'tidak_hadir'], true))) {
                    $errors[] = 'Peserta belum memiliki foto episode, hasil sudah final, atau peserta tidak dapat diedit.';
                }
            }
            $rows[] = ['number' => (int) $number, 'participant_id' => $participant?->id, 'name' => $participant?->nama_karyawan ?? '', 'nomor_mcu' => $participant?->nomor_mcu ?? '',
                'status' => $errors !== [] ? 'failed' : ($updates !== [] ? 'ready' : 'skipped'), 'errors' => $errors, 'updates' => $updates, 'retained' => $retained];
        }
        $counts = array_count_values(array_column($rows, 'status'));

        return ['rows' => $rows, 'digest' => hash_hmac('sha256', json_encode([$states, $visit->getRawOriginal(), $category->getRawOriginal()], JSON_THROW_ON_ERROR), config('app.key')),
            'stats' => ['total' => count($rows), 'ready' => $counts['ready'] ?? 0, 'failed' => $counts['failed'] ?? 0, 'skipped' => $counts['skipped'] ?? 0]];
    }

    /** @param array<string, mixed> $item */
    private function stored(Anamnesa $exam, array $item, bool $note): mixed
    {
        return match ($item['storage']) {
            'history' => $exam->historyValue($item['id'])[$note ? 'note' : 'answer'],
            'physical' => $note ? ($exam->dynamic_notes ?? [])['p_'.$item['id']] ?? null : $exam->{$item['id']},
            default => ($note ? $exam->dynamic_notes : $exam->dynamic_answers)[$item['id']] ?? null,
        };
    }

    public function confirm(CategoryResultImport $batch, string $digest): CategoryResultImport
    {
        return DB::transaction(function () use ($batch, $digest): CategoryResultImport {
            $batch = CategoryResultImport::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $visit = RencanaKunjungan::whereKey($batch->rencana_kunjungan_id)->lockForUpdate()->firstOrFail();
            $category = McuCategory::whereKey($batch->mcu_category_id)->lockForUpdate()->firstOrFail();
            $this->assertAvailable($visit, $category);
            if ($batch->status === 'complete') {
                return $batch;
            }
            $participants = $visit->karyawans()->orderBy('id')->lockForUpdate()->get();
            Paket::whereIn('id', $participants->pluck('paket_id'))->orderBy('id')->lockForUpdate()->get();
            Anamnesa::whereIn('karyawan_id', $participants->modelKeys())->orderBy('karyawan_id')->lockForUpdate()->get();
            $preview = $this->preview($visit, $category, $batch->source);
            if (! hash_equals($batch->digest, $digest) || ! hash_equals($batch->digest, $preview['digest'])) {
                $this->fail('Data berubah sejak preview. Upload ulang untuk memeriksa hasil terbaru.');
            }
            if ($preview['stats']['failed'] > 0 || $preview['stats']['ready'] === 0) {
                $this->fail('Perbaiki semua baris gagal dan pastikan ada hasil baru sebelum import.');
            }
            $participants = $this->participants($visit)->keyBy('id');
            foreach ($preview['rows'] as &$row) {
                if ($row['status'] !== 'ready') {
                    continue;
                }
                $participant = $participants[$row['participant_id']];
                $exam = $participant->anamnesa ?? new Anamnesa(['karyawan_id' => $participant->id, 'rencana_kunjungan_id' => $visit->id]);
                $exam->package_items ??= $participant->paket->active_items ?? [];
                $exam->dynamic_schema ??= $exam->dynamicGroups($participant);
                $history = $exam->anamnesa_data ?? [];
                $answers = $exam->dynamic_answers ?? [];
                $notes = $exam->dynamic_notes ?? [];
                $sections = $exam->section_status ?? [];
                $recalculateBmi = false;
                foreach ($row['updates'] as $update) {
                    $item = $update['item'];
                    $value = $update['value'];
                    if (! $update['note'] && in_array($item['physical_key'] ?? null, ['tinggi_badan', 'berat_badan'], true)) {
                        $recalculateBmi = true;
                    }
                    if ($item['storage'] === 'history') {
                        $history[$item['id']] ??= $exam->historyValue($item['id']);
                        $history[$item['id']][$update['note'] ? 'note' : 'answer'] = ! $update['note'] && $item['type'] === 'radio' ? ($value === 'YA' ? 'Ya' : 'Tidak') : $value;
                    } elseif ($update['note']) {
                        $notes[($item['storage'] === 'physical' ? 'p_' : '').$item['id']] = $value;
                    } elseif ($item['storage'] === 'physical') {
                        $exam->{$item['id']} = $value;
                    } else {
                        $answers[$item['id']] = $value;
                    }
                    $sections[$item['section']] = 'draft';
                }
                $exam->fill(['anamnesa_data' => $history, 'dynamic_answers' => $answers, 'dynamic_notes' => $notes, 'section_status' => $sections, 'status' => 'draft', 'lock_version' => ($exam->lock_version ?? 0) + 1]);
                if ($recalculateBmi) {
                    $height = $exam->physicalMeasurement($participant, 'tinggi_badan');
                    $weight = $exam->physicalMeasurement($participant, 'berat_badan');
                    $bmi = Anamnesa::calculateBmi($height === null ? null : (float) $height, $weight === null ? null : (float) $weight);
                    $exam->fill(['bmi' => $bmi['bmi'], 'kategori_bmi' => $bmi['kategori']]);
                }
                $exam->save();
                $participant->update(['status_pemeriksaan' => 'proses']);
                $row['status'] = 'succeeded';
                unset($row['updates']);
            }
            unset($row);
            $batch->update(['status' => 'complete', 'results' => $preview['rows']]);

            return $batch;
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['import_file' => $message]);
    }
}
