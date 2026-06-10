<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Catalog\CategoryIndex;
use App\Entity\PriceChange;
use App\Entity\ProductVariant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Admin side of pricing: the variant panel, reprice preview and apply, alert counts. */
final class PricingService
{
    public function __construct(
        private Connection $db,
        private EntityManagerInterface $em,
        private PricingData $data,
        private VariantPricer $pricer,
        private PriceHistory $history,
        private ClockInterface $clock,
    ) {}

    public function variant(string $id): ?array
    {
        $rows = $this->data->variants('v.id = :id', ['id' => $id]);
        if ($rows === []) {
            return null;
        }

        return $this->panel($rows[0], $this->evaluate($rows)[$rows[0]['id']]);
    }

    /** Null when the category doesn't exist. Without a category the whole catalogue is previewed. */
    public function preview(?string $categoryId): ?array
    {
        $where = "v.active = TRUE AND p.status IN ('draft', 'published')";
        $params = [];
        $types = [];
        if ($categoryId !== null) {
            $categoryId = Uuid::fromString($categoryId)->toRfc4122();
            $index = CategoryIndex::load($this->db);
            if ($index->get($categoryId) === null) {
                return null;
            }
            $where .= ' AND p.category_id IN (:categories)';
            $params['categories'] = $index->subtreeIds($categoryId);
            $types['categories'] = ArrayParameterType::STRING;
        }
        $rows = $this->data->variants($where, $params, $types);
        $pricing = $this->evaluate($rows);
        $preview = [];
        foreach ($rows as $row) {
            $item = $pricing[$row['id']];
            if ($item->suggestion === null) {
                continue;
            }
            $preview[] = [
                'variantId' => $row['id'], 'productId' => $row['product_id'], 'productName' => $row['product_name'],
                'sku' => $row['sku'], 'categorySlug' => $row['category_slug'],
                'priceCzk' => $item->variant->priceCzk, 'priceEur' => $item->variant->priceEur,
                'landedCostCzk' => $item->sourcing->landedCostCzk, 'currentMarginBp' => $item->currentMarginBp,
                'suggestedPriceCzk' => $item->suggestion->priceCzk, 'suggestedPriceEur' => $item->suggestion->priceEur,
                'suggestedMarginBp' => $item->suggestion->marginBp, 'marketPriceMinor' => $item->variant->marketPriceMinor,
                'suggestionAboveMarket' => $item->suggestion->aboveMarket, 'flags' => $item->flags(),
                'changed' => $item->changes(), 'applicable' => $item->applicable(),
            ];
        }

        return ['categoryId' => $categoryId, 'variants' => count($rows), 'withoutSuggestion' => count($rows) - count($preview), 'rows' => $preview];
    }

    /** Null when the variant doesn't exist. */
    public function applySuggestion(string $variantId, int $expectedPriceCzk): ?array
    {
        if ($this->db->fetchOne('SELECT 1 FROM product_variant WHERE id = :id', ['id' => $variantId]) === false) {
            return null;
        }

        return $this->apply([$variantId => $expectedPriceCzk])['items'][0];
    }

