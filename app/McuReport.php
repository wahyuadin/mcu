<?php

namespace App;

use App\Models\Anamnesa;
use App\Models\Karyawan;

class McuReport
{
    /** @return list<array{title: string, kind: string, groups: list<array<string, mixed>>, doctor_id: ?string, show_doctor?: bool, attachments: list<array<string, mixed>>}> */
    public function sections(Karyawan $participant, Anamnesa $exam): array
    {
        $sections = [];
        $physical = $this->physical($participant, $exam);
        if ($physical !== []) {
            $sections[] = ['title' => 'HASIL PEMERIKSAAN FISIK', 'kind' => 'columns', 'groups' => $physical, 'doctor_id' => $exam->dokter_fisik_id, 'show_doctor' => true, 'attachments' => []];
        }
        $history = [];
        $number = 0;
        foreach (McuCatalog::anamnesa() as $name => $items) {
            $rows = [];
            foreach ($items as $key => $item) {
                $number++;
                if (! $exam->includes($participant, $key)) {
                    continue;
                }
                $value = $exam->historyValue($key);
                $rows[] = ['key' => $key, 'number' => $number, 'label' => $item['label'], 'value' => ($value['answer'] ?? 'Belum diisi').(filled($value['note']) ? ' — '.$value['note'] : '')];
            }
            if ($rows !== []) {
                $history[] = ['name' => $name, 'column' => $number <= 14 ? 0 : 1, 'rows' => $rows];
            }
        }
        if ($history !== []) {
            $sections[] = ['title' => 'ANAMNESA PASIEN', 'kind' => 'columns', 'groups' => $history, 'doctor_id' => $exam->dokter_id, 'show_doctor' => true, 'attachments' => []];
        }

        $laboratory = [];
        foreach (McuCatalog::laboratory() as $name => $items) {
            $rows = [];
            foreach ($items as $key => $item) {
                if (! $exam->includes($participant, $key)) {
                    continue;
                }
                $row = ($exam->laboratorium ?? [])[$key] ?? [];
                $rows[] = ['key' => $key, 'label' => $item['label'], 'value' => ($row['skipped'] ?? false) ? 'Tidak dilakukan' : ($row['result'] ?? 'Belum diisi'),
                    'unit' => $row['unit'] ?? $item['unit'], 'reference' => $row['reference'] ?? '',
                    'remark' => ($row['skipped'] ?? false) ? ($row['reason'] ?? '') : ($row['flag'] ?? ''),
                    'abnormal' => ! ($row['skipped'] ?? false) && in_array($row['flag'] ?? '', ['Tinggi', 'Rendah', 'Tidak Normal'], true)];
            }
            if ($rows !== []) {
                $laboratory[] = ['name' => $name, 'rows' => $rows];
            }
        }
        if ($laboratory !== []) {
            $sections[] = ['title' => 'HASIL PEMERIKSAAN LABORATORIUM', 'kind' => 'laboratory', 'groups' => $laboratory, 'doctor_id' => $exam->dokter_id, 'show_doctor' => true, 'attachments' => []];
        }

        foreach (McuCatalog::supporting() as $key => $definition) {
            if (! $exam->includes($participant, $key)) {
                continue;
            }
            $data = ($exam->penunjang ?? [])[$key] ?? [];
            $rows = [];
            foreach ($definition['fields'] as $field => $label) {
                $rows[] = ['key' => $field, 'label' => $label, 'value' => ($data['skipped'] ?? false) ? 'Tidak dilakukan — '.($data['reason'] ?? '') : ($data[$field] ?? 'Belum diisi'), 'impression' => $field === 'kesimpulan'];
            }
            $attachments = [];
            if (! ($data['skipped'] ?? false) && ! empty($data['attachment'])) {
                $attachments[] = ['key' => $key, 'label' => $definition['label'], 'path' => $data['attachment'], 'mime' => 'image/jpeg'];
            }
            $sections[] = ['title' => 'HASIL PEMERIKSAAN '.mb_strtoupper($definition['label']), 'kind' => 'detail', 'groups' => [['name' => null, 'rows' => $rows]], 'doctor_id' => $data['doctor_id'] ?? null, 'attachments' => $attachments];
        }

        foreach ($exam->categoryGroups($participant) as $category) {
            $title = in_array(mb_strtoupper($category['name']), ['LAB', 'LABORATORIUM', 'HASIL PEMERIKSAAN LABORATORIUM'], true)
                ? 'HASIL PEMERIKSAAN LABORATORIUM' : $category['name'];
            $isColumns = in_array(mb_strtoupper($title), ['HASIL PEMERIKSAAN FISIK', 'ANAMNESA PASIEN'], true);
            $groups = [];
            $attachments = [];
            $number = 0;
            $hasLaboratory = false;
            foreach (LaboratoryResult::sections($category['items']) as $subsection) {
                $rows = [];
                foreach ($subsection['items'] as $item) {
                    $number++;
                    $evaluation = $item['type'] === 'laboratory' ? $exam->laboratoryEvaluation($item) : null;
                    $hasLaboratory = $hasLaboratory || $evaluation !== null;
                    $rows[] = ['key' => $item['id'], 'number' => $isColumns ? $number : null, 'label' => $item['label'], 'value' => $exam->dynamicReportValue($item),
                        'unit' => $item['lab_config']['unit'] ?? '', 'reference' => $item['lab_config']['reference'] ?? '',
                        'remark' => ($evaluation['status'] ?? null) === 'Tidak dapat dibandingkan' ? 'Tidak dapat dibandingkan' : ($evaluation['remark'] ?? ''),
                        'abnormal' => ($evaluation['status'] ?? null) === 'Tidak normal', 'impression' => in_array(mb_strtolower($item['label']), ['kesan', 'kesimpulan'], true)];
                    $attachment = ($exam->dynamic_attachments ?? [])[$item['id']] ?? null;
                    if (($item['allow_attachment'] ?? false) && $attachment) {
                        $attachments[] = ['key' => 'dynamic-'.$item['id'], 'label' => $item['label'], ...$attachment];
                    }
                }
                $groups[] = ['name' => $subsection['name'], 'column' => $number <= (int) ceil(count($category['items']) / 2) ? 0 : 1, 'rows' => $rows];
            }
            if ($isColumns) {
                $columns = [];
                $midpoint = (int) ceil(count($category['items']) / 2);
                foreach ($groups as $group) {
                    foreach ([0, 1] as $column) {
                        $rows = array_values(array_filter($group['rows'], fn (array $row): bool => ($row['number'] <= $midpoint ? 0 : 1) === $column));
                        if ($rows !== []) {
                            $columns[] = ['name' => $group['name'], 'column' => $column, 'rows' => $rows];
                        }
                    }
                }
                $groups = $columns;
            }
            $sections[] = ['title' => $title, 'kind' => $isColumns ? 'columns' : ($hasLaboratory ? 'laboratory' : 'detail'), 'groups' => $groups,
                'doctor_id' => ($category['has_responsible_doctor'] ?? true) ? (($exam->category_doctors ?? [])[$category['id']] ?? null) : null,
                'show_doctor' => $category['has_responsible_doctor'] ?? true, 'attachments' => $attachments];
        }

        foreach ($sections as &$section) {
            if ($section['kind'] === 'laboratory') {
                $section['groups'] = $this->laboratoryPages($section['groups']);
            }
        }

        return $sections;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function laboratoryPages(array $groups): array
    {
        $pages = [];
        foreach ($groups as $group) {
            $name = mb_strtoupper($group['name'] ?? '');
            $page = match ($name) {
                'HEMATOLOGI', 'HITUNG JENIS LEUKOSIT', 'GLUKOSA' => 0,
                'FAAL HATI', 'FAAL GINJAL', 'PROFIL LIPID', 'ELEKTROLIT', 'SEROLOGI' => 1,
                default => 2,
            };
            if ($name === 'FAAL HATI') {
                $first = array_values(array_filter($group['rows'], fn (array $row): bool => mb_strtoupper($row['label']) === 'SGOT (AST)'));
                $remaining = array_values(array_filter($group['rows'], fn (array $row): bool => mb_strtoupper($row['label']) !== 'SGOT (AST)'));
                if ($first !== []) {
                    $pages[] = [...$group, 'rows' => $first, 'reference_page' => 0];
                }
                if ($remaining !== []) {
                    $pages[] = [...$group, 'name' => $group['name'].($first !== [] ? '' : ''), 'rows' => $remaining, 'reference_page' => 1];
                }
            } else {
                $pages[] = [...$group, 'reference_page' => $page];
            }
        }

        return $pages;
    }

    /** @return list<array{name: string, column: int, rows: list<array{key: string, number: int, label: string, value: mixed, subheading: ?string}>}> */
    private function physical(Karyawan $participant, Anamnesa $exam): array
    {
        $definitions = [
            ['Tinggi Badan & Berat Badan', 0, ['tinggi_badan' => 'Tinggi Badan (cm)', 'berat_badan' => 'Berat Badan (kg)', 'bmi' => 'BMI (kg/m²)', 'kategori_bmi' => 'Kategori BMI']],
            ['Tanda-Tanda Vital', 0, ['tekanan_darah' => 'Tekanan Darah (mmHg)', 'nadi' => 'Nadi (denyut per menit)', 'lingkar_pinggang' => 'Lingkar Pinggang (cm)', 'pernapasan' => 'Pernapasan (napas per menit)', 'suhu_badan' => 'Suhu Badan (°C)']],
            ['Visus Mata', 0, ['visus_od_tanpa_kacamata' => 'Visus Mata (OD)', 'visus_os_tanpa_kacamata' => 'Visus Mata (OS)', 'visus_od_dengan_kacamata' => 'Visus Mata (OD)', 'visus_os_dengan_kacamata' => 'Visus Mata (OS)', 'ishihara_test' => 'Ishihara Test']],
            ['Keadaan Umum', 0, ['kesan_umum' => 'Kesan Umum', 'kulit' => 'Kulit', 'mata' => 'Mata']],
            ['Kepala - Telinga', 0, ['kesan_telinga' => 'Kesan (Telinga)']],
            ['Kepala - Hidung', 0, ['kesan_hidung' => 'Kesan (Hidung)']],
            ['Kepala - Tenggorokan', 0, ['kesan_tenggorokan' => 'Kesan (Tenggorokan)']],
            ['Kepala - Mulut', 1, ['kesan_mulut' => 'Kesan (Mulut)', 'gigi' => 'Gigi']],
            ['Kepala - Leher', 1, ['kesan_leher' => 'Kesan (Leher)']],
            ['Dada - Thorax', 1, ['kesan_thorax' => 'Kesan (Thorax)']],
            ['Dada - Auskultasi', 1, ['ronchi' => 'Ronchi', 'wheezing' => 'Wheezing', 'gallop' => 'Gallop', 'murmur' => 'Murmur']],
            ['Abdomen & Urogenitalia', 1, ['kesan_abdomen' => 'Kesan (Abdomen)', 'hati_lien' => 'Hati / Lien', 'benjolan_massa' => 'Benjolan Massa', 'nyeri_tekan' => 'Nyeri Tekan', 'hernia' => 'Hernia', 'test_cva' => 'Test CVA', 'rectal' => 'Rectal', 'haemorrhoid' => 'Haemorrhoid']],
            ['Ekstremitas', 1, ['ekstremitas_atas' => 'Ekstremitas Atas', 'ekstremitas_bawah' => 'Ekstremitas Bawah', 'range_of_movement' => 'Range of Movement (ROM)']],
            ['Pemeriksaan Neurologis', 1, ['refleks_fisiologis' => 'Refleks Fisiologis', 'refleks_patologis' => 'Refleks Patologis', 'kekuatan_motorik' => 'Kekuatan Motorik', 'kelainan_saraf_pusat' => 'Kelainan Saraf Pusat', 'kelainan_saraf_tepi' => 'Kelainan Saraf Tepi']],
        ];
        $fieldKeys = ['kategori_bmi' => 'bmi'];
        foreach (McuCatalog::physicalFields() as $key => $fields) {
            foreach ($fields as $field) {
                $fieldKeys[$field] = $key;
            }
        }
        $groups = [];
        $number = 0;
        foreach ($definitions as [$name, $column, $fields]) {
            $rows = [];
            foreach ($fields as $field => $label) {
                $number++;
                if ($exam->includes($participant, $fieldKeys[$field] ?? $field)) {
                    $subheading = match ($field) {
                        'visus_od_tanpa_kacamata' => 'Tanpa Kacamata',
                        'visus_od_dengan_kacamata' => 'Dengan Kacamata',
                        'ishihara_test' => 'Buta Warna',
                        default => null,
                    };
                    $rows[] = ['key' => $field, 'number' => $number, 'label' => $label, 'value' => $exam->$field ?? 'Belum diisi', 'subheading' => $subheading];
                }
            }
            if ($rows !== []) {
                $groups[] = ['name' => $name, 'column' => $column, 'rows' => $rows];
            }
        }

        return $groups;
    }
}
