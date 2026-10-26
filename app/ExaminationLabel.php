<?php

namespace App;

use App\Models\Anamnesa;
use App\Models\Karyawan;
use App\Models\McuCategory;

class ExaminationLabel
{
    public function examinationUrl(Karyawan $participant, string $categoryId, string $subcategoryId): string
    {
        $exam = $participant->anamnesa ?? new Anamnesa;
        $group = collect($exam->dynamicGroups($participant))->firstWhere('id', $categoryId);
        $section = $group['section'] ?? null;
        if (! $group) {
            $master = McuCategory::find($categoryId);
            $section = $master?->isAnamnesis() ? 'anamnesa' : ($master?->isPhysical() ? 'fisik' : null);
        }

        return route('pemeriksaan.edit', [$participant->rencana_kunjungan_id, $participant, 'tab' => in_array($section, ['anamnesa', 'fisik'], true) ? $section : 'kategori-'.$categoryId, 'subcategory' => $subcategoryId]);
    }

    /** @param list<array<string, mixed>> $categories
     * @return array<string, array<string, array{qr: string, code128: string, modules: int}>>
     */
    public function barcodes(Karyawan $participant, array $categories): array
    {
        $images = [];
        foreach ($categories as $category) {
            if (! $category['enabled']) {
                continue;
            }
            foreach ($category['subcategories'] as $subcategory) {
                $images[$category['id']][$subcategory['id']] = (new LabelBarcode)->images($this->examinationUrl($participant, $category['id'], $subcategory['id']));
            }
        }

        return $images;
    }

    /** @return array{tab: string, subcategory: string}|null */
    public function navigationTarget(Karyawan $participant, Anamnesa $exam, mixed $tab, mixed $subcategoryId): ?array
    {
        if (! is_string($tab) || ! is_string($subcategoryId)) {
            return null;
        }
        $groups = isset($exam->categoryGroups($participant)[$tab]) ? [$exam->categoryGroups($participant)[$tab]] : [];
        if (in_array($tab, ['anamnesa', 'fisik'], true)) {
            $groups = $tab === 'anamnesa' ? $exam->anamnesisGroups($participant) : $exam->physicalMasterGroups($participant);
        }
        foreach ($groups as $group) {
            $ids = array_filter(array_map(fn (array $item): ?string => $item['subcategory']['id'] ?? null, $group['items']));
            if (in_array($subcategoryId, $ids, true) || ($ids === [] && $subcategoryId === $group['id'])) {
                return ['tab' => $tab, 'subcategory' => $subcategoryId];
            }
        }
        if ($groups === [] && in_array($tab, ['anamnesa', 'fisik'], true) && $exam->workflowProgress($participant)[$tab]['included']
            && McuCategory::whereKey($subcategoryId)->where('legacy_group', $tab === 'anamnesa' ? 'anamnesa_riwayat' : 'pemeriksaan_fisik')->exists()) {
            return ['tab' => $tab, 'subcategory' => $subcategoryId];
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function categories(Karyawan $participant, Anamnesa $exam): array
    {
        $groups = $exam->dynamicGroups($participant);
        foreach (McuCategory::whereIn('legacy_group', ['anamnesa_riwayat', 'pemeriksaan_fisik'])->get() as $master) {
            $section = $master->isAnamnesis() ? 'anamnesa' : 'fisik';
            if ($exam->workflowProgress($participant)[$section]['included'] && ! in_array($master->id, array_column($groups, 'id'), true)) {
                $groups[] = ['id' => $master->id, 'name' => $master->name, 'section' => $section, 'items' => []];
            }
        }
        $masters = McuCategory::whereIn('id', array_column($groups, 'id'))->get()->keyBy('id');
        $categories = [];
        foreach ($groups as $group) {
            $master = $masters->get($group['id']);
            $subcategories = [];
            foreach ($group['items'] as $item) {
                $subcategory = $item['subcategory'] ?? null;
                if ($subcategory) {
                    $subcategories[$subcategory['id']] = ['id' => $subcategory['id'], 'name' => $subcategory['name'], 'order' => $subcategory['order'] ?? 0];
                }
            }
            uasort($subcategories, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            $categories[] = ['id' => $group['id'], 'name' => $group['name'], 'enabled' => (bool) $master?->label_enabled,
                'type' => $master?->label_barcode_type ?? 'qr', 'width' => $master?->label_width_mm ?? 80, 'height' => $master?->label_height_mm ?? 40,
                'subcategories' => $subcategories ? array_values($subcategories) : [['id' => $group['id'], 'name' => 'Tanpa subkategori', 'order' => 0]]];
        }

        return $categories;
    }
}
