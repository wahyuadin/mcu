<?php

namespace Database\Seeders;

use App\Models\McuCategory;
use App\Models\McuItem;
use App\Models\McuSubcategory;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class McuTemplateMasterSeeder extends Seeder
{
    /** @var list<string> */
    private array $itemIds = [];

    /** @var array<string, int> */
    private array $itemOrders = [];

    public function run(): void
    {
        $this->itemIds = [];
        $this->itemOrders = [];
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTMLFile(public_path('template/hasil-mcu-nayaka.html'), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('Template hasil MCU Nayaka tidak dapat dibaca.');
        }
        $xpath = new DOMXPath($document);
        $pages = $xpath->query('//article[position() > 2]');
        if (! $pages || $pages->length !== 7) {
            throw new RuntimeException('Struktur template hasil MCU Nayaka tidak sesuai.');
        }

        DB::transaction(function () use ($xpath, $pages): void {
            $categoryIds = [];
            $subcategoryIds = [];
            $subcategoryOrders = [];
            foreach ($pages as $page) {
                $name = trim($xpath->query('.//h2', $page)->item(0)?->textContent ?? '');
                if ($name === '') {
                    throw new RuntimeException('Kategori pemeriksaan tidak ditemukan dalam template.');
                }
                if (in_array($name, ['HASIL PEMERIKSAAN FISIK', 'ANAMNESA PASIEN'], true)) {
                    continue;
                }
                $categoryId = $this->identifier('category/'.$name);
                if (! in_array($categoryId, $categoryIds, true)) {
                    $categoryIds[] = $categoryId;
                }
                $category = McuCategory::updateOrCreate(['id' => $categoryId], [
                    'name' => $name === 'HASIL PEMERIKSAAN LABORATORIUM' ? 'LAB' : $name,
                    'sort_order' => array_search($categoryId, $categoryIds, true) + 1, 'is_active' => true, 'has_responsible_doctor' => true,
                ]);
                $subcategoryOrders[$categoryId] ??= [];
                foreach ($xpath->query('.//section', $page) as $section) {
                    $subcategoryName = trim($xpath->query('.//h3', $section)->item(0)?->textContent ?? '');
                    $subcategoryName = str_replace(' (LANJUTAN)', '', $subcategoryName);
                    $subcategoryId = $this->identifier('subcategory/'.$name.'/'.$subcategoryName);
                    if (! in_array($subcategoryId, $subcategoryOrders[$categoryId], true)) {
                        $subcategoryOrders[$categoryId][] = $subcategoryId;
                        $subcategoryIds[] = $subcategoryId;
                    }
                    $subcategory = McuSubcategory::updateOrCreate(['id' => $subcategoryId], [
                        'mcu_category_id' => $category->id, 'name' => $subcategoryName,
                        'sort_order' => array_search($subcategoryId, $subcategoryOrders[$categoryId], true) + 1, 'is_active' => true,
                    ]);
                    foreach ($xpath->query('.//tbody/tr', $section) as $row) {
                        $cells = $xpath->query('./td', $row);
                        $label = trim($cells->item(0)?->textContent ?? '');
                        if ($cells->length !== 5) {
                            throw new RuntimeException('Struktur tabel laboratorium dalam template MCU tidak sesuai.');
                        }
                        $config = $this->laboratoryConfig(trim($cells->item(2)->textContent), trim($cells->item(3)->textContent));
                        $this->seedItem($category, $subcategory, $label, $config['result_type'], $config);
                    }
                }
                foreach ($xpath->query('.//dl[contains(@class, "detail-list")]/div/dt', $page) as $label) {
                    $label = trim($label->textContent);
                    $this->seedItem($category, null, $label, $label === 'Heart Rate' ? 'number' : 'text', null, in_array($label, ['Kesan', 'Kesimpulan'], true));
                }
            }
            if (count($categoryIds) !== 3 || count($subcategoryIds) !== 11 || count($this->itemIds) !== 63) {
                throw new RuntimeException('Isi template MCU tidak sesuai dengan 3 kategori, 11 subkategori, dan 63 item dinamis yang diharapkan.');
            }

            $protectedCategories = McuCategory::whereIn('legacy_group', ['anamnesa_riwayat', 'pemeriksaan_fisik'])->pluck('id');
            DB::table('mcu_items')->whereNotIn('mcu_category_id', $protectedCategories)->whereNotIn('id', $this->itemIds)->delete();
            DB::table('mcu_subcategories')->whereNotIn('mcu_category_id', $protectedCategories)->whereNotIn('id', $subcategoryIds)->delete();
            DB::table('mcu_categories')->whereNotIn('id', $protectedCategories)->whereNotIn('id', $categoryIds)->delete();
        });
    }

    private function identifier(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'nayaka-mcu/template/v1/'.$key)->toString();
    }

    /** @param array<string, mixed>|null $config */
    private function seedItem(McuCategory $category, ?McuSubcategory $subcategory, string $label, string $type, ?array $config = null, bool $attachment = false): void
    {
        $this->itemOrders[$category->id] ??= 0;
        $id = $this->identifier('item/'.$category->id.'/'.($subcategory?->id ?? '').'/'.$label);
        if (in_array($id, $this->itemIds, true)) {
            throw new RuntimeException('Item duplikat dalam template MCU: '.$label);
        }
        $this->itemIds[] = $id;
        McuItem::updateOrCreate(['id' => $id], [
            'mcu_category_id' => $category->id, 'mcu_subcategory_id' => $subcategory?->id, 'label' => $label, 'input_type' => $type,
            'lab_config' => $config, 'yes_report' => $type === 'radio' ? 'Ya' : null, 'no_report' => $type === 'radio' ? 'Tidak' : null,
            'help_text' => null, 'is_required' => true, 'is_active' => true, 'sort_order' => ++$this->itemOrders[$category->id], 'allow_attachment' => $attachment,
        ]);
    }

    /** @return array<string, mixed> */
    private function laboratoryConfig(string $unit, string $reference): array
    {
        $config = ['result_type' => 'text', 'rule' => 'text', 'unit' => $unit, 'reference' => $reference,
            'min' => null, 'max' => null, 'normal_values' => [$reference], 'normal_report' => '', 'abnormal_report' => 'Tidak Normal'];
        if (preg_match('/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)$/', $reference, $matches)) {
            $config = array_replace($config, ['result_type' => 'number', 'rule' => 'range', 'min' => (float) $matches[1], 'max' => (float) $matches[2], 'normal_values' => []]);
        } elseif (preg_match('/^(>=|<=|>|<)\s*(\d+(?:\.\d+)?)$/', $reference, $matches)) {
            $rule = ['>=' => 'gte', '<=' => 'lte', '>' => 'gt', '<' => 'lt'][$matches[1]];
            $config = array_replace($config, ['result_type' => 'number', 'rule' => $rule, str_starts_with($matches[1], '>') ? 'min' : 'max' => (float) $matches[2], 'normal_values' => []]);
        }

        return $config;
    }
}
