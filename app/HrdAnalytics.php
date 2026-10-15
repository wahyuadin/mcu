<?php

namespace App;

use App\Models\Anamnesa;
use App\Models\Karyawan;
use App\Models\McuItem;
use App\Models\Perusahaan;
use App\Models\RencanaKunjungan;

class HrdAnalytics
{
    /** @param array<string, mixed> $filters
     * @return array{sources: array<string, array<string, mixed>>, records: list<array<string, mixed>>, planned: ?int}
     */
    public function dataset(Perusahaan $company, array $filters): array
    {
        $visits = $company->rencanaKunjungans()->with('pakets')->when($filters['visit'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($filters['period'] ?? null, fn ($query, $period) => $query->where('periode_kunjungan', $period))->get();
        $examColumns = array_diff((new Anamnesa)->getFillable(), ['revisions', 'dynamic_attachments', 'category_doctors']);
        $participants = Karyawan::where('perusahaan_id', $company->id)->whereIn('rencana_kunjungan_id', $visits->modelKeys())
            ->when($filters['package'] ?? null, fn ($query, $id) => $query->where('paket_id', $id))
            ->with(['anamnesa' => fn ($query) => $query->select(['id', ...$examColumns]), 'paket', 'rencanaKunjungan'])
            ->select(['id', 'perusahaan_id', 'rencana_kunjungan_id', 'paket_id', 'nama_karyawan', 'nomor_mcu', 'nip', 'bagian', 'jenis_kelamin', 'tanggal_lahir', 'tanggal_mcu', 'is_closing', 'status_pemeriksaan'])
            ->orderBy('nama_karyawan')->orderBy('id')->lazy(150);
        $sources = [];
        foreach (['gender' => 'Jenis kelamin', 'age' => 'Kelompok usia', 'department' => 'Departemen', 'package' => 'Paket MCU', 'visit' => 'Kunjungan', 'status' => 'Progres pemeriksaan', 'fitness' => 'Kelayakan kerja'] as $key => $label) {
            $sources[$key] = ['id' => $key, 'title' => $label, 'group' => in_array($key, ['fitness', 'bmi'], true) ? 'Kesimpulan' : 'Demografi', 'kind' => 'choice', 'medical' => in_array($key, ['fitness', 'bmi'], true)];
        }
        foreach ($visits as $visit) {
            foreach ($visit->pakets as $package) {
                if (! empty($filters['package']) && $package->id !== $filters['package']) {
                    continue;
                }
                foreach ($package->dynamic_schema ?? [] as $group) {
                    foreach ($group['items'] as $item) {
                        $this->register($sources, $item, $group);
                    }
                }
            }
        }
        $records = [];
        foreach ($participants as $participant) {
            $exam = $participant->anamnesa;
            $released = $participant->is_closing && $participant->status_pemeriksaan === 'selesai' && $exam?->status === 'final';
            $referenceDate = $participant->tanggal_mcu ?? $participant->rencanaKunjungan->tgl_mulai ?? $exam?->finalized_at;
            $age = $participant->tanggal_lahir && $referenceDate ? (int) $participant->tanggal_lahir->diffInYears($referenceDate) : null;
            $values = ['gender' => match ($participant->jenis_kelamin) {
                'L' => 'Pria', 'P' => 'Wanita', default => 'Belum diisi'
            },
                'age' => $age === null || $age < 0 ? 'Belum diisi' : match (true) {
                    $age <= 25 => '25 atau kurang', $age <= 35 => '26–35', $age <= 45 => '36–45', $age <= 55 => '46–55', default => 'Lebih dari 55'
                },
                'department' => $participant->bagian ?: 'Belum diisi', 'package' => $participant->paket?->nama_paket ?? 'Paket tidak tersedia', 'visit' => $participant->rencanaKunjungan->nomor_kunjungan,
                'status' => ['belum' => 'Belum mulai', 'proses' => 'Dalam proses', 'siap_finalisasi' => 'Siap finalisasi', 'selesai' => 'Final', 'batal' => 'Batal', 'tidak_hadir' => 'Tidak hadir'][$participant->status_pemeriksaan] ?? 'Tidak diketahui'];
            if ($released) {
                $values['fitness'] = $exam->status_kelaikan;
            }
            foreach ($exam?->dynamicGroups($participant) ?? $participant->paket->dynamic_schema ?? [] as $group) {
                foreach ($group['items'] as $item) {
                    $key = $this->register($sources, $item, $group);
                    if (! $key || ! $released) {
                        continue;
                    }
                    $answer = ($exam->dynamic_answers ?? [])[$item['id']] ?? null;
                    $values[$key] = $item['type'] === 'laboratory' ? $exam->laboratoryEvaluation($item)['status'] : (is_scalar($answer) ? $answer : null);
                }
            }
            $active = $exam?->package_items ?? $participant->paket->active_items ?? [];
            if (! empty($active['bmi'])) {
                $sources['bmi'] = ['id' => 'bmi', 'title' => 'Kategori BMI', 'group' => 'Kesimpulan', 'kind' => 'choice', 'medical' => true];
                if ($released) {
                    $values['bmi'] = $exam->kategori_bmi;
                }
            }
            foreach (McuCatalog::laboratory() as $group => $items) {
                foreach ($items as $key => $definition) {
                    if (empty($active[$key])) {
                        continue;
                    }
                    $row = ($exam?->laboratorium ?? [])[$key] ?? [];
                    $source = 'lab:'.$key.':'.substr(hash('sha256', json_encode([$row['unit'] ?? $definition['unit'], $row['reference'] ?? ''])), 0, 12);
                    $sources[$source] = ['id' => $source, 'title' => $definition['label'], 'group' => $group, 'kind' => 'laboratory', 'medical' => true,
                        'unit' => $row['unit'] ?? $definition['unit'], 'reference' => $row['reference'] ?? ''];
                    if ($released) {
                        $values[$source] = ($row['skipped'] ?? false) ? 'Tidak dilakukan' : (blank($row['result'] ?? null) ? 'Belum diperiksa' : (in_array($row['flag'] ?? '', ['Tinggi', 'Rendah', 'Tidak Normal'], true) ? 'Tidak normal' : (($row['flag'] ?? '') === 'Normal' ? 'Normal' : 'Tidak dapat dibandingkan')));
                    }
                }
            }
            foreach (McuCatalog::physical() as $group => $items) {
                foreach ($items as $key => $label) {
                    if (empty($active[$key]) || $key === 'bmi') {
                        continue;
                    }
                    foreach (McuCatalog::physicalFields()[$key] ?? [$key] as $field) {
                        $source = 'physical:'.$field;
                        $sources[$source] = ['id' => $source, 'title' => $label.' · '.$field, 'group' => $group, 'kind' => in_array($field, ['tinggi_badan', 'berat_badan', 'nadi', 'suhu_badan', 'lingkar_pinggang', 'pernapasan'], true) ? 'number' : 'text', 'medical' => true];
                        if ($released) {
                            $values[$source] = $exam->{$field};
                        }
                    }
                }
            }
            foreach (McuCatalog::anamnesa() + ['Riwayat tambahan (paket lama)' => ['asma' => ['label' => 'Asma / alergi', 'type' => 'choice'], 'ginjal' => ['label' => 'Penyakit ginjal', 'type' => 'choice']]] as $group => $items) {
                foreach ($items as $key => $definition) {
                    if (empty($active[$key])) {
                        continue;
                    }
                    $source = 'history:'.$key;
                    $sources[$source] = ['id' => $source, 'title' => $definition['label'], 'group' => $group, 'kind' => ($definition['type'] ?? 'choice') === 'choice' ? 'choice' : 'text', 'medical' => true];
                    if ($released) {
                        $values[$source] = $exam->historyValue($key)['answer'];
                    }
                }
            }
            foreach (McuCatalog::supporting() as $key => $definition) {
                if (empty($active[$key])) {
                    continue;
                }
                $source = 'supporting:'.$key;
                $sources[$source] = ['id' => $source, 'title' => $definition['label'], 'group' => 'Penunjang', 'kind' => 'laboratory', 'medical' => true];
                if ($released) {
                    $row = ($exam->penunjang ?? [])[$key] ?? [];
                    $hasResult = collect(array_keys($definition['fields']))->contains(fn (string $field): bool => filled($row[$field] ?? null));
                    $values[$source] = ($row['skipped'] ?? false) ? 'Tidak dilakukan' : (! $hasResult ? 'Belum diperiksa' : match ($row['flag'] ?? null) {
                        'Normal' => 'Normal', 'Tidak Normal' => 'Tidak normal', default => 'Tidak dapat dibandingkan',
                    });
                }
            }
            $records[] = ['id' => $participant->id, 'name' => $participant->nama_karyawan, 'number' => $participant->nomor_mcu, 'employee' => $participant->nip,
                'status' => $participant->status_pemeriksaan, 'final' => $participant->status_pemeriksaan === 'selesai' && $exam?->status === 'final', 'released' => $released, 'values' => $values];
        }
        $planned = $visits->isNotEmpty() && $visits->every(fn (RencanaKunjungan $visit): bool => $visit->jumlah_peserta_direncanakan !== null) ? (int) $visits->sum('jumlah_peserta_direncanakan') : null;
        foreach (['laboratory' => '10 temuan laboratorium terbanyak', 'supporting' => '10 temuan penunjang terbanyak'] as $family => $title) {
            $sources['ranking:'.$family] = ['id' => 'ranking:'.$family, 'title' => $title, 'kind' => 'ranking', 'group' => 'Temuan terkonfirmasi', 'medical' => true];
        }

        return ['sources' => $sources, 'records' => $records, 'planned' => $planned];
    }

    /** @param array<string, array<string, mixed>> $sources
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $group
     */
    private function register(array &$sources, array $item, array $group): ?string
    {
        if (($item['type'] ?? '') === 'label') {
            return null;
        }
        $key = 'item:'.$item['id'].':'.substr(hash('sha256', json_encode([$item['label'], $group['name'], $item['subcategory']['name'] ?? null, $item['type'], $item['lab_config'] ?? null, $item['yes_report'] ?? null, $item['no_report'] ?? null])), 0, 12);
        $sources[$key] ??= ['id' => $key, 'title' => $item['label'], 'group' => $group['name'].(isset($item['subcategory']) ? ' · '.$item['subcategory']['name'] : ''),
            'kind' => match ($item['type']) {
                'laboratory' => 'laboratory', 'radio' => 'choice', 'number' => 'number', default => 'text'
            }, 'medical' => true, 'unit' => $item['lab_config']['unit'] ?? '', 'reference' => $item['lab_config']['reference'] ?? ''];

        return $key;
    }

    /** @param array<string, array<string, mixed>> $sources
     * @return array<string, array<string, mixed>>
     */
    public function builderSources(array $sources): array
    {
        foreach (McuItem::with('category', 'subcategory')->where('is_active', true)->whereHas('category', fn ($query) => $query->where('is_active', true))->orderBy('sort_order')->get() as $item) {
            $this->register($sources, $item->definition(), ['name' => $item->category->name]);
        }

        return $sources;
    }

    /** @return array<string, mixed> */
    public function summarize(array $dataset, array $filters, ?array $layout): array
    {
        $records = array_values(array_filter($dataset['records'], fn (array $row): bool => $this->matches($row, $filters)));
        $collection = collect($records);
        $final = $collection->where('final', true)->count();
        $target = empty($filters['package']) && empty($filters['cross_source']) && empty($filters['q']) && empty($filters['released']) ? $dataset['planned'] : null;
        $stats = ['planned' => $target, 'registered' => count($records), 'final' => $final, 'remaining' => $target === null ? null : max(0, $target - $final),
            'released' => $collection->where('released', true)->count(), 'canceled' => $collection->where('status', 'batal')->count(), 'absent' => $collection->where('status', 'tidak_hadir')->count()];
        $widgets = [];
        $configuration = $layout ?? array_map(fn (array $source): array => ['source' => $source['id'], 'title' => $source['title'], 'dimension' => '', 'chart' => $source['kind'] === 'number' ? 'number' : ($source['kind'] === 'text' ? 'table' : ($source['kind'] === 'laboratory' ? 'indicator' : 'bar')), 'metric' => $source['kind'] === 'number' ? 'average' : 'count', 'width' => 1, 'height' => 1], array_values($dataset['sources']));
        foreach ($configuration as $config) {
            $source = $dataset['sources'][$config['source']] ?? null;
            $dimension = $dataset['sources'][$config['dimension'] ?? ''] ?? null;
            $filterSource = $dataset['sources'][$config['filter_source'] ?? ''] ?? null;
            $widgetRecords = array_values(array_filter($records, function (array $row) use ($source, $dimension, $filterSource, $config): bool {
                if (($source['medical'] ?? true) || ($dimension['medical'] ?? false) || ($filterSource['medical'] ?? false)) {
                    if (! $row['released']) {
                        return false;
                    }
                }

                return empty($config['filter_source']) || (string) ($row['values'][$config['filter_source']] ?? '') === (string) ($config['filter_value'] ?? '');
            }));
            $widgets[] = ($source['kind'] ?? null) === 'ranking' ? $this->ranking($widgetRecords, $config, $dataset['sources']) : $this->widget($widgetRecords, $config, $source, $dimension);
        }
        $page = min(max(1, (int) ($filters['page'] ?? 1)), max(1, (int) ceil(count($records) / 30)));
        $details = array_slice($records, ($page - 1) * 30, 30);
        $details = array_map(fn (array $row): array => array_diff_key($row, ['values' => true]) + ['department' => $row['values']['department'], 'package' => $row['values']['package']], $details);

        return ['stats' => $stats, 'widgets' => $widgets, 'sources' => array_values($dataset['sources']), 'participants' => $details,
            'page' => $page, 'pages' => max(1, (int) ceil(count($records) / 30)), 'filtered_count' => count($records), 'layout' => $configuration];
    }

    /** @param list<array<string, mixed>> $records
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $source
     * @param  array<string, mixed>|null  $dimension
     * @return array<string, mixed>
     */
    private function widget(array $records, array $config, ?array $source, ?array $dimension): array
    {
        $source ??= ['id' => $config['source'], 'kind' => 'text', 'group' => 'Sumber tidak tersedia pada filter ini'];
        $buckets = [];
        $numbers = [];
        $total = $valid = $abnormal = 0;
        foreach ($records as $record) {
            $value = $record['values'][$source['id']] ?? null;
            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }
            $isNumeric = $source['kind'] === 'number' && is_numeric($value) && is_finite((float) $value);
            if (in_array($config['metric'], ['average', 'min', 'max'], true) && ! $isNumeric) {
                continue;
            }
            $total++;
            $comparable = $source['kind'] !== 'laboratory' || in_array($value, ['Normal', 'Tidak normal'], true);
            $valid += (int) $comparable;
            $abnormal += (int) ($source['kind'] === 'laboratory' && $value === 'Tidak normal');
            if ($isNumeric) {
                $numbers[] = (float) $value;
            }
            $label = (string) ($dimension ? ($record['values'][$dimension['id']] ?? 'Belum diisi') : $value);
            $bucket = $buckets[$label] ?? ['label' => $label, 'count' => 0, 'valid' => 0, 'numbers' => [], 'series' => []];
            $bucket['count']++;
            $bucket['valid'] += (int) $comparable;
            if ($isNumeric) {
                $bucket['numbers'][] = (float) $value;
            }
            if ($dimension && $source['kind'] !== 'number') {
                $bucket['series'][(string) $value] = ($bucket['series'][(string) $value] ?? 0) + 1;
            }
            $buckets[$label] = $bucket;
        }
        $rows = [];
        foreach ($buckets as $bucket) {
            $percent = $valid > 0 && $bucket['valid'] > 0 ? round($bucket['valid'] / $valid * 100, 1) : null;
            $number = $this->numeric($bucket['numbers']);
            $rows[] = ['label' => $bucket['label'], 'count' => $bucket['count'], 'percent' => $percent,
                'value' => match ($config['metric']) {
                    'percent' => $percent, 'average', 'min', 'max' => $number[$config['metric']] ?? null, default => $bucket['count']
                },
                'series' => array_map(fn (string|int $label, int $count): array => ['label' => (string) $label, 'count' => $count], array_keys($bucket['series']), array_values($bucket['series'])),
                'cross_source' => $dimension['id'] ?? $source['id'], 'cross_value' => $bucket['label']];
        }
        usort($rows, fn (array $left, array $right): int => ($right['value'] ?? -INF) <=> ($left['value'] ?? -INF));

        return [...$config, 'kind' => $source['kind'], 'group' => $source['group'], 'unit' => $source['unit'] ?? '', 'reference' => $source['reference'] ?? '',
            'dimension_title' => $dimension['title'] ?? null, 'total' => $total, 'valid' => $valid, 'rows' => $rows,
            'numeric' => $this->numeric($numbers), 'abnormal_percent' => $valid > 0 && $source['kind'] === 'laboratory' ? round($abnormal / $valid * 100, 1) : null];
    }

    /** @param list<float> $numbers
     * @return array{count: int, min: float, max: float, average: float}|null
     */
    private function numeric(array $numbers): ?array
    {
        return $numbers === [] ? null : ['count' => count($numbers), 'min' => min($numbers), 'max' => max($numbers), 'average' => round(array_sum($numbers) / count($numbers), 2)];
    }

    /** @param list<array<string, mixed>> $records
     * @param  array<string, mixed>  $config
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function ranking(array $records, array $config, array $sources): array
    {
        $rows = [];
        foreach ($sources as $source) {
            if ($source['kind'] !== 'laboratory' || (str_starts_with($source['id'], 'supporting:') !== ($config['source'] === 'ranking:supporting'))) {
                continue;
            }
            $counts = array_count_values(array_map(fn (array $row): string => (string) ($row['values'][$source['id']] ?? ''), $records));
            $count = $counts['Tidak normal'] ?? 0;
            if ($count === 0) {
                continue;
            }
            $valid = $count + ($counts['Normal'] ?? 0);
            $percent = round($count / $valid * 100, 1);
            $rows[] = ['label' => $source['title'], 'count' => $count, 'percent' => $percent, 'value' => $config['metric'] === 'percent' ? $percent : $count,
                'context' => trim(($source['unit'] ?? '').' · '.($source['reference'] ?? ''), ' ·'), 'series' => [], 'cross_source' => $source['id'], 'cross_value' => 'Tidak normal'];
        }
        usort($rows, fn (array $left, array $right): int => $right['value'] <=> $left['value']);

        return [...$config, 'kind' => 'ranking', 'group' => 'Hanya penanda medis terkonfirmasi; satu peserta dapat memiliki beberapa temuan.', 'unit' => '', 'reference' => '',
            'dimension_title' => null, 'total' => array_sum(array_column($rows, 'count')), 'valid' => count($records), 'rows' => array_slice($rows, 0, 10), 'numeric' => null, 'abnormal_percent' => null];
    }

    public function matches(array $row, array $filters): bool
    {
        if (! empty($filters['q']) && ! str_contains(mb_strtolower($row['name'].' '.$row['number'].' '.$row['employee']), mb_strtolower($filters['q']))) {
            return false;
        }
        if (! empty($filters['cross_source'])) {
            if (! array_key_exists($filters['cross_source'], $row['values']) || (string) ($row['values'][$filters['cross_source']] ?? '') !== (string) ($filters['cross_value'] ?? '')) {
                return false;
            }
        }

        return empty($filters['released']) || $row['released'];
    }
}
