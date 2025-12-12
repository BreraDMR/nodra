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
        $currency = $locale === 'cs' ? 'CZK' : 'EUR';
        $priceColumn = $currency === 'CZK' ? 'price_czk' : 'price_eur';
        $params = ['locale' => $locale];
        $where = "p.status = 'published'";
        if ($query->category !== null && $query->category !== '') {
            $where .= ' AND p.category = :category';
            $params['category'] = $query->category;
        }
        if ($query->q !== null && trim($query->q) !== '') {
            $where .= " AND (p.copy -> :locale ->> 'name' ILIKE :search OR p.slug ILIKE :search)";
            $params['search'] = '%'.trim($query->q).'%';
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
        $rows = $this->db->fetchAllAssociative("SELECT p.id, p.slug, p.category, p.image, p.badge, p.copy -> :locale ->> 'name' AS name,
            MIN(v.$priceColumn) AS from_price, SUM(CASE WHEN v.stock > 0 THEN 1 ELSE 0 END) AS available
            FROM product p JOIN product_variant v ON v.product_id = p.id AND v.active = TRUE
            WHERE $where GROUP BY p.id ORDER BY $sort LIMIT 12 OFFSET ".(($page - 1) * 12), $params);

        return [
            'items' => array_map(fn (array $row): array => $this->card($row, $currency), $rows),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }
}