    /**
     * All or nothing: every suggestion must still be the one the admin saw, and none may be flagged.
     *
     * @param array<string, int> $expected variant id => suggested CZK price shown to the admin
     */
    public function apply(array $expected): array
    {
        $expected = $this->normalizeIds($expected);

        return $this->db->transactional(function () use ($expected): array {
            $ids = array_keys($expected);
            // lock first so a second apply or an admin edit waits for this one
            $found = $this->db->fetchFirstColumn('SELECT id FROM product_variant WHERE id IN (:ids) ORDER BY id FOR UPDATE', ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
            $missing = array_values(array_diff($ids, $found));
            if ($missing !== []) {
                throw new \InvalidArgumentException('Unknown variant '.$missing[0]);
            }
            $rows = $this->data->variants('v.id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
            $pricing = $this->evaluate($rows);
            $stale = array_values(array_filter($ids, static fn (string $id): bool => $pricing[$id]->suggestion?->priceCzk !== $expected[$id]));
            if ($stale !== []) {
                throw new StalePriceException($stale);
            }
            $skus = array_column($rows, 'sku', 'id');
            foreach ($ids as $id) {
                if (!$pricing[$id]->applicable()) {
                    throw new \InvalidArgumentException(sprintf('%s is flagged margin_too_low and is never repriced automatically; set its price by hand', $skus[$id]));
                }
            }

            $items = [];
            $applied = 0;
            foreach ($ids as $id) {
                $variant = $this->em->find(ProductVariant::class, Uuid::fromString($id));
                $oldCzk = $variant->getPriceCzk();
                $oldEur = $variant->getPriceEur();
                $suggestion = $pricing[$id]->suggestion;
                $variant->changePrice($suggestion->priceCzk, $suggestion->priceEur);
                $this->history->record($variant, $oldCzk, $oldEur, PriceChange::REPRICE);
                $changed = $oldCzk !== $suggestion->priceCzk || $oldEur !== $suggestion->priceEur;
                $applied += (int) $changed;
                $items[] = [
                    'variantId' => $id, 'oldPriceCzk' => $oldCzk, 'newPriceCzk' => $suggestion->priceCzk,
                    'oldPriceEur' => $oldEur, 'newPriceEur' => $suggestion->priceEur, 'changed' => $changed,
                ];
            }
            $this->em->flush();

            return ['applied' => $applied, 'items' => $items];
        });
    }

    /**
     * Counts for the dashboard tile, over active variants of published products. A variant NODRA holds itself is
     * sold from own stock, so its offers needing a check isn't an alert.
     */
    public function alerts(): array
    {
        $rows = $this->data->variants("v.active = TRUE AND p.status = 'published'");
        $pricing = $this->evaluate($rows);
        $held = array_column(array_filter($rows, static fn (array $row): bool => (int) $row['stock'] > 0), 'id', 'id');
        $count = static fn (callable $test): int => count(array_filter($pricing, $test));

        return [
            'marginTooLow' => $count(static fn (VariantPricing $p): bool => in_array(VariantPricing::MARGIN_TOO_LOW, $p->flags(), true)),
            'aboveMarket' => $count(static fn (VariantPricing $p): bool => $p->aboveMarket),
            'checkNeeded' => $count(static fn (VariantPricing $p): bool => $p->sourcing->status === Sourcing::CHECK_NEEDED && !isset($held[$p->variant->id])),
        ];
    }

    /**
     * @param list<array> $rows from PricingData::variants()
     *
     * @return array<string, VariantPricing> by variant id
     */
    private function evaluate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $offers = $this->data->offersByProduct(array_column($rows, 'product_id'));
        $rules = $this->data->ruleBook();
        $now = $this->clock->now();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = $this->pricer->price(VariantFacts::fromRow($row), $offers[$row['product_id']] ?? [], $rules, $now);
        }

        return $result;
    }

    private function panel(array $row, VariantPricing $pricing): array
    {
        $offer = $pricing->sourcing->offer;

        return [
            'variantId' => $row['id'], 'productId' => $row['product_id'], 'productName' => $row['product_name'], 'sku' => $row['sku'],
            'categoryId' => $row['category_id'], 'categorySlug' => $row['category_slug'],
            'priceCzk' => $pricing->variant->priceCzk, 'priceEur' => $pricing->variant->priceEur,
            'currentMarginBp' => $pricing->currentMarginBp,
            'availability' => $pricing->sourcing->toPublic() + ['reason' => $pricing->sourcing->reason],
            'cost' => $offer === null ? null : [
                'offerId' => $offer->id, 'supplier' => $offer->supplier, 'seller' => $offer->seller, 'url' => $offer->url,
                'currency' => $offer->currency, 'priceMinor' => $offer->priceMinor, 'inboundShippingMinor' => $offer->inboundShippingMinor,
                'fxRateCzk' => $offer->fxRateCzk, 'fxRateDate' => $offer->fxRateDate?->format('Y-m-d'),
                'checkedAt' => $offer->checkedAt->format(\DATE_ATOM),
                'leadTimeMinDays' => $offer->leadTimeMinDays, 'leadTimeMaxDays' => $offer->leadTimeMaxDays,
                'landedCostCzk' => $pricing->sourcing->landedCostCzk,
            ],
            'rule' => $pricing->rule?->toArray(),
            'suggestion' => $pricing->suggestion?->toArray(),
            'rrpMinor' => $pricing->variant->rrpMinor, 'rrpCurrency' => $row['rrp_currency'], 'rrpSource' => $row['rrp_source'],
            'rrpCheckedAt' => $row['rrp_checked_at'], 'rrpCzk' => $pricing->rrpCzk,
            'marketPriceMinor' => $pricing->variant->marketPriceMinor, 'marketPriceSource' => $row['market_price_source'],
            'marketCheckedAt' => $row['market_checked_at'],
            'flags' => $pricing->flags(),
            'applicable' => $pricing->applicable(),
        ];
    }

    /**
     * @param array<string, int> $expected
     *
     * @return array<string, int> keyed by lowercase RFC 4122 ids, the way the database returns them
     */
    private function normalizeIds(array $expected): array
    {
        $normalized = [];
        foreach ($expected as $id => $price) {
            if (!Uuid::isValid((string) $id)) {
                throw new \InvalidArgumentException('Unknown variant '.$id);
            }
            $normalized[Uuid::fromString((string) $id)->toRfc4122()] = $price;
        }

        return $normalized;
    }
}
