<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Attribute definitions live on categories as plain arrays:
 * {key, type: text|number|choice, unit, labels: {cs, de, en}, filterable, options: [{value, labels}]}.
 * Values on products and variants are always strings, so SQL filters compare text.
 */
final class AttributeSchema
{
    public const TYPES = ['text', 'number', 'choice'];
    private const LOCALES = ['cs', 'de', 'en'];

    /**
     * Cleans definitions coming from the admin form or the seed file.
     *
     * @param list<array<string, mixed>> $definitions
     * @param list<string> $inheritedKeys keys already defined by ancestors
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeDefinitions(array $definitions, array $inheritedKeys = []): array
    {
        $clean = [];
        $seen = array_fill_keys($inheritedKeys, true);
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new \InvalidArgumentException('Each attribute definition must be an object');
            }
            $key = trim((string) ($definition['key'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key)) {
                throw new \InvalidArgumentException(sprintf('Attribute key "%s" must be lowercase letters, numbers or underscores', $key));
            }
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(sprintf('Attribute key "%s" is already defined here or in a parent category', $key));
            }
            $seen[$key] = true;
            $type = $definition['type'] ?? '';
            if (!in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException(sprintf('Attribute "%s" has an unknown type', $key));
            }
            $labels = self::labels($definition, $key);
            if (in_array('', $labels, true)) {
                throw new \InvalidArgumentException(sprintf('Attribute "%s" needs a label in every language', $key));
            }
            $unit = trim((string) ($definition['unit'] ?? ''));
            $options = [];
            if ($type === 'choice') {
                foreach ($definition['options'] ?? [] as $option) {
                    $value = trim((string) (is_array($option) ? ($option['value'] ?? '') : $option));
                    if ($value === '' || mb_strlen($value) > 80) {
                        throw new \InvalidArgumentException(sprintf('Attribute "%s" has an empty or too long option', $key));
                    }
                    if (isset($options[$value])) {
                        throw new \InvalidArgumentException(sprintf('Attribute "%s" repeats option "%s"', $key, $value));
                    }
                    $optionLabels = is_array($option) ? self::labels($option, $value) : [];
                    $options[$value] = ['value' => $value, 'labels' => array_filter($optionLabels, static fn (string $label): bool => $label !== '')];
                }
                if ($options === []) {
                    throw new \InvalidArgumentException(sprintf('Choice attribute "%s" needs at least one option', $key));
                }
            }
            $clean[] = [
                'key' => $key,
                'type' => $type,
                'unit' => $unit === '' ? null : mb_substr($unit, 0, 12),
                'labels' => $labels,
                'filterable' => (bool) ($definition['filterable'] ?? false),
                'options' => array_values($options),
            ];
        }

        return $clean;
    }

    /**
     * Checks values against the effective definitions and returns them trimmed, empty values dropped.
     *
     * @param array<string, mixed> $values
     * @param list<array<string, mixed>> $definitions
     *
     * @return array<string, string>
     */
    public static function normalizeValues(array $values, array $definitions): array
    {
        $byKey = array_column($definitions, null, 'key');
        $clean = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !isset($byKey[$key])) {
                throw new \InvalidArgumentException(sprintf('Attribute "%s" is not defined for this category', $key));
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException(sprintf('Attribute "%s" must be a single value', $key));
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 80) {
                throw new \InvalidArgumentException(sprintf('Attribute "%s" is longer than 80 characters', $key));
            }
            $definition = $byKey[$key];
            if ($definition['type'] === 'number') {
                $value = str_replace(',', '.', $value);
                if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
                    throw new \InvalidArgumentException(sprintf('Attribute "%s" must be a number', $key));
                }
                // 40.0 and 40 must filter as the same value
                $value = rtrim(rtrim(str_contains($value, '.') ? $value : $value.'.', '0'), '.');
                $value = $value === '' ? '0' : $value;
            }
            if ($definition['type'] === 'choice' && !in_array($value, array_column($definition['options'], 'value'), true)) {
                throw new \InvalidArgumentException(sprintf('Attribute "%s" does not allow "%s"', $key, $value));
            }
            $clean[$key] = $value;
        }
        ksort($clean);

        return $clean;
    }

    /**
     * Localized display rows for stored values, in definition order.
     *
     * @param array<string, string> $values
     * @param list<array<string, mixed>> $definitions
     *
     * @return list<array{key: string, label: string, value: string, unit: ?string}>
     */
    public static function specs(array $values, array $definitions, string $locale): array
    {
        $specs = [];
        foreach ($definitions as $definition) {
            $value = $values[$definition['key']] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $specs[] = [
                'key' => $definition['key'],
                'label' => $definition['labels'][$locale],
                'value' => self::valueLabel($definition, (string) $value, $locale),
                'unit' => $definition['unit'],
            ];
        }

        return $specs;
    }

    /**
     * Flat shape used by the admin API, the same one it accepts back.
     *
     * @param list<array<string, mixed>> $definitions
     */
    public static function forAdmin(array $definitions): array
    {
        return array_map(static fn (array $definition): array => [
            'key' => $definition['key'],
            'type' => $definition['type'],
            'unit' => $definition['unit'],
            'labelCs' => $definition['labels']['cs'],
            'labelDe' => $definition['labels']['de'],
            'labelEn' => $definition['labels']['en'],
            'filterable' => $definition['filterable'],
            'options' => array_map(static fn (array $option): array => [
                'value' => $option['value'],
                'labelCs' => $option['labels']['cs'] ?? null,
                'labelDe' => $option['labels']['de'] ?? null,
                'labelEn' => $option['labels']['en'] ?? null,
            ], $definition['options']),
        ], $definitions);
    }

    public static function valueLabel(array $definition, string $value, string $locale): string
    {
        if ($definition['type'] === 'number') {
            // stored with a dot so filters stay stable, shown with a comma where people expect one
            return $locale === 'en' ? $value : str_replace('.', ',', $value);
        }
        foreach ($definition['options'] ?? [] as $option) {
            if ($option['value'] === $value) {
                return $option['labels'][$locale] ?? $value;
            }
        }

        return $value;
    }

    /** @return array{cs: string, de: string, en: string} */
    private static function labels(array $source, string $fallback): array
    {
        $labels = [];
        foreach (self::LOCALES as $locale) {
            // accepts both {labels: {cs: ...}} and flat labelCs from the admin form
            $label = $source['labels'][$locale] ?? $source['label'.ucfirst($locale)] ?? '';
            $labels[$locale] = mb_substr(trim((string) $label), 0, 80);
        }

        return $labels;
    }
}
