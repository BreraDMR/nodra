<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalog\AttributeSchema;
use App\Catalog\CategoryIndex;
use App\Catalog\Gtin;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use App\Pricing\Money;
use App\Pricing\PriceCalculator;
use App\Pricing\PricingData;
use App\Pricing\PricingSettings;
use App\Pricing\RuleBook;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Matches every parsed feed row against the catalogue and builds the preview report plus the writes
 * apply would do. Matching keys in order: EAN, then brand + MPN, then the supplier SKU for re-imports.
 * Nothing here writes; apply executes the plan.
 */
final class ImportPlanner
{
    private const LIST_LIMIT = 200;
    private const FEED_RRP_SOURCE_PREFIX = 'feed ';

    private CategoryIndex $index;
    private RuleBook $ruleBook;

    /** @var array<string, list<array<string, mixed>>> effective definitions per category id */
    private array $definitions = [];

    public function __construct(
        private Connection $db,
        private EntityManagerInterface $em,
        private PricingData $pricingData,
        private PriceCalculator $prices,
        private PricingSettings $pricingSettings,
        private ImportSettings $settings,
    ) {}

    /**
     * @param list<AwinRow> $rows
     *
     * @return array{report: array<string, mixed>, plan: ImportPlan}
     */
    public function plan(AwinParsedFile $file, string $supplier, \DateTimeImmutable $now): array
    {
        $this->index = CategoryIndex::load($this->db);
        $this->ruleBook = $this->pricingData->ruleBook();
        $guesser = new CategoryGuesser($this->index);
        $match = $this->loadMatches($file->rows, $supplier);
        $brands = $this->brandSet();

        $updates = [];
        $newRows = [];
        $conflicts = [];
        $unknowns = [];
        $costByRow = [];
        $knownBrands = $brands;
        $eanGroups = [];

        foreach ($file->rows as $row) {
            $ean = $this->eanOf($row);
            $eanInvalid = $row->ean !== null && $ean === null;
            $candidates = [];
            if ($ean !== null) {
                $candidates = $match['byEan'][$ean] ?? [];
            }
            if ($candidates === [] && $row->mpn !== null && $row->brandName !== null) {
                $candidates = $match['byBrandMpn'][self::norm($row->brandName).'|'.self::norm($row->mpn)] ?? [];
            }
            $offer = $match['offerBySku'][$row->productId] ?? null;
            if ($candidates === [] && $offer !== null && $offer->getVariant() !== null) {
                $candidates = [$offer->getVariant()];
            }

            if (count($candidates) > 1) {
                $conflicts[] = ['row' => $row->rowNumber, 'message' => sprintf('The row matches %d existing variants by its identity keys', count($candidates)), 'productName' => $row->name, 'ean' => $ean];
                continue;
            }

            if ($candidates !== []) {
                $variant = $candidates[0];
                if ($offer !== null && ($offer->getVariant() === null || !$offer->getVariant()->getId()->equals($variant->getId()))) {
                    $conflicts[] = ['row' => $row->rowNumber, 'message' => sprintf('Supplier SKU "%s" is already recorded on another offer', $row->productId), 'productName' => $row->name, 'ean' => $ean];
                    continue;
                }
                $contradiction = $this->contradiction($row, $variant);
                if ($contradiction !== null) {
                    $conflicts[] = ['row' => $row->rowNumber, 'message' => $contradiction, 'productName' => $row->name, 'ean' => $ean];
                    continue;
                }
                $update = $this->planUpdate($row, $variant, $offer);
                if ($update !== null) {
                    $updates[] = $update;
                    $costByRow[$row->rowNumber] = $this->costRow($row, $supplier, $variant->getProduct(), $this->variantRrp($update), $variant->getMarketPriceMinor(), $variant->getId()->toRfc4122());
                }
                $this->unknownDeliveryTime($row, $unknowns);
                continue;
            }

            if ($offer !== null) {
                // the supplier SKU says this feed row is already recorded, but on an offer without an exact variant
                $conflicts[] = ['row' => $row->rowNumber, 'message' => sprintf('Supplier SKU "%s" is already recorded on an offer without an exact variant; match that offer first', $row->productId), 'productName' => $row->name, 'ean' => $ean];
                continue;
            }

            $categoryRow = $guesser->guess($row->categoryPath, $row->merchantCategory);
            if ($categoryRow === null) {
                $unknowns[] = ['row' => $row->rowNumber, 'kind' => 'unknown_category', 'message' => sprintf('Category "%s" is not in the catalogue; the row is parked', $row->categoryPath ?? $row->merchantCategory ?? '')];
                continue;
            }
            if ($eanInvalid) {
                $unknowns[] = ['row' => $row->rowNumber, 'kind' => 'invalid_ean', 'message' => sprintf('EAN "%s" does not pass the check digit; matching falls back to brand and MPN', $row->ean)];
            }
            if ($ean === null && $row->mpn === null) {
                $unknowns[] = ['row' => $row->rowNumber, 'kind' => 'no_identity', 'message' => 'The row has no valid EAN and no MPN; it cannot be identified on a re-import'];
                continue;
            }
            $brand = $row->brandName !== null && trim($row->brandName) !== '' ? trim($row->brandName) : null;
            if ($brand !== null && !isset($knownBrands[self::norm($brand)])) {
                $knownBrands[self::norm($brand)] = true;
                $unknowns[] = ['row' => $row->rowNumber, 'kind' => 'new_brand', 'message' => sprintf('Brand "%s" is new; it will be created with the product', $brand)];
            }
            $attributes = $this->attributesFor($row, $categoryRow, $unknowns);
            $newRows[] = ['row' => $row, 'categoryRow' => $categoryRow, 'attributes' => $attributes, 'ean' => $ean];
            if ($ean !== null) {
                $eanGroups[$ean][] = count($newRows) - 1;
            }
            $costByRow[$row->rowNumber] = $this->costRow($row, $supplier, $categoryRow, $row->rrpMinor !== null ? [$row->rrpMinor, $row->currency] : null, null, null);
            $this->unknownDeliveryTime($row, $unknowns);
        }

        // one EAN claimed by several rows of the file would become two of our products claiming it: conflict, nothing written
        $collided = [];
        foreach ($eanGroups as $ean => $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $collided[$index] = true;
                    unset($costByRow[$newRows[$index]['row']->rowNumber]);
                    $conflicts[] = ['row' => $newRows[$index]['row']->rowNumber, 'message' => sprintf('EAN %s is claimed by %d rows of the file', $ean, count($indexes)), 'productName' => $newRows[$index]['row']->name, 'ean' => $ean];
                }
            }
        }
        $newRows = array_values(array_filter($newRows, static fn (array $entry, int $index): bool => !isset($collided[$index]), ARRAY_FILTER_USE_BOTH));

        $products = $this->groupProducts($newRows);

        $report = $this->report($file, $supplier, $products, $updates, $conflicts, $unknowns, $costByRow);

        return [
            'report' => $report,
            'plan' => new ImportPlan($products, $updates, $file->errors),
        ];
    }

    /**
     * Bulk lookups for the whole file: variants by EAN, by brand + MPN, and the supplier's offers by SKU.
     *
     * @param list<AwinRow> $rows
     *
     * @return array{byEan: array<string, list<ProductVariant>>, byBrandMpn: array<string, list<ProductVariant>>, offerBySku: array<string, SupplierOffer>}
     */
    private function loadMatches(array $rows, string $supplier): array
    {
        $repo = $this->em->getRepository(ProductVariant::class);
        $byEan = [];
        $byBrandMpn = [];
        $eanList = [];
        $mpnList = [];
        $skus = [];
        foreach ($rows as $row) {
            $ean = $this->eanOf($row);
            if ($ean !== null) {
                $eanList[] = $ean;
            }
            if ($row->mpn !== null) {
                $mpnList[] = $row->mpn;
            }
            $skus[] = $row->productId;
        }
        if ($eanList !== []) {
            foreach ($repo->createQueryBuilder('v')->where('v.ean IN (:eans)')->setParameter('eans', array_unique($eanList))->getQuery()->getResult() as $variant) {
                $byEan[$variant->getEan()][] = $variant;
            }
        }
        if ($mpnList !== []) {
            $q = $repo->createQueryBuilder('v')->join('v.product', 'p')->where('v.mpn IN (:mpns)')->setParameter('mpns', array_unique($mpnList));
            foreach ($q->getQuery()->getResult() as $variant) {
                $brand = $variant->getProduct()->getBrand();
                if ($brand !== null) {
                    $byBrandMpn[self::norm($brand).'|'.self::norm($variant->getMpn())][] = $variant;
                }
            }
        }
        $offerBySku = [];
        foreach (array_chunk(array_unique($skus), 1000) as $chunk) {
            $q = $this->em->getRepository(SupplierOffer::class)->createQueryBuilder('o')
                ->where('o.supplier = :supplier AND o.supplierSku IN (:skus)')
                ->setParameter('supplier', $supplier)->setParameter('skus', $chunk)
                ->getQuery()->getResult();
            foreach ($q as $offer) {
                $offerBySku[$offer->getSupplierSku()] = $offer;
            }
        }

        return ['byEan' => $byEan, 'byBrandMpn' => $byBrandMpn, 'offerBySku' => $offerBySku];
    }

    /** The digits of the row's EAN, or null when it is missing or fails the check digit. */
    private function eanOf(AwinRow $row): ?string
    {
        if ($row->ean === null) {
            return null;
        }
        try {
            return Gtin::normalize($row->ean);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** The row's size or colour contradicts what the variant already says: a conflict, nothing is written. */
    private function contradiction(AwinRow $row, ProductVariant $variant): ?string
    {
        $product = $variant->getProduct();
        foreach (['colour' => $row->colour, 'size' => $row->size] as $key => $value) {
            if ($value === null) {
                continue;
            }
            $current = $variant->getAttributes()[$key] ?? $product->getAttributes()[$key] ?? null;
            if ($current === null && $key === 'colour') {
                $current = $variant->getColor();
            }
            if ($current === null && $key === 'size') {
                $current = $variant->getSize();
            }
            if ($current !== null && self::norm((string) $current) !== self::norm($value)) {
                return sprintf('The row says %s "%s" but the variant with the same EAN carries "%s"', $key, $value, $current);
            }
        }

        return null;
    }

    private function planUpdate(AwinRow $row, ProductVariant $variant, ?SupplierOffer $offer): ?PlannedUpdate
    {
        $changes = [];
        if ($offer !== null) {
            if ($offer->getPriceMinor() !== $row->priceMinor) {
                $changes[] = ['field' => 'price', 'old' => $offer->getPriceMinor(), 'new' => $row->priceMinor];
            }
            if ($offer->getReportedQuantity() !== $row->quantity) {
                $changes[] = ['field' => 'stock', 'old' => $offer->getReportedQuantity(), 'new' => $row->quantity];
            }
            $lead = self::leadTime($row->deliveryTime);
            if ($offer->getLeadTimeMinDays() !== $lead['min'] || $offer->getLeadTimeMaxDays() !== $lead['max']) {
                $changes[] = ['field' => 'leadTime', 'old' => self::leadText($offer->getLeadTimeMinDays(), $offer->getLeadTimeMaxDays()), 'new' => self::leadText($lead['min'], $lead['max'])];
            }
            if ($offer->getUrl() !== $row->deepLink) {
                $changes[] = ['field' => 'url', 'old' => $offer->getUrl(), 'new' => $row->deepLink];
            }
            if ($offer->getTitle() !== mb_substr($row->name, 0, 200)) {
                $changes[] = ['field' => 'title', 'old' => $offer->getTitle(), 'new' => mb_substr($row->name, 0, 200)];
            }
        } else {
            $changes[] = ['field' => 'offer', 'old' => null, 'new' => sprintf('%d %s', $row->priceMinor, $row->currency)];
        }
        $rrp = null;
        if ($row->rrpMinor !== null && ($variant->getRrpMinor() === null || str_starts_with((string) $variant->getRrpSource(), self::FEED_RRP_SOURCE_PREFIX))) {
            if ($variant->getRrpMinor() !== $row->rrpMinor || $variant->getRrpCurrency() !== $row->currency) {
                $changes[] = ['field' => 'rrp', 'old' => $variant->getRrpMinor() === null ? null : sprintf('%d %s', $variant->getRrpMinor(), $variant->getRrpCurrency()), 'new' => sprintf('%d %s', $row->rrpMinor, $row->currency)];
                $rrp = [$row->rrpMinor, $row->currency];
            }
        }
        if ($changes === []) {
            return null;
        }
        $lead = self::leadTime($row->deliveryTime);

        return new PlannedUpdate($row, $variant, $changes, $rrp, $lead['min'], $lead['max'], $offer);
    }

    /** RRP the variant would carry after the update: the feed's, or the existing one. @return array{0: int, 1: string}|null */
    private function variantRrp(PlannedUpdate $update): ?array
    {
        return $update->rrp ?? ($update->variant->getRrpMinor() !== null ? [$update->variant->getRrpMinor(), (string) $update->variant->getRrpCurrency()] : null);
    }

    /** @param array<string, mixed> $categoryRow CategoryIndex row or a Product for existing variants */
    private function costRow(AwinRow $row, string $supplier, Product|array $category, ?array $rrp, ?int $marketPriceMinor, ?string $variantId): array
    {
        if ($category instanceof Product) {
            $categoryId = $category->getCategory()->getId()->toRfc4122();
            $categorySlug = $category->getCategory()->getSlug();
            $productName = $category->getCopy()['en']['name'] ?? $category->getSlug();
        } else {
            $categoryId = $category['id'];
            $categorySlug = $category['slug'];
            $productName = $row->name;
        }
        $rate = match ($row->currency) {
            'CZK' => Money::RATE_ONE,
            'EUR' => $this->pricingSettings->eurRate,
            default => null,
        };
        $inbound = $this->settings->inboundShippingMinor($supplier);
        $landed = $rate === null ? null : Money::landedCost($row->priceMinor, $inbound, $rate);
        $rrpCzk = $rrp === null ? null : $this->prices->rrpInCzk($rrp[0], $rrp[1]);
        $rule = $landed === null ? null : $this->ruleBook->find($categoryId, $landed);
        $suggestion = $landed === null || $rule === null ? null : $this->prices->suggest($landed, $rule->markupBp, $rrpCzk, $marketPriceMinor);

        return [
            'variantId' => $variantId,
            'productName' => $productName,
            'categorySlug' => $categorySlug,
            'feedPriceMinor' => $row->priceMinor,
            'currency' => $row->currency,
            'fxRateCzk' => $rate,
            'inboundShippingMinor' => $inbound,
            'landedCostCzk' => $landed,
            'suggestedPriceCzk' => $suggestion?->priceCzk,
            'suggestedPriceEur' => $suggestion?->priceEur,
            'marginTooLow' => $suggestion?->marginTooLow ?? false,
        ];
    }

    /** Colour/size values the category schema defines become variant attributes; the rest is dropped and listed. */
    private function attributesFor(AwinRow $row, array $categoryRow, array &$unknowns): array
    {
        $definitions = $this->effectiveDefinitions($categoryRow['id']);
        $attributes = [];
        foreach (['colour' => $row->colour, 'size' => $row->size] as $key => $value) {
            if ($value === null) {
                continue;
            }
            try {
                $attributes += AttributeSchema::normalizeValues([$key => $value], $definitions);
            } catch (\InvalidArgumentException) {
                $unknowns[] = ['row' => $row->rowNumber, 'kind' => 'unknown_attribute', 'message' => sprintf('Attribute %s "%s" is not defined for category "%s"; the value is dropped', $key, $value, $categoryRow['slug'])];
            }
        }

        return $attributes;
    }

    /** @return list<array<string, mixed>> */
    private function effectiveDefinitions(string $categoryId): array
    {
        return $this->definitions[$categoryId] ??= $this->index->effectiveAttributes($categoryId);
    }

    /** Rows that share brand and product name are one product with several variants. @param list<array<string, mixed>> $newRows @return list<PlannedNewProduct> */
    private function groupProducts(array $newRows): array
    {
        $groups = [];
        foreach ($newRows as $entry) {
            $row = $entry['row'];
            $groups[self::norm($row->name).'|'.self::norm((string) $row->brandName)][] = $entry;
        }
        $products = [];
        foreach ($groups as $entries) {
            $first = $entries[0];
            $row = $first['row'];
            $variants = [];
            foreach ($entries as $entry) {
                $lead = self::leadTime($entry['row']->deliveryTime);
                $variants[] = new PlannedNewVariant($entry['row'], $entry['ean'], $entry['row']->mpn, self::variantLabel($entry['row']), $entry['attributes'], $lead['min'], $lead['max']);
            }
            $products[] = new PlannedNewProduct(
                $this->uniqueSlug($row->name),
                $row->brandName !== null && trim($row->brandName) !== '' ? trim($row->brandName) : null,
                $row->name,
                $row->description,
                $first['categoryRow'],
                $row->images,
                $variants,
            );
        }

        return $products;
    }

    /** @return array<string, mixed> */
    private function report(AwinParsedFile $file, string $supplier, array $products, array $updates, array $conflicts, array $unknowns, array $costByRow): array
    {
        $newList = [];
        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $newList[] = [
                    'row' => $variant->row->rowNumber,
                    'productName' => $variant->row->name,
                    'brand' => $product->brand,
                    'ean' => $variant->ean,
                    'mpn' => $variant->mpn,
                    'category' => $product->categoryRow['slug'],
                    'priceMinor' => $variant->row->priceMinor,
                    'currency' => $variant->row->currency,
                ];
            }
        }
        $updateList = array_map(static fn (PlannedUpdate $update): array => [
            'row' => $update->row->rowNumber,
            'variantId' => $update->variant->getId()->toRfc4122(),
            'sku' => $update->variant->getSku(),
            'productName' => $update->variant->getProduct()->getCopy()['en']['name'] ?? $update->variant->getProduct()->getSlug(),
            'changes' => $update->changes,
        ], $updates);
        ksort($costByRow);
        $costRows = array_values($costByRow);
        $marginTooLow = count(array_filter($costRows, static fn (array $row): bool => $row['marginTooLow']));

        $counts = [
            'totalRows' => $file->totalRows,
            'newProducts' => count($newList),
            'updates' => count($updateList),
            'conflicts' => count($conflicts),
            'unknowns' => count($unknowns),
            'errors' => count($file->errors),
            'rowsWithCost' => count($costRows),
            'suggestionsMarginTooLow' => $marginTooLow,
        ];

        return [
            'supplier' => $supplier,
            'totalRows' => $file->totalRows,
            'counts' => $counts,
            'newProducts' => array_slice($newList, 0, self::LIST_LIMIT),
            'updates' => array_slice($updateList, 0, self::LIST_LIMIT),
            'conflicts' => array_slice($conflicts, 0, self::LIST_LIMIT),
            'unknowns' => array_slice($unknowns, 0, self::LIST_LIMIT),
            'errors' => array_slice($file->errors, 0, self::LIST_LIMIT),
            'cost' => ['rows' => array_slice($costRows, 0, self::LIST_LIMIT), 'marginTooLow' => $marginTooLow],
        ];
    }

    private function unknownDeliveryTime(AwinRow $row, array &$unknowns): void
    {
        if ($row->deliveryTime !== null && self::leadTime($row->deliveryTime)['min'] === null) {
            $unknowns[] = ['row' => $row->rowNumber, 'kind' => 'delivery_time', 'message' => sprintf('Delivery time "%s" has no day range in it; the offer stays check_needed', $row->deliveryTime)];
        }
    }

    /** @return array{min: ?int, max: ?int} */
    private static function leadTime(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return ['min' => null, 'max' => null];
        }
        $value = str_replace(['–', '—'], '-', trim($value));
        if (preg_match('/(\d+)\s*(?:-|to\b|bis\b|až\b|do\b)/i', $value, $m) && preg_match('/(?:-|to\b|bis\b|až\b|do\b)\s*(\d+)/i', $value, $m2)) {
            $min = (int) $m[1];
            $max = (int) $m2[1];
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }
            if ($min >= 1 && $max <= 365) {
                return ['min' => $min, 'max' => $max];
            }

            return ['min' => null, 'max' => null];
        }
        if (preg_match('/(\d+)/', $value, $m)) {
            $days = (int) $m[1];
            if ($days >= 1 && $days <= 365) {
                return ['min' => $days, 'max' => $days];
            }
        }

        return ['min' => null, 'max' => null];
    }

    private static function leadText(?int $min, ?int $max): ?string
    {
        return $min === null || $max === null ? null : ($min === $max ? (string) $min : $min.'-'.$max);
    }

    private static function variantLabel(AwinRow $row): string
    {
        $text = trim(($row->colour ?? '').' '.($row->size ?? ''));

        return mb_substr($text !== '' ? $text : $row->name, 0, 80);
    }

    /** @return array<string, true> normalised brand => true */
    private function brandSet(): array
    {
        $brands = [];
        foreach ($this->db->fetchFirstColumn('SELECT DISTINCT brand FROM product WHERE brand IS NOT NULL') as $brand) {
            $brands[self::norm((string) $brand)] = true;
        }

        return $brands;
    }

    private function uniqueSlug(string $name): string
    {
        $base = $this->slugify($name);
        $taken = $this->db->fetchFirstColumn('SELECT slug FROM product WHERE slug = :base OR slug LIKE :prefix', ['base' => $base, 'prefix' => $base.'-%']);
        $candidate = $base;
        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }

    private function slugify(string $name): string
    {
        $slug = mb_strtolower(trim($name));
        if (function_exists('transliterator_transliterate')) {
            $slug = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $slug);
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim(substr($slug, 0, 80), '-');

        return $slug === '' ? 'import' : $slug;
    }

    private static function norm(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
