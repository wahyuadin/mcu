<?php

namespace App;

use App\Models\McuCategory;
use Illuminate\Database\Eloquent\Collection;

class McuCategoryPresentation
{
    /** @var Collection<int, McuCategory>|null */
    private ?Collection $categories = null;

    public function refresh(): void
    {
        $this->categories = null;
    }

    /** @return Collection<int, McuCategory> */
    public function categories(): Collection
    {
        return $this->categories ??= McuCategory::orderBy('sort_order')->orderBy('name')->get();
    }

    public function category(string $section): ?McuCategory
    {
        $legacy = ['anamnesa' => 'anamnesa_riwayat', 'fisik' => 'pemeriksaan_fisik'][$section] ?? $section;

        return str_starts_with($section, 'kategori-')
            ? $this->categories()->firstWhere('id', substr($section, 9))
            : $this->categories()->firstWhere('legacy_group', $legacy);
    }

    /** @param array<string, mixed> $sections
     * @return array<string, mixed>
     */
    public function arrange(array $sections): array
    {
        $ordered = [];
        foreach ($this->categories() as $category) {
            $key = match ($category->legacy_group) {
                'anamnesa_riwayat' => 'anamnesa',
                'pemeriksaan_fisik' => 'fisik',
                default => isset($sections['kategori-'.$category->id]) ? 'kategori-'.$category->id : $category->legacy_group,
            };
            if ($key !== null && array_key_exists($key, $sections)) {
                $value = $sections[$key];
                if (is_array($value)) {
                    $value['label'] = $category->name;
                } else {
                    $value = $category->name;
                }
                $ordered[$key] = $value;
            }
        }

        return $ordered + $sections;
    }
}
