<?php

declare(strict_types=1);

namespace App\Pricing;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Bulk reads for pricing and availability, a fixed number of queries whatever the number of variants. */
final class PricingData
{
    private const CHUNK = 1000;

    public function __construct(private Connection $db) {}

    /**
     * @param list<string> $productIds
     *
     * @return array<string, list<OfferFacts>> product id => its offers, variant-level and product-level
     */
    public function offersByProduct(array $productIds): array
    {
        $byProduct = [];
        foreach (array_chunk(array_values(array_unique($productIds)), self::CHUNK) as $chunk) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, product_id, variant_id, verification_status, currency, price_minor, checked_at, inbound_shipping_minor, fx_rate_czk, fx_rate_date,
                    reported_quantity, lead_time_min_days, lead_time_max_days, supplier, seller, url
                FROM supplier_offer WHERE product_id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                $byProduct[$row['product_id']][] = OfferFacts::fromRow($row);
            }
        }

        return $byProduct;
    }

    /** @return list<array> every rule with its category slug, default rules first */
    public function rules(): array
    {
        return $this->db->fetchAllAssociative('SELECT r.id, r.category_id, r.min_cost_czk_minor, r.max_cost_czk_minor, r.markup_bp, r.active, c.slug AS category_slug
            FROM pricing_rule r LEFT JOIN category c ON c.id = r.category_id
            ORDER BY r.category_id IS NOT NULL, c.slug, r.min_cost_czk_minor, r.id');
    }

    public function ruleBook(): RuleBook
    {
        return new RuleBook(
            array_map(RuleFacts::fromRow(...), $this->rules()),
            $this->db->fetchAllKeyValue('SELECT id, parent_id FROM category'),
        );
    }

    /**
     * Variants with what pricing and the admin rows need.
     *
     * @param string $where condition on v (variant), p (product) and c (category)
     *
     * @return list<array>
     */
    public function variants(string $where, array $params = [], array $types = []): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT v.id, v.product_id, v.sku, v.active, v.price_czk, v.price_eur, v.rrp_minor, v.rrp_currency, v.rrp_source, v.rrp_checked_at,
                v.market_price_minor, v.market_price_source, v.market_checked_at, p.category_id, p.status,
                p.copy -> 'en' ->> 'name' AS product_name, c.slug AS category_slug
            FROM product_variant v JOIN product p ON p.id = v.product_id JOIN category c ON c.id = p.category_id
            WHERE $where ORDER BY c.slug, product_name, v.sku",
            $params,
            $types,
        );
    }
}
