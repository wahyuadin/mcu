<?php

namespace App;

use App\Models\Anamnesa;
use App\Models\Karyawan;

class ParticipantAnamnesaForm
{
    /** @return array<string, array<string, array{label: string, type: string, required?: bool, default_answer?: ?string}>> */
    public static function groups(Karyawan $participant): array
    {
        $exam = $participant->anamnesa ?? new Anamnesa;
        $groups = [];
        foreach (McuCatalog::anamnesa() + ['Riwayat tambahan' => ['asma' => ['label' => 'Asma / alergi', 'type' => 'choice'], 'ginjal' => ['label' => 'Penyakit ginjal', 'type' => 'choice']]] as $label => $items) {
            $items = array_filter($items, fn (array $item, string $key): bool => $exam->includes($participant, $key), ARRAY_FILTER_USE_BOTH);
            if ($items !== []) {
                $groups[$label] = $items;
            }
        }

        foreach ($exam->anamnesisGroups($participant) as $group) {
            foreach ($group['items'] as $item) {
                if ($item['type'] === 'label') {
                    continue;
                }
                $name = $item['subcategory']['name'] ?? $group['name'];
                $groups[$name][$item['id']] = ['label' => $item['label'], 'type' => $item['type'] === 'radio' ? 'choice' : ($item['type'] === 'number' ? 'number' : 'text'), 'required' => $item['required'], 'default_answer' => match ($item['default_answer'] ?? null) {
                    'YA' => 'Ya', 'TIDAK' => 'Tidak', default => null
                }];
            }
        }

        return $groups;
    }
}
