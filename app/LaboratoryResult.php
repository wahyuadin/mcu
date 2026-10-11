<?php

namespace App;

class LaboratoryResult
{
    /** @param array<string, mixed> $config
     * @return array{status: string, remark: string}
     */
    public static function evaluate(array $config, mixed $answer): array
    {
        if ($answer === null || (is_string($answer) && trim($answer) === '')) {
            return ['status' => 'Belum diperiksa', 'remark' => ''];
        }
        $normal = null;
        if (($config['result_type'] ?? null) === 'number' && is_scalar($answer) && is_numeric($answer) && is_finite((float) $answer)) {
            $value = (float) $answer;
            $min = $config['min'] ?? null;
            $max = $config['max'] ?? null;
            $normal = match ($config['rule'] ?? null) {
                'range' => is_numeric($min) && is_numeric($max) ? $value >= (float) $min && $value <= (float) $max : null,
                'lt' => is_numeric($max) ? $value < (float) $max : null,
                'lte' => is_numeric($max) ? $value <= (float) $max : null,
                'gt' => is_numeric($min) ? $value > (float) $min : null,
                'gte' => is_numeric($min) ? $value >= (float) $min : null,
                default => null,
            };
        } elseif (($config['result_type'] ?? null) === 'text' && ($config['rule'] ?? null) === 'text' && is_string($answer) && ! empty($config['normal_values'])) {
            $values = array_map(fn (string $value): string => mb_strtolower(trim($value)), $config['normal_values']);
            $normal = in_array(mb_strtolower(trim($answer)), $values, true);
        }
        if ($normal === null) {
            return ['status' => 'Tidak dapat dibandingkan', 'remark' => ''];
        }

        return ['status' => $normal ? 'Normal' : 'Tidak normal', 'remark' => (string) ($config[$normal ? 'normal_report' : 'abnormal_report'] ?? '')];
    }

    /** @param list<array<string, mixed>> $items
     * @return list<array{name: ?string, items: list<array<string, mixed>>}>
     */
    public static function sections(array $items): array
    {
        $groups = collect($items)->groupBy(fn (array $item): string => $item['subcategory']['id'] ?? '');

        return $groups->sortBy(fn ($group): int => $group->first()['subcategory']['order'] ?? -1)
            ->map(fn ($group): array => ['name' => $group->first()['subcategory']['name'] ?? null, 'items' => $group->values()->all()])->values()->all();
    }
}
