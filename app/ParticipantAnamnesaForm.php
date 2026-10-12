<?php

namespace App;

use App\Models\Anamnesa;
use App\Models\Karyawan;

class ParticipantAnamnesaForm
{
    /** @return array<string, array<string, array{label: string, type: string}>> */
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

        return $groups;
    }
}
