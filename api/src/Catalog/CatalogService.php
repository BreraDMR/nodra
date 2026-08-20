<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Pricing\AvailabilityService;
use App\Pricing\Sourcing;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class CatalogService
{
    public function __construct(private Connection $db, private AvailabilityService $availability) {}

    public function browse(CatalogQuery $query): array
    {
        $locale = $query->locale;
        $nameSql = $this->nameSql($locale);
        // the money is CZK in every language (D00.5); the euro column only feeds the reference display
        $params = ['locale' => $locale];
        $types = [];
        $where = "p.status = 'published'";
        if ($query->category !== null && $query->category !== '') {
            $index = CategoryIndex::load($this->db);
            $category = $index->bySlug($query->category);
            $ids = $category === null || !$index->isVisible($category['id']) ? [] : $index->subtreeIds($category['id'], true);
            if ($ids === []) {
                return ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
            }
            $where .= ' AND p.category_id IN (:categoryIds)';
            $params['categoryIds'] = $ids;
            $types['categoryIds'] = ArrayParameterType::STRING;
        }
        if ($query->brand !== null && trim($query->brand) !== '') {
            $where .= ' AND p.brand = :brand';
            $params['brand'] = trim($query->brand);
        }
        $filter = 0;
        foreach ($query->attr as $key => $accepted) {
            $values = array_values(array_filter(array_map('trim', explode(',', (string) $accepted)), static fn (string $v): bool => $v !== ''));
            if ($values === []) {
                continue;
            }
            // the variant value wins over the product value, same rule as on the product page
            $where .= " AND EXISTS (SELECT 1 FROM product_variant fv WHERE fv.product_id = p.id AND fv.active = TRUE
                AND COALESCE(fv.attributes ->> :attrKey$filter, p.attributes ->> :attrKey$filter) IN (:attrValues$filter))";
            $params['attrKey'.$filter] = (string) $key;
            $params['attrValues'.$filter] = array_slice($values, 0, 20);
            $types['attrValues'.$filter] = ArrayParameterType::STRING;
            ++$filter;
        }
        if ($query->q !== null && trim($query->q) !== '') {
            $term = trim($query->q);
            $where .= " AND ($nameSql ILIKE :search OR p.slug ILIKE :search OR p.brand ILIKE :search
                OR EXISTS (SELECT 1 FROM product_variant sv WHERE sv.product_id = p.id AND sv.active = TRUE AND (sv.mpn ILIKE :search OR sv.ean = :exact)))";
            $params['search'] = '%'.strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%';
            $params['exact'] = preg_replace('/[\s-]/', '', $term);
        }
        if ($query->availableOnly) {
            // "orderable" comes from the same calculation as the cards, so it's worked out in PHP for the candidates
            $candidates = $this->db->fetchFirstColumn("SELECT p.id FROM product p WHERE $where", array_diff_key($params, ['locale' => true]), $types);
            $orderable = $this->availability->orderableProducts($candidates);
            if ($orderable === []) {
                return ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
            }
            $where .= ' AND p.id IN (:orderableIds)';
            $params['orderableIds'] = $orderable;
            $types['orderableIds'] = ArrayParameterType::STRING;
        }

        $sort = match ($query->sort) {
            'price_asc' => 'from_price ASC, p.slug ASC',
            'price_desc' => 'from_price DESC, p.slug ASC',
            'newest' => 'p.created_at DESC, p.slug ASC',
            default => 'p.featured_rank ASC, p.slug ASC',
        };
        $countParams = array_diff_key($params, ['locale' => true]);
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM product p WHERE $where", $countParams, $types);
        $pages = max(1, (int) ceil($total / 12));
        $page = min($query->page, $pages);
        $rows = $this->db->fetchAllAssociative("SELECT p.id, p.slug, p.brand, c.slug AS category, c.names ->> :locale AS category_name,
            p.image, p.badge, $nameSql AS name,
            COALESCE(MIN(CASE WHEN v.stock > 0 THEN v.price_czk END), MIN(v.price_czk)) AS from_price,
            COALESCE(MIN(CASE WHEN v.stock > 0 THEN v.price_eur END), MIN(v.price_eur)) AS from_eur,
            COALESCE(SUM(GREATEST(v.stock, 0)), 0) AS available_units
            FROM product p JOIN category c ON c.id = p.category_id
            JOIN product_variant v ON v.product_id = p.id AND v.active = TRUE
            WHERE $where GROUP BY p.id, c.id ORDER BY $sort LIMIT 12 OFFSET ".(($page - 1) * 12), $params, $types);

        // one lookup for the whole page, not one per card
        $availability = $this->availability->forProducts(array_column($rows, 'id'));

        return [
            'items' => array_map(fn (array $row): array => $this->card($row) + ['availability' => $availability[$row['id']]['card']], $rows),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    public function product(string $slug, string $locale): ?array
    {
        $row = $this->db->fetchAssociative("SELECT p.*, p.copy -> :locale ->> 'name' AS name, c.slug AS category_slug,
            COALESCE(MIN(CASE WHEN v.stock > 0 THEN v.price_czk END), MIN(v.price_czk)) AS from_czk,
            COALESCE(MIN(CASE WHEN v.stock > 0 THEN v.price_eur END), MIN(v.price_eur)) AS from_eur,
            COALESCE(SUM(GREATEST(v.stock, 0)), 0) AS available_units
            FROM product p JOIN category c ON c.id = p.category_id
            JOIN product_variant v ON v.product_id = p.id AND v.active = TRUE
            WHERE p.slug = :slug AND p.status = 'published' GROUP BY p.id, c.id", ['slug' => $slug, 'locale' => $locale]);
        if ($row === false) {
            return null;
        }
        $copy = json_decode($row['copy'], true, flags: JSON_THROW_ON_ERROR)[$locale];
        $index = CategoryIndex::load($this->db);
        $path = $index->path($row['category_id']);
        $definitions = $index->effectiveAttributes($row['category_id']);
        $attributes = json_decode($row['attributes'], true, flags: JSON_THROW_ON_ERROR);
        $variants = $this->db->fetchAllAssociative('SELECT * FROM product_variant WHERE product_id = :id AND active = TRUE ORDER BY sku', ['id' => $row['id']]);
        $availability = $this->availability->forProducts([$row['id']])[$row['id']];
        $inBox = trim((string) ($copy['inBox'] ?? ''));

        return [
            'id' => $row['id'],
            'slug' => $row['slug'],
            'brand' => $row['brand'],
            'category' => $row['category_slug'],
            'categoryName' => end($path)['names'][$locale],
            'name' => $copy['name'],
            'short' => $copy['short'],
            'description' => $copy['description'],
            'details' => $this->details($copy['details']),
            'image' => $row['image'],
            'images' => json_decode($row['images'], true, flags: JSON_THROW_ON_ERROR),
            'badge' => $row['badge'],
            'breadcrumbs' => array_map(static fn (array $category): array => ['slug' => $category['slug'], 'name' => $category['names'][$locale]], $path),
            'specs' => AttributeSchema::specs($attributes, $definitions, $locale),
            'inBox' => $inBox === '' ? null : $inBox,
            'fromPrice' => ['amount' => (int) $row['from_czk'], 'currency' => 'CZK'],
            'fromPriceEur' => $row['from_eur'] === null ? null : ['amount' => (int) $row['from_eur'], 'currency' => 'EUR'],
            'inStock' => (int) $row['available_units'] > 0,
            'availableUnits' => (int) $row['available_units'],
            'availability' => $availability['card'],
            'variants' => array_map(static function (array $variant) use ($locale, $definitions, $availability): array {
                $labels = json_decode($variant['label'], true, flags: JSON_THROW_ON_ERROR);

                return [
                    'id' => $variant['id'], 'sku' => $variant['sku'], 'label' => $labels[$locale],
                    'color' => $variant['color'], 'size' => $variant['size'], 'stock' => (int) $variant['stock'],
                    'mpn' => $variant['mpn'], 'ean' => $variant['ean'],
                    'specs' => AttributeSchema::specs(json_decode($variant['attributes'], true, flags: JSON_THROW_ON_ERROR), $definitions, $locale),
                    'price' => ['amount' => (int) $variant['price_czk'], 'currency' => 'CZK'],
                    'priceEur' => $variant['price_eur'] === null ? null : ['amount' => (int) $variant['price_eur'], 'currency' => 'EUR'],
                    'availability' => $availability['variants'][$variant['id']] ?? Sourcing::unavailable()->toPublic(),
                ];
            }, $variants),
        ];
    }

    public function categories(string $locale): array
    {
        $counts = $this->db->fetchAllKeyValue("SELECT category_id, COUNT(*) FROM product WHERE status = 'published' GROUP BY category_id");

        return CategoryIndex::load($this->db)->publicTree($locale, array_map('intval', $counts));
    }

    public function facets(string $slug, string $locale): ?array
    {
        $index = CategoryIndex::load($this->db);
        $category = $index->bySlug($slug);
        if ($category === null || !$index->isVisible($category['id'])) {
            return null;
        }
        $ids = $index->subtreeIds($category['id'], true);
        $brands = $this->db->fetchAllAssociative(
            "SELECT brand AS value, COUNT(*) AS count FROM product WHERE status = 'published' AND brand IS NOT NULL AND category_id IN (:ids) GROUP BY brand ORDER BY brand",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
        $definitions = array_values(array_filter($index->effectiveAttributes($category['id']), static fn (array $d): bool => $d['filterable']));
        $counts = [];
        if ($definitions !== []) {
            // p.attributes || v.attributes lets the variant value replace the product value.
            // Doctrine writes an empty PHP array as [] rather than {}, and object || array is an array, so treat [] as {}
            $rows = $this->db->fetchAllAssociative(
                "SELECT e.key, e.value, COUNT(DISTINCT p.id) AS count
                FROM product p JOIN product_variant v ON v.product_id = p.id AND v.active = TRUE
                CROSS JOIN LATERAL jsonb_each_text(
                    (CASE WHEN jsonb_typeof(p.attributes) = 'object' THEN p.attributes ELSE '{}'::jsonb END)
                    || (CASE WHEN jsonb_typeof(v.attributes) = 'object' THEN v.attributes ELSE '{}'::jsonb END)) e
                WHERE p.status = 'published' AND p.category_id IN (:ids) AND e.key IN (:keys)
                GROUP BY e.key, e.value",
                ['ids' => $ids, 'keys' => array_column($definitions, 'key')],
                ['ids' => ArrayParameterType::STRING, 'keys' => ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                $counts[$row['key']][$row['value']] = (int) $row['count'];
            }
        }
        $attributes = [];
        foreach ($definitions as $definition) {
            $values = [];
            foreach ($counts[$definition['key']] ?? [] as $value => $count) {
                $value = (string) $value;
                $values[] = ['value' => $value, 'label' => AttributeSchema::valueLabel($definition, $value, $locale), 'count' => $count];
            }
            if ($values === []) {
                continue;
            }
            usort($values, $definition['type'] === 'number'
                ? static fn (array $a, array $b): int => (float) $a['value'] <=> (float) $b['value']
                : static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
            $attributes[] = ['key' => $definition['key'], 'label' => $definition['labels'][$locale], 'type' => $definition['type'], 'unit' => $definition['unit'], 'values' => $values];
        }

        return [
            'category' => $category['slug'],
            'brands' => array_map(static fn (array $row): array => ['value' => $row['value'], 'count' => (int) $row['count']], $brands),
            'attributes' => $attributes,
        ];
    }

    private function nameSql(string $locale): string
    {
        return match ($locale) {
            'cs' => "p.copy -> 'cs' ->> 'name'",
            'de' => "p.copy -> 'de' ->> 'name'",
            'en' => "p.copy -> 'en' ->> 'name'",
            default => throw new \InvalidArgumentException('Unsupported catalog locale'),
        };
    }

    private function card(array $row): array
    {
        return [
            'id' => $row['id'], 'slug' => $row['slug'], 'name' => $row['name'], 'brand' => $row['brand'],
            'category' => $row['category'], 'categoryName' => $row['category_name'],
            'image' => $row['image'], 'badge' => $row['badge'],
            'fromPrice' => ['amount' => (int) $row['from_price'], 'currency' => 'CZK'],
            'fromPriceEur' => $row['from_eur'] === null ? null : ['amount' => (int) $row['from_eur'], 'currency' => 'EUR'],
            'inStock' => (int) $row['available_units'] > 0,
            'availableUnits' => (int) $row['available_units'],
        ];
    }

    /** @return list<string> */
    private function details(array|string $details): array
    {
        $parts = is_array($details) ? $details : explode(';', $details);

        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
    }
}
