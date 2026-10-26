<?php

namespace App\Models;

use App\LaboratoryResult;
use App\McuCatalog;
use App\McuCategoryPresentation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Anamnesa extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'anamnesas';

    protected $fillable = [
        'anamnesa_data', 'laboratorium', 'penunjang', 'section_status', 'revisions', 'package_items',
        'dynamic_schema', 'dynamic_answers', 'dynamic_notes', 'dynamic_attachments', 'category_doctors',
        'dokter_fisik_id', 'finalized_at', 'finalized_by', 'lock_version',
        'karyawan_id',
        'rencana_kunjungan_id',
        'dokter_id',
        'header_form',
        'sub_header',
        'keluhan_saat_ini',
        'riwayat_penyakit_dahulu',
        'riwayat_penyakit_keluarga',
        'riwayat_kebiasaan',
        'tinggi_badan',
        'berat_badan',
        'bmi',
        'kategori_bmi',
        'tekanan_darah',
        'nadi',
        'lingkar_pinggang',
        'pernapasan',
        'suhu_badan',
        'visus_od_tanpa_kacamata',
        'visus_os_tanpa_kacamata',
        'visus_od_dengan_kacamata',
        'visus_os_dengan_kacamata',
        'ishihara_test',
        'kesan_umum',
        'kulit',
        'mata',
        'kesan_telinga',
        'kesan_hidung',
        'kesan_tenggorokan',
        'kesan_mulut',
        'gigi',
        'kesan_leher',
        'kesan_thorax',
        'ronchi',
        'wheezing',
        'gallop',
        'murmur',
        'kesan_abdomen',
        'hati_lien',
        'benjolan_massa',
        'nyeri_tekan',
        'hernia',
        'test_cva',
        'rectal',
        'haemorrhoid',
        'ekstremitas_atas',
        'ekstremitas_bawah',
        'range_of_movement',
        'refleks_fisiologis',
        'refleks_patologis',
        'kekuatan_motorik',
        'kelainan_saraf_pusat',
        'kelainan_saraf_tepi',
        'catatan_dokter',
        'anjuran_saran',
        'status_kelaikan',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'anamnesa_data' => 'array', 'laboratorium' => 'array', 'penunjang' => 'array',
            'section_status' => 'array', 'revisions' => 'array', 'package_items' => 'array',
            'dynamic_schema' => 'array', 'dynamic_answers' => 'array', 'dynamic_notes' => 'array', 'dynamic_attachments' => 'array', 'category_doctors' => 'array',
            'finalized_at' => 'datetime', 'lock_version' => 'integer',
            'riwayat_penyakit_dahulu' => 'array',
            'riwayat_penyakit_keluarga' => 'array',
            'riwayat_kebiasaan' => 'array',
            'tinggi_badan' => 'decimal:2',
            'berat_badan' => 'decimal:2',
            'bmi' => 'decimal:2',
            'lingkar_pinggang' => 'decimal:2',
            'suhu_badan' => 'decimal:2',
            'nadi' => 'integer',
            'pernapasan' => 'integer',
        ];
    }

    public function includes(Karyawan $participant, string $key): bool
    {
        return (($this->package_items ?? $participant->paket->active_items ?? [])[$key] ?? false) === true;
    }

    /** @return list<array{id: string, name: string, order: int, items: list<array<string, mixed>>}> */
    public function dynamicGroups(Karyawan $participant): array
    {
        return $this->dynamic_schema ?? ($this->exists ? [] : ($participant->paket->dynamic_schema ?? []));
    }

    /** @return array<string, array<string, mixed>> */
    public function categoryGroups(Karyawan $participant): array
    {
        return collect($this->dynamicGroups($participant))->filter(fn (array $group): bool => $group['items'] !== [] && ! in_array($this->groupSection($group), ['anamnesa', 'fisik'], true))
            ->map(function (array $group): array {
                $master = app(McuCategoryPresentation::class)->categories()->firstWhere('id', $group['id']);

                return array_replace($group, ['name' => $master?->name ?? $group['name'], 'order' => $master?->sort_order ?? $group['order']]);
            })
            ->sortBy('order')->keyBy(fn (array $group): string => 'kategori-'.$group['id'])->all();
    }

    /** @return list<array<string, mixed>> */
    public function anamnesisGroups(Karyawan $participant): array
    {
        return array_map($this->displayGroup(...), array_values(array_filter($this->dynamicGroups($participant), fn (array $group): bool => $this->groupSection($group) === 'anamnesa' && $group['items'] !== [])));
    }

    /** @return list<array<string, mixed>> */
    public function physicalMasterGroups(Karyawan $participant): array
    {
        return array_map($this->displayGroup(...), array_values(array_filter($this->dynamicGroups($participant), fn (array $group): bool => $this->groupSection($group) === 'fisik' && $group['items'] !== [])));
    }

    /** @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function displayGroup(array $group): array
    {
        $master = app(McuCategoryPresentation::class)->categories()->firstWhere('id', $group['id']);

        return array_replace($group, ['name' => $master?->name ?? $group['name']]);
    }

    /** @param array<string, mixed> $group */
    public function groupSection(array $group): string
    {
        if (in_array($group['section'] ?? null, ['anamnesa', 'fisik'], true)) {
            return $group['section'];
        }
        $master = app(McuCategoryPresentation::class)->categories()->firstWhere('id', $group['id']);

        return $master?->isAnamnesis() ? 'anamnesa' : ($master?->isPhysical() ? 'fisik' : ($group['section'] ?? 'kategori-'.$group['id']));
    }

    public function physicalMeasurement(Karyawan $participant, string $key): mixed
    {
        foreach ($this->physicalMasterGroups($participant) as $group) {
            foreach ($group['items'] as $item) {
                if (($item['physical_key'] ?? null) === $key) {
                    return ($this->dynamic_answers ?? [])[$item['id']] ?? null;
                }
            }
        }

        return $this->includes($participant, $key) ? $this->$key : null;
    }

    public function sectionHasDoctor(Karyawan $participant, string $section): bool
    {
        $groups = array_filter($this->dynamicGroups($participant), fn (array $group): bool => $this->groupSection($group) === $section);

        return $groups === [] || collect($groups)->contains(fn (array $group): bool => $group['has_responsible_doctor'] ?? true);
    }

    /** @return array<string, array{label: string, status: string, missing: list<string>, included: bool}> */
    public function categoryProgress(Karyawan $participant): array
    {
        $sections = [];
        foreach ($this->categoryGroups($participant) as $key => $group) {
            $missing = [];
            $legacyStates = [];
            foreach ($group['items'] as $item) {
                if ($item['required'] && blank(($this->dynamic_answers ?? [])[$item['id']] ?? null)) {
                    $missing[] = $group['name'].' — '.$item['label'];
                } elseif ($item['type'] === 'laboratory' && $this->laboratoryEvaluation($item)['status'] === 'Tidak dapat dibandingkan') {
                    $missing[] = $group['name'].' — '.$item['label'].' (hasil tidak dapat dibandingkan)';
                }
                $legacyStates[] = ($this->section_status ?? [])[$item['type'] === 'laboratory' ? 'laboratorium' : 'dinamis'] ?? null;
            }
            $state = ($this->section_status ?? [])[$key] ?? null;
            if (! array_key_exists($key, $this->section_status ?? [])) {
                if (count(array_filter($legacyStates, fn (?string $state): bool => $state === 'selesai')) === count($legacyStates)) {
                    $state = 'selesai';
                } elseif (array_filter($legacyStates) !== []) {
                    $state = 'draft';
                }
            }
            $sections[$key] = ['label' => $group['name'], 'included' => true, 'missing' => $missing,
                'status' => $state === 'selesai' && $missing === [] ? 'Selesai' : ($state !== null ? 'Draft' : 'Belum diisi')];
        }

        return $sections;
    }

    /** @return array<string, array{label: string, status: string, missing: list<string>, included: bool}> */
    public function workflowProgress(Karyawan $participant): array
    {
        $sections = $this->progress($participant, false);
        unset($sections['dinamis']);
        $conclusion = $sections['kesimpulan'];
        unset($sections['kesimpulan']);

        return app(McuCategoryPresentation::class)->arrange($sections + $this->categoryProgress($participant)) + ['kesimpulan' => $conclusion];
    }

    /** @param array<string, mixed> $item */
    public function dynamicReportValue(array $item): string
    {
        if ($item['type'] === 'label') {
            return $item['help_text'] ?? '';
        }
        $answer = ($this->dynamic_answers ?? [])[$item['id']] ?? null;
        if ($answer === null || $answer === '') {
            return 'Belum diisi';
        }
        $value = (string) $answer;
        if ($item['type'] === 'radio') {
            $value = match ($answer) {
                'YA' => $item['yes_report'], 'TIDAK' => $item['no_report'], default => 'Belum diisi',
            };
        }

        $note = ($this->dynamic_notes ?? [])[$item['id']] ?? null;

        return $value.(filled($note) && ($item['type'] !== 'radio' || $answer === 'YA') ? ' — '.$note : '');
    }

    /** @param array<string, mixed> $item
     * @return array{status: string, remark: string}
     */
    public function laboratoryEvaluation(array $item): array
    {
        return LaboratoryResult::evaluate($item['lab_config'] ?? [], ($this->dynamic_answers ?? [])[$item['id']] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function dynamicGroupsForSection(Karyawan $participant, string $section): array
    {
        $groups = [];
        foreach ($this->dynamicGroups($participant) as $group) {
            if (in_array($this->groupSection($group), ['anamnesa', 'fisik'], true)) {
                if ($section === $this->groupSection($group)) {
                    $groups[] = $group;
                }

                continue;
            }
            if (in_array($section, ['anamnesa', 'fisik'], true)) {
                continue;
            }
            $group['items'] = array_values(array_filter($group['items'], fn (array $item): bool => ($item['type'] === 'laboratory') === ($section === 'laboratorium')));
            if ($group['items'] !== []) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** @return array{answer: ?string, note: ?string} */
    public function historyValue(string $key): array
    {
        if (array_key_exists($key, $this->anamnesa_data ?? [])) {
            return array_replace(['answer' => null, 'note' => null], $this->anamnesa_data[$key]);
        }
        if ($key === 'keluhan_saat_ini') {
            return ['answer' => $this->keluhan_saat_ini, 'note' => null];
        }
        $familyKeys = ['keluarga_hipertensi' => 'hipertensi', 'keluarga_diabetes' => 'diabetes_melitus', 'keluarga_hepatitis' => 'hepatitis_b', 'keluarga_kanker' => 'kanker', 'keluarga_jantung' => 'jantung', 'keluarga_asma' => 'asma', 'keluarga_tbc' => 'tbc'];
        $habitKeys = ['kebiasaan_merokok' => 'merokok', 'kebiasaan_alkohol' => 'alkohol', 'olahraga' => 'olahraga', 'kopi' => 'kopi', 'obat_rutin' => 'obat_rutin', 'kacamata' => 'kacamata'];
        $old = match (true) {
            isset($familyKeys[$key]) => ($this->riwayat_penyakit_keluarga ?? [])[$familyKeys[$key]] ?? null,
            isset($habitKeys[$key]) && array_key_exists($habitKeys[$key], $this->riwayat_kebiasaan ?? []) => $this->riwayat_kebiasaan[$habitKeys[$key]],
            default => ($this->riwayat_penyakit_dahulu ?? [])[$key] ?? null,
        };

        return ['answer' => $old === null ? null : (is_bool($old) || is_int($old) ? ($old ? 'Ya' : 'Tidak') : (string) $old), 'note' => null];
    }

    /** @return array<string, array{label: string, status: string, missing: list<string>, included: bool}> */
    public function progress(Karyawan $participant, bool $includeDynamic = true): array
    {
        $sections = [];
        foreach (['anamnesa' => 'Anamnesa', 'fisik' => 'Pemeriksaan fisik', 'laboratorium' => 'Laboratorium', 'penunjang' => 'Penunjang', 'dinamis' => 'Pemeriksaan sesuai mapping paket', 'kesimpulan' => 'Kesimpulan'] as $key => $label) {
            $sections[$key] = ['label' => $label, 'status' => 'Belum diisi', 'missing' => [], 'included' => false];
        }
        foreach (Paket::getDefaultItemCatalog()['anamnesa_riwayat'] as $key => $label) {
            if ($this->includes($participant, $key)) {
                $sections['anamnesa']['included'] = true;
                if (blank($this->historyValue($key)['answer'])) {
                    $sections['anamnesa']['missing'][] = $label;
                }
            }
        }
        foreach (McuCatalog::physical() as $items) {
            foreach ($items as $key => $label) {
                if (! $this->includes($participant, $key)) {
                    continue;
                }
                $sections['fisik']['included'] = true;
                foreach (McuCatalog::physicalFields()[$key] ?? [$key] as $field) {
                    if (blank($this->{$field})) {
                        $sections['fisik']['missing'][] = $label.' ('.$field.')';
                    }
                }
            }
        }
        foreach (McuCatalog::laboratory() as $items) {
            foreach ($items as $key => $item) {
                if (! $this->includes($participant, $key)) {
                    continue;
                }
                $sections['laboratorium']['included'] = true;
                $row = ($this->laboratorium ?? [])[$key] ?? [];
                if (($row['skipped'] ?? false) && filled($row['reason'] ?? null)) {
                    continue;
                }
                if (blank($row['result'] ?? null) || blank($row['reference'] ?? null) || blank($row['flag'] ?? null) || ($item['unit'] !== '' && blank($row['unit'] ?? null))) {
                    $sections['laboratorium']['missing'][] = $item['label'].' (hasil, satuan, rujukan, dan penanda)';
                }
            }
        }
        foreach ($this->supportingProgress($participant) as $item) {
            $sections['penunjang']['included'] = true;
            $sections['penunjang']['missing'] = [...$sections['penunjang']['missing'], ...$item['missing']];
        }
        foreach ($this->dynamicGroups($participant) as $group) {
            $builtinSection = in_array($this->groupSection($group), ['anamnesa', 'fisik'], true) ? $this->groupSection($group) : null;
            if (! $includeDynamic && ! $builtinSection) {
                continue;
            }
            foreach ($group['items'] as $item) {
                $section = $builtinSection ?? ($item['type'] === 'laboratory' ? 'laboratorium' : 'dinamis');
                $sections[$section]['included'] = true;
                if ($item['required'] && blank(($this->dynamic_answers ?? [])[$item['id']] ?? null)) {
                    $sections[$section]['missing'][] = $group['name'].' — '.$item['label'];
                } elseif ($item['type'] === 'laboratory' && $this->laboratoryEvaluation($item)['status'] === 'Tidak dapat dibandingkan') {
                    $sections[$section]['missing'][] = $group['name'].' — '.$item['label'].' (hasil tidak dapat dibandingkan)';
                }
            }
        }
        if ($sections['fisik']['included'] && $this->sectionHasDoctor($participant, 'fisik') && ! $this->dokter_fisik_id) {
            $sections['fisik']['missing'][] = 'Dokter pemeriksa fisik';
        }
        $sections['kesimpulan']['included'] = true;
        foreach (['catatan_dokter' => 'Kesimpulan medis', 'anjuran_saran' => 'Saran', 'status_kelaikan' => 'Status kelayakan', 'dokter_id' => 'Dokter penanggung jawab'] as $field => $label) {
            if (blank($this->{$field})) {
                $sections['kesimpulan']['missing'][] = $label;
            }
        }
        foreach ($sections as $key => &$section) {
            $section['status'] = ! $section['included'] ? 'Tidak termasuk paket' : (($this->section_status[$key] ?? null) === 'selesai' && $section['missing'] === [] ? 'Selesai' : (isset($this->section_status[$key]) ? 'Draft' : 'Belum diisi'));
        }

        return $sections;
    }

    /** @return array<string, array{label: string, status: string, missing: list<string>, skipped: bool}> */
    public function supportingProgress(Karyawan $participant): array
    {
        $items = [];
        foreach (McuCatalog::supporting() as $key => $definition) {
            if (! $this->includes($participant, $key)) {
                continue;
            }
            $row = ($this->penunjang ?? [])[$key] ?? [];
            $skipped = ($row['skipped'] ?? false) && filled($row['reason'] ?? null);
            $missing = [];
            if (! $skipped) {
                foreach ($definition['fields'] as $field => $label) {
                    if (blank($row[$field] ?? null)) {
                        $missing[] = $definition['label'].' — '.$label;
                    }
                }
                if (blank($row['doctor_id'] ?? null)) {
                    $missing[] = $definition['label'].' — dokter pemeriksa';
                }
            }
            $items[$key] = [
                'label' => $definition['label'], 'missing' => $missing, 'skipped' => $skipped,
                'status' => $missing === [] && ($this->section_status['penunjang'] ?? null) === 'selesai'
                    ? 'Selesai' : ($row !== [] ? 'Draft' : 'Belum diisi'),
            ];
        }

        return $items;
    }

    /** @return array{completed: int, total: int, percent: int} */
    public function examinationCompletion(Karyawan $participant): array
    {
        $statuses = [$this->identityProgress($participant)['status']];
        foreach ($this->workflowProgress($participant) as $key => $section) {
            if ((in_array($key, ['fisik', 'anamnesa', 'laboratorium'], true) || str_starts_with($key, 'kategori-')) && $section['included']) {
                $statuses[] = $section['status'];
            }
        }
        foreach ($this->supportingProgress($participant) as $item) {
            $statuses[] = $item['status'];
        }
        $completed = count(array_filter($statuses, fn (string $status): bool => $status === 'Selesai'));
        $total = count($statuses);

        return ['completed' => $completed, 'total' => $total, 'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0];
    }

    /** @return array{status: string, missing: list<string>} */
    public function identityProgress(Karyawan $participant): array
    {
        $missing = [];
        foreach (['tanggal_lahir' => 'Tanggal lahir', 'nip' => 'NIK/NIP karyawan', 'bagian' => 'Departemen', 'tanggal_mcu' => 'Tanggal MCU'] as $field => $label) {
            if (blank($participant->{$field})) {
                $missing[] = $label;
            }
        }
        if (! $participant->hasStoredPhoto()) {
            $missing[] = 'Foto pasien';
        }

        return ['status' => $missing === [] ? 'Selesai' : 'Draft', 'missing' => $missing];
    }

    /** @return list<string> */
    public function finalizationErrors(Karyawan $participant): array
    {
        $errors = [];
        foreach (['tanggal_lahir' => 'Tanggal lahir', 'nip' => 'NIK/NIP karyawan', 'bagian' => 'Departemen', 'tanggal_mcu' => 'Tanggal MCU'] as $field => $label) {
            if (blank($participant->{$field})) {
                $errors[] = $label.' belum diisi.';
            }
        }
        foreach ($this->workflowProgress($participant) as $section) {
            if ($section['included'] && $section['status'] !== 'Selesai') {
                $errors[] = $section['label'].' belum selesai'.($section['missing'] ? ': '.implode(', ', $section['missing']) : '. Tandai bagian selesai setelah diperiksa.');
            }
        }

        return $errors;
    }

    public function dokterFisik(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_fisik_id');
    }

    /**
     * Hitung Nilai BMI & Kategori BMI otomatis dari Tinggi (cm) dan Berat (kg)
     */
    public static function calculateBmi(?float $tinggiCm, ?float $beratKg): array
    {
        if (! $tinggiCm || ! $beratKg || $tinggiCm <= 0 || $beratKg <= 0) {
            return [
                'bmi' => null,
                'kategori' => null,
            ];
        }

        $tinggiMeter = $tinggiCm / 100;
        $bmi = round($beratKg / ($tinggiMeter * $tinggiMeter), 2);

        $kategori = match (true) {
            $bmi < 18.5 => 'Berat Badan Kurang (Underweight)',
            $bmi < 23 => 'Normal',
            $bmi < 25 => 'Kelebihan Berat Badan (Overweight)',
            $bmi < 30 => 'Obesitas I',
            default => 'Obesitas II',
        };

        return [
            'bmi' => $bmi,
            'kategori' => $kategori,
        ];
    }

    /**
     * Relasi ke Karyawan
     */
    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_id');
    }

    /**
     * Relasi ke Rencana Kunjungan
     */
    public function rencanaKunjungan(): BelongsTo
    {
        return $this->belongsTo(RencanaKunjungan::class, 'rencana_kunjungan_id');
    }

    /**
     * Relasi ke Dokter Pemeriksa
     */
    public function dokter(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_id');
    }
}
