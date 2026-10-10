<?php

namespace App;

use App\Models\Paket;

class McuCatalog
{
    /** @return array<string, array<string, string>> */
    public static function physical(): array
    {
        return array_intersect_key(Paket::getDefaultItemCatalog(), array_flip([
            'antropometri_vital', 'visus_buta_warna', 'keadaan_umum_kepala',
            'thorax_auskultasi', 'abdomen_urogenital', 'ekstremitas_neurologis',
        ]));
    }

    /** @return array<string, array<string, array{label: string, type: string}>> */
    public static function anamnesa(): array
    {
        $groups = [
            'Keluhan' => ['keluhan_saat_ini' => 'Keluhan sekarang'],
            'Riwayat penyakit dahulu' => [
                'hepatitis' => 'Hepatitis', 'hipertensi' => 'Hipertensi', 'diabetes_melitus' => 'Diabetes melitus',
                'jantung' => 'Penyakit jantung', 'riwayat_haemorrhoid' => 'Haemorrhoid', 'kejang' => 'Kejang',
                'riwayat_hernia' => 'Hernia', 'alergi' => 'Alergi', 'tbc' => 'TBC', 'rawat_inap' => 'Rawat inap',
                'operasi' => 'Operasi', 'hiv_aids' => 'HIV/AIDS', 'riwayat_obat' => 'Riwayat obat-obatan',
            ],
            'Hazard lingkungan kerja' => [
                'hazard_bising' => 'Bising', 'hazard_getaran' => 'Getaran', 'hazard_debu' => 'Debu/asap',
                'hazard_kimia' => 'Zat kimia', 'hazard_makanan' => 'Mengelola produksi makanan',
                'hazard_pc' => 'Penggunaan komputer (jam per hari)', 'hazard_dorong' => 'Mendorong/menarik',
                'hazard_beban' => 'Angkat beban tanpa alat >25 kg', 'hazard_kecelakaan' => 'Riwayat kecelakaan kerja',
            ],
            'Riwayat penyakit keluarga' => [
                'keluarga_hipertensi' => 'Hipertensi', 'keluarga_diabetes' => 'Diabetes melitus',
                'keluarga_hepatitis' => 'Hepatitis B', 'keluarga_kanker' => 'Kanker',
                'keluarga_jantung' => 'Penyakit jantung', 'keluarga_asma' => 'Asthma bronkhial', 'keluarga_tbc' => 'Tuberculosis',
            ],
            'Riwayat kebiasaan' => [
                'olahraga' => 'Olahraga (kali per minggu)', 'kebiasaan_merokok' => 'Merokok/rokok elektrik (batang per hari atau rincian penggunaan)',
                'kebiasaan_alkohol' => 'Minuman beralkohol', 'kopi' => 'Minum kopi (gelas per hari)',
                'obat_rutin' => 'Obat yang rutin diminum', 'kacamata' => 'Menggunakan kacamata',
            ],
        ];
        $text = ['keluhan_saat_ini', 'riwayat_obat', 'olahraga', 'kebiasaan_merokok', 'kopi', 'obat_rutin'];
        foreach ($groups as &$items) {
            foreach ($items as $key => $label) {
                $items[$key] = ['label' => $label, 'type' => in_array($key, $text, true) ? 'text' : 'choice'];
            }
        }

        return $groups;
    }

    /** @return array<string, string> */
    public static function anamnesaLabels(): array
    {
        $labels = [];
        foreach (self::anamnesa() as $items) {
            foreach ($items as $key => $item) {
                $labels[$key] = $item['label'];
            }
        }

        return $labels;
    }

