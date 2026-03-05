<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

final class CatalogService
{
    private const CATEGORIES = [
        'bags' => ['cs' => 'Brašny', 'de' => 'Taschen', 'en' => 'Bags'],
        'apparel' => ['cs' => 'Oblečení', 'de' => 'Bekleidung', 'en' => 'Apparel'],
        'lights' => ['cs' => 'Světla', 'de' => 'Beleuchtung', 'en' => 'Lights'],
        'accessories' => ['cs' => 'Doplňky', 'de' => 'Zubehör', 'en' => 'Accessories'],
    ];

    public function __construct(private Connection $db) {}

    public function browse(CatalogQuery $query): array
    {
        $locale = $query->locale;
        $nameSql = match ($locale) {
            'cs' => "p.copy -> 'cs' ->> 'name'",
            'de' => "p.copy -> 'de' ->> 'name'",
            'en' => "p.copy -> 'en' ->> 'name'",
            default => throw new \InvalidArgumentException('Unsupported catalog locale'),
        };
        $currency = $locale === 'cs' ? 'CZK' : 'EUR';
        $priceColumn = $currency === 'CZK' ? 'price_czk' : 'price_eur';
        $params = [];
        $where = "p.status = 'published'";
        if ($query->category !== null && $query->category !== '') {
            $where .= ' AND p.category = :category';
            $params['category'] = $query->category;
        }
        if ($query->q !== null && trim($query->q) !== '') {
            $where .= " AND ($nameSql ILIKE :search OR p.slug ILIKE :search)";
            $params['search'] = '%'.trim($query->q).'%';
        }
        if ($query->availableOnly) {
            $where .= ' AND EXISTS (SELECT 1 FROM product_variant stock_variant WHERE stock_variant.product_id = p.id AND stock_variant.active = TRUE AND stock_variant.stock > 0)';
        }

        $sort = match ($query->sort) {
            'price_asc' => 'from_price ASC, p.slug ASC',
            'price_desc' => 'from_price DESC, p.slug ASC',
            'newest' => 'p.created_at DESC, p.slug ASC',
            default => 'p.featured_rank ASC, p.slug ASC',
        };
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM product p WHERE $where", $params);
        $pages = max(1, (int) ceil($total / 12));
        $page = min($query->page, $pages);
        $rows = $this->db->fetchAllAssociative("SELECT p.id, p.slug, p.category, p.image, p.badge, $nameSql AS name,
            COALESCE(MIN(CASE WHEN v.stock > 0 THEN v.$priceColumn END), MIN(v.$priceColumn)) AS from_price,
            COALESCE(SUM(GREATEST(v.stock, 0)), 0) AS available_units
            FROM product p JOIN product_variant v ON v.product_id = p.id AND v.active = TRUE
            WHERE $where GROUP BY p.id ORDER BY $sort LIMIT 12 OFFSET ".(($page - 1) * 12), $params);

        return [
            'items' => array_map(fn (array $row): array => $this->card($row, $currency), $rows),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    public function product(string $slug, string $locale): ?array
    {
        $row = $this->db->fetchAssociative("SELECT p.*, p.copy -> :locale ->> 'name' AS name,
            COALESCE(MIN(CASE WHEN v.stock > 0 THEN v.price_czk END), MIN(v.price_czk)) AS from_czk,
            COALESCE(MIN(CASE WHEN v.stock > 0 THEN v.price_eur END), MIN(v.price_eur)) AS from_eur,
            COALESCE(SUM(GREATEST(v.stock, 0)), 0) AS available_units
            FROM product p JOIN product_variant v ON v.product_id = p.id AND v.active = TRUE
            WHERE p.slug = :slug AND p.status = 'published' GROUP BY p.id", ['slug' => $slug, 'locale' => $locale]);
        if ($row === false) {
            return null;
        }
        $currency = $locale === 'cs' ? 'CZK' : 'EUR';
        $copy = json_decode($row['copy'], true, flags: JSON_THROW_ON_ERROR)[$locale];
        $variants = $this->db->fetchAllAssociative('SELECT * FROM product_variant WHERE product_id = :id AND active = TRUE ORDER BY sku', ['id' => $row['id']]);

        return [
            'id' => $row['id'],
            'slug' => $row['slug'],
            'category' => $row['category'],
            'name' => $copy['name'],
            'short' => $copy['short'],
            'description' => $copy['description'],
            'details' => $this->details($copy['details']),
            'image' => $row['image'],
            'images' => json_decode($row['images'], true, flags: JSON_THROW_ON_ERROR),
            'badge' => $row['badge'],
            'fromPrice' => ['amount' => (int) $row[$currency === 'CZK' ? 'from_czk' : 'from_eur'], 'currency' => $currency],
            'inStock' => (int) $row['available_units'] > 0,
            'availableUnits' => (int) $row['available_units'],
            'variants' => array_map(static function (array $variant) use ($locale, $currency): array {
                $labels = json_decode($variant['label'], true, flags: JSON_THROW_ON_ERROR);

                return [
                    'id' => $variant['id'], 'sku' => $variant['sku'], 'label' => $labels[$locale],
                    'color' => $variant['color'], 'size' => $variant['size'], 'stock' => (int) $variant['stock'],
                    'price' => ['amount' => (int) $variant[$currency === 'CZK' ? 'price_czk' : 'price_eur'], 'currency' => $currency],
                ];
            }, $variants),
        ];
    }

    public function categories(string $locale): array
    {
        $counts = $this->db->fetchAllKeyValue("SELECT category, COUNT(*) FROM product WHERE status = 'published' GROUP BY category");
        $categories = [];
        foreach (self::CATEGORIES as $slug => $labels) {
            $categories[] = ['slug' => $slug, 'name' => $labels[$locale], 'count' => (int) ($counts[$slug] ?? 0)];
        }

        return $categories;
    }

    private function card(array $row, string $currency): array
    {
        return [
            'id' => $row['id'], 'slug' => $row['slug'], 'name' => $row['name'],
            'category' => $row['category'], 'image' => $row['image'], 'badge' => $row['badge'],
            'fromPrice' => ['amount' => (int) $row['from_price'], 'currency' => $currency],
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
