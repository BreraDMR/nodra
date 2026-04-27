<?php

declare(strict_types=1);

namespace App\Pricing;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/** Public availability and lead time per variant, and the best one per product card. */
final class AvailabilityService
{
    public function __construct(private Connection $db, private PricingData $data, private SourcingCalculator $calculator, private ClockInterface $clock) {}

    /**
     * Two queries for a whole page: active variants, then all offers of those products.
     *
     * @param list<string> $productIds
     *
     * @return array<string, array{card: array, variants: array<string, array>}> public shapes, see Sourcing::toPublic()
     */
    public function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $variants = $this->db->fetchAllAssociative(
            'SELECT id, product_id FROM product_variant WHERE product_id IN (:ids) AND active = TRUE',
            ['ids' => $productIds],
            ['ids' => ArrayParameterType::STRING],
        );
        $offers = $this->data->offersByProduct($productIds);
        $now = $this->clock->now();
        $byProduct = [];
        foreach ($variants as $variant) {
            $byProduct[$variant['product_id']][$variant['id']] = $this->calculator->evaluate($variant['id'], $offers[$variant['product_id']] ?? [], $now);
        }

        $result = [];
        foreach ($productIds as $productId) {
            $sourcing = $byProduct[$productId] ?? [];
            $result[$productId] = [
                'card' => Sourcing::best(array_values($sourcing))->toPublic(),
                'variants' => array_map(static fn (Sourcing $s): array => $s->toPublic(), $sourcing),
            ];
        }

        return $result;
    }

    /**
     * Full sourcing, best offer and cost included, for checkout.
     *
     * @param array<string, string> $productIdsByVariant variant id => product id
     *
     * @return array<string, Sourcing>
     */
    public function forVariants(array $productIdsByVariant): array
    {
        $offers = $this->data->offersByProduct(array_values($productIdsByVariant));
        $now = $this->clock->now();
        $result = [];
        foreach ($productIdsByVariant as $variantId => $productId) {
            $result[$variantId] = $this->calculator->evaluate((string) $variantId, $offers[$productId] ?? [], $now);
        }

        return $result;
    }
}