    /** @return array<string, array<string, array{label: string, unit: string}>> */
    public static function laboratory(): array
    {
        $groups = [
            'Hematologi' => [
                'hemoglobin' => ['Hemoglobin', 'g/dL'], 'hematokrit' => ['Hematokrit', '%'],
                'eritrosit' => ['Eritrosit', 'juta/µL'], 'leukosit' => ['Leukosit', 'ribu/µL'],
                'trombosit' => ['Trombosit', 'ribu/µL'], 'mcv' => ['MCV', 'fL'], 'mch' => ['MCH', 'pg'],
                'mchc' => ['MCHC', 'g/dL'], 'led' => ['LED', 'mm/jam'],
            ],
            'Hitung jenis leukosit' => [
                'basofil' => ['Basofil', '%'], 'eosinofil' => ['Eosinofil', '%'], 'neutrofil_batang' => ['Neutrofil batang', '%'],
                'neutrofil_segmen' => ['Neutrofil segmen', '%'], 'limfosit' => ['Limfosit', '%'], 'monosit' => ['Monosit', '%'],
            ],
            'Glukosa' => ['glukosa_puasa' => ['Glukosa puasa', 'mg/dL'], 'glukosa_pp' => ['Glukosa 2 jam PP', 'mg/dL'], 'hba1c' => ['HbA1c (NGSP)', '%']],
            'Faal hati' => ['sgot' => ['SGOT (AST)', 'U/L'], 'sgpt' => ['SGPT (ALT)', 'U/L'], 'bilirubin' => ['Bilirubin total', 'mg/dL'], 'albumin' => ['Albumin', 'g/dL']],
            'Faal ginjal' => ['ureum' => ['Ureum', 'mg/dL'], 'kreatinin' => ['Kreatinin', 'mg/dL'], 'asam_urat' => ['Asam urat', 'mg/dL'], 'elfg' => ['eLFG (CKD-EPI)', 'mL/menit/1.73m²']],
            'Profil lipid' => ['kolesterol' => ['Kolesterol total', 'mg/dL'], 'trigliserida' => ['Trigliserida', 'mg/dL'], 'hdl' => ['HDL kolesterol', 'mg/dL'], 'ldl' => ['LDL kolesterol', 'mg/dL']],
            'Elektrolit' => ['natrium' => ['Natrium (Na)', 'mmol/L'], 'kalium' => ['Kalium (K)', 'mmol/L'], 'klorida' => ['Klorida (Cl)', 'mmol/L']],
            'Serologi' => ['hbsag' => ['HBsAg', ''], 'anti_hbs' => ['Anti HBs', 'mIU/mL']],
            'Urinalisa' => [
                'warna_urine' => ['Warna urine', ''], 'kejernihan' => ['Kejernihan', ''], 'berat_jenis' => ['Berat jenis urine', ''],
                'ph_urine' => ['pH urine', ''], 'protein_urine' => ['Protein urine', ''], 'glukosa_urine' => ['Glukosa urine', ''],
                'eritrosit_urine' => ['Eritrosit urine', '/LPB'], 'leukosit_urine' => ['Leukosit urine', '/LPB'],
            ],
            'Drug test' => [
                'amphetamine' => ['Amphetamine (AMP)', ''], 'methamphetamine' => ['Methamphetamine (MET)', ''],
                'thc' => ['Tetrahydrocannabinol (THC)', ''], 'morphine' => ['Morphine/Opiate (MOP)', ''],
                'cocaine' => ['Cocaine (COC)', ''], 'benzodiazepine' => ['Benzodiazepine (BZD)', ''],
            ],
        ];
        $result = [];
        foreach ($groups as $group => $items) {
            foreach ($items as $key => [$label, $unit]) {
                $result[$group]['lab_'.str_replace(' ', '', $key)] = ['label' => $label, 'unit' => $unit];
            }
        }

        return $result;
    }

    /** @return array<string, array{label: string, fields: array<string, string>}> */
    public static function supporting(): array
    {
        return [
            'rontgen' => ['label' => 'Rontgen thoraks', 'fields' => ['jantung' => 'Jantung', 'aorta' => 'Aorta', 'paru' => 'Paru', 'tulang' => 'Tulang & jaringan lunak', 'kesimpulan' => 'Kesan']],
            'ekg' => ['label' => 'EKG', 'fields' => ['irama' => 'Irama', 'heart_rate' => 'Heart rate (x/menit)', 'axis' => 'Axis', 'kelainan' => 'Kelainan', 'kesimpulan' => 'Kesimpulan']],
            'audiometri' => ['label' => 'Audiometri', 'fields' => ['hasil' => 'Rincian hasil', 'kesimpulan' => 'Kesimpulan']],
            'spirometri' => ['label' => 'Spirometri', 'fields' => ['hasil' => 'Rincian hasil', 'kesimpulan' => 'Kesimpulan']],
        ];
    }

    /** @return array<string, list<string>> */
    public static function physicalFields(): array
    {
        return [
            'visus_tanpa_kacamata' => ['visus_od_tanpa_kacamata', 'visus_os_tanpa_kacamata'],
            'visus_dengan_kacamata' => ['visus_od_dengan_kacamata', 'visus_os_dengan_kacamata'],
            'bmi' => ['bmi', 'kategori_bmi'],
        ];
    }

    public static function laboratoryFlag(?string $value, ?string $reference): ?string
    {
        if ($value === null || $reference === null || $value === '' || $reference === '') {
            return null;
        }
        $number = str_replace(',', '.', $value);
        $reference = str_replace(',', '.', trim($reference));
        if (is_numeric($number)) {
            $number = (float) $number;
            if (preg_match('/^([<>]=?)\s*(-?\d+(?:\.\d+)?)$/', $reference, $match)) {
                $limit = (float) $match[2];

                return match ($match[1]) {
                    '<' => $number >= $limit ? 'Tinggi' : 'Normal',
                    '<=' => $number > $limit ? 'Tinggi' : 'Normal',
                    '>' => $number <= $limit ? 'Rendah' : 'Normal',
                    '>=' => $number < $limit ? 'Rendah' : 'Normal',
                };
            }
            if (preg_match('/^(-?\d+(?:\.\d+)?)\s*[-–]\s*(-?\d+(?:\.\d+)?)$/', $reference, $match) && (float) $match[1] <= (float) $match[2]) {
                return $number < (float) $match[1] ? 'Rendah' : ($number > (float) $match[2] ? 'Tinggi' : 'Normal');
            }
        }

        return null;
    }
}
