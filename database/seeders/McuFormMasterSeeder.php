<?php

namespace Database\Seeders;

use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\Paket;
use Illuminate\Database\Seeder;

class McuFormMasterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (Paket::getDefaultItemCatalog() as $group => $items) {
            if (in_array($group, ['anamnesa_riwayat', 'antropometri_vital'], true)) {
                continue;
            }
            $category = McuCategory::firstOrNew(['legacy_group' => $group]);
            if (! $category->exists) {
                $category->fill(['name' => ucwords(str_replace('_', ' ', $group)), 'sort_order' => 100, 'is_active' => true])->save();
            }
            foreach ($items as $key => $label) {
                $item = McuItem::firstOrNew(['legacy_key' => $key]);
                if (! $item->exists) {
                    $item->fill(['mcu_category_id' => $category->id, 'label' => $label, 'input_type' => 'existing', 'is_required' => true, 'is_active' => true, 'sort_order' => 100])->save();
                }
            }
        }
    }
}
