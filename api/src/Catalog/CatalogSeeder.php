<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\Category;
use App\Entity\ImportFieldOrigin;
use App\Entity\ImportRun;
use App\Entity\PriceChange;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use App\Import\OriginRecorder;
use App\Pricing\PriceHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shared by the fixtures and app:catalog:import. Creates what's missing and only fills
 * blank fields on existing rows, so admin edits survive a repeated import.
 */
final class CatalogSeeder
{
    /** Lead time windows the demo offers rotate through, so a basket can mix them into parts. */
    private const DEMO_LEAD_TIMES = [[2, 4], [3, 6], [5, 9]];

    public function __construct(
        private EntityManagerInterface $em,
        private PriceHistory $history,
        private OriginRecorder $origins,
        #[Autowire(param: 'app.pricing.fresh_offer_days')] private int $freshOfferDays,
    ) {}

    /** @return array{added: int, filled: int, warnings: list<string>} */
    public function seedCategories(): array
    {
        $bySlug = $this->categoriesBySlug();
        $now = new \DateTimeImmutable();
        $added = 0;
        $filled = 0;
        $warnings = [];
        foreach (CatalogSeed::categories() as $seed) {
            $parent = null;
            if ($seed['parent'] !== null) {
                $parent = $bySlug[$seed['parent']] ?? throw new \RuntimeException(sprintf('Parent category "%s" is missing; was its slug changed in the admin?', $seed['parent']));
            }
            $existing = $bySlug[$seed['slug']] ?? null;
            // seed definitions only matter for a new category or one the seed never touched;
            // a category the admin emptied stays empty (the seeded marker is never reset)
            if ($existing !== null && ($existing->getAttributes() !== [] || $existing->getSeededAt() !== null)) {
                continue;
            }
            $definitions = $this->seedDefinitions($seed, $existing === null ? $parent : $existing->getParent(), $existing, $bySlug, $warnings);
            if ($existing === null) {
                $category = new Category($seed['slug'], $seed['names'], $parent, $seed['position'] ?? 0);
                $category->update($seed['slug'], $seed['names'], $parent, $seed['position'] ?? 0, true, $definitions);
                $category->markSeeded($now);
                $this->em->persist($category);
                $bySlug[$seed['slug']] = $category;
                ++$added;
            } elseif ($existing->getAttributes() === [] && $definitions !== []) {
                $existing->update($existing->getSlug(), $existing->getNames(), $existing->getParent(), $existing->getPosition(), $existing->isActive(), $definitions);
                $existing->markSeeded($now);
                ++$filled;
            }
        }
        $this->em->flush();

        return ['added' => $added, 'filled' => $filled, 'warnings' => $warnings];
    }

    /** With a run the seed also journals the field origins of what it creates; the fixtures pass none. */
    public function seedProducts(?ImportRun $run = null): array
    {
        $now = new \DateTimeImmutable();
        $bySlug = $this->categoriesBySlug();
        $products = $this->em->getRepository(Product::class);
        $offers = $this->em->getRepository(SupplierOffer::class);
        $added = 0;
        $filled = 0;
        $newOffers = 0;
        $errors = [];
        foreach (CatalogSeed::items() as $rank => $item) {
            $category = $bySlug[$item['category']] ?? throw new \RuntimeException(sprintf('Product "%s" uses unknown category "%s"', $item['slug'], $item['category']));
            $brand = isset($item['brand']) ? trim($item['brand']) : null;
            $existing = $products->findOneBy(['slug' => $item['slug']]);
            if ($existing !== null) {
                // seed values only fill a card nobody has described yet. After that brand and attributes are
                // the admin's, a blank one included, since the admin may have cleared it on purpose.
                $blank = $existing->getDescribedAt() === null;
                $attributes = [];
                if ($blank && $existing->getAttributes() === []) {
                    try {
                        $attributes = AttributeSchema::normalizeValues($item['attributes'] ?? [], $this->effectiveAttributes($existing->getCategory()));
                    } catch (\InvalidArgumentException) {
                        // they no longer fit, leave the blank to the admin
                    }
                }
                $source = $item['source'] ?? null;
                // any row with this listing counts, also one the admin has since matched to a variant
                if (is_array($source) && isset($source['url']) && $offers->findOneBy(['product' => $existing, 'url' => $source['url']]) === null) {
                    $offer = SupplierOfferSeed::fromItem($existing, $item);
                    if ($offer !== null) {
                        $this->em->persist($offer);
                        ++$newOffers;
                    }
                }
                $variants = $this->em->getRepository(ProductVariant::class)->findBy(['product' => $existing]);
                $codeFills = $this->seedVariantCodes($item, $variants);
                $newOffers += $this->seedDemoOffers($existing, $variants, $rank, $item, $now);
                $touched = $codeFills !== [];
                if ($blank && (($existing->getBrand() === null && $brand !== null) || ($existing->getAttributes() === [] && $attributes !== []))) {
                    $existing->describe($existing->getBrand() ?? $brand, $existing->getAttributes() ?: $attributes);
                    $touched = true;
                }
                if ($touched) {
                    ++$filled;
                }
                foreach ($codeFills as $fill) {
                    if ($run !== null) {
                        $this->origins->forRun($run, ImportFieldOrigin::ENTITY_VARIANT, $fill['variant']->getId(), $fill['fields'], $now);
                    }
                }
                continue;
            }

            try {
                $attributes = AttributeSchema::normalizeValues($item['attributes'] ?? [], $this->effectiveAttributes($category));
            } catch (\InvalidArgumentException $error) {
                // one card that no longer fits the (changed) definitions is a row error, not an abort
                $errors[] = ['row' => $rank + 1, 'message' => sprintf('Seed product "%s": %s', $item['slug'], $error->getMessage())];
                continue;
            }
            $copy = [];
            foreach (['cs', 'de', 'en'] as $locale) {
                $copy[$locale] = [
                    'name' => $item['name'][$locale],
                    'short' => $item['short'][$locale],
                    'description' => $item['description'][$locale] ?? $item['short'][$locale],
                    'details' => $item['details'][$locale] ?? [],
                ];
            }
            $image = '/images/'.$item['image'];
            $product = new Product($item['slug'], $category, $copy, $image);
            $product->update($item['slug'], $category, $copy, $image, [$image], $item['badge'] ?? null, $item['featuredRank'] ?? $rank + 1, 'published');
            $product->describe($brand, $attributes);
            $this->em->persist($product);
            if ($run !== null) {
                $this->origins->forRun($run, ImportFieldOrigin::ENTITY_PRODUCT, $product->getId(), ['category', 'name', 'short', 'description', 'brand', 'attributes'], $now);
            }
            $offer = SupplierOfferSeed::fromItem($product, $item);
            if ($offer !== null) {
                $this->em->persist($offer);
                if ($run !== null) {
                    $this->origins->forRun($run, ImportFieldOrigin::ENTITY_OFFER, $offer->getId(), ['url', 'title', 'price', 'stock', 'checked'], $now);
                }
                ++$newOffers;
            }
            $newVariants = [];
            foreach ($item['variants'] as $index => $option) {
                // stock is what NODRA physically holds (D04): a new card starts with none, the supplier snapshot stays on the offer
                $variant = new ProductVariant(
                    $product,
                    'ND-'.strtoupper(substr(hash('sha256', $item['slug']), 0, 12)).'-'.($index + 1),
                    $option['label'],
                    $item['priceCzk'] + ($option['priceDeltaCzk'] ?? 0),
                    $item['priceEur'] + ($option['priceDeltaEur'] ?? 0),
                    0,
                    $option['color'] ?? null,
                    $option['size'] ?? null,
                );
                $this->em->persist($variant);
                $newVariants[] = $variant;
                $mpn = $option['mpn'] ?? null;
                $ean = self::seedEan($option);
                if ($mpn !== null || $ean !== null) {
                    $variant->identify($mpn, $ean, []);
                }
                // the first price is history too; existing variants' prices are never touched by the import
                $this->history->record($variant, null, null, PriceChange::IMPORT);
                if ($run !== null) {
                    $fields = ['sku', 'label', 'price'];
                    if ($mpn !== null) {
                        $fields[] = 'mpn';
                    }
                    if ($ean !== null) {
                        $fields[] = 'ean';
                    }
                    $this->origins->forRun($run, ImportFieldOrigin::ENTITY_VARIANT, $variant->getId(), $fields, $now);
                }
            }
            $newOffers += $this->seedDemoOffers($product, $newVariants, $rank, $item, $now);
            ++$added;
        }
        $this->em->flush();

        return ['added' => $added, 'filled' => $filled, 'offers' => $newOffers, 'errors' => $errors];
    }

    /**
     * Demo matched offers (the D02 tail): every second variant gets one, so the stand shows real
     * lead times and the "together / split" choice. Supplier "demo" marks them as demo data and
     * no field origins are journalled for them; a stale one gets re-checked, otherwise the whole
     * demo would drift back to "check needed" after fresh_offer_days.
     *
     * @param list<ProductVariant> $variants
     */
    private function seedDemoOffers(Product $product, array $variants, int $rank, array $item, \DateTimeImmutable $now): int
    {
        $source = is_array($item['source'] ?? null) ? $item['source'] : [];
        $priceMinor = (int) ($source['priceCzk'] ?? $item['priceCzk']) * 100;
        [$leadMin, $leadMax] = self::DEMO_LEAD_TIMES[$rank % count(self::DEMO_LEAD_TIMES)];
        $freshSince = $now->getTimestamp() - $this->freshOfferDays * 86_400;
        $repo = $this->em->getRepository(SupplierOffer::class);
        $added = 0;
        foreach ($variants as $index => $variant) {
            if (($rank + $index) % 2 !== 0) {
                continue;
            }
            $url = sprintf('https://demo.nodra.test/%s/%s', $product->getSlug(), $variant->getSku());
            $offer = $repo->findOneBy(['variant' => $variant, 'url' => $url]);
            if ($offer === null) {
                $title = mb_substr($item['name']['en'].' — '.$variant->getLabel()['en'], 0, 200);
                $offer = new SupplierOffer($product, 'demo', $url, $title, 'CZK', $priceMinor, 5, $now);
                $offer->update('demo', $url, $title, null, 'CZK', $priceMinor, 5, $now, $leadMin, $leadMax, $variant, 'matched');
                $this->em->persist($offer);
                ++$added;
            } elseif ($offer->getCheckedAt()->getTimestamp() < $freshSince) {
                $offer->update($offer->getSupplier(), $offer->getUrl(), $offer->getTitle(), $offer->getSeller(), $offer->getCurrency(), $offer->getPriceMinor(), $offer->getReportedQuantity(), $now, $offer->getLeadTimeMinDays(), $offer->getLeadTimeMaxDays(), $offer->getVariant(), $offer->getVerificationStatus());
            }
        }

        return $added;
    }

    /** Seed EANs must pass the check digit: a typo in the seed files fails the import loudly. */
    private static function seedEan(array $option): ?string
    {
        return isset($option['ean']) ? Gtin::normalize($option['ean']) : null;
    }

    /**
     * Seed MPN/EANs reach existing variants as well, matched by the seed's deterministic SKU, but only
     * into still-blank fields, so a code an import or the admin already wrote is never overwritten.
     *
     * @param list<ProductVariant> $variants
     * @return list<array{variant: ProductVariant, fields: list<string>}>
     */
    private function seedVariantCodes(array $item, array $variants): array
    {
        $prefix = 'ND-'.strtoupper(substr(hash('sha256', $item['slug']), 0, 12)).'-';
        $bySku = [];
        foreach ($variants as $variant) {
            $bySku[$variant->getSku()] = $variant;
        }
        $fills = [];
        foreach ($item['variants'] as $index => $option) {
            $variant = $bySku[$prefix.($index + 1)] ?? null;
            if ($variant === null) {
                continue;
            }
            $mpn = $variant->getMpn() ?? ($option['mpn'] ?? null);
            $ean = $variant->getEan() ?? self::seedEan($option);
            $fields = [];
            if ($mpn !== null && $variant->getMpn() === null) {
                $fields[] = 'mpn';
            }
            if ($ean !== null && $variant->getEan() === null) {
                $fields[] = 'ean';
            }
            if ($fields === []) {
                continue;
            }
            $variant->identify($mpn, $ean, $variant->getAttributes());
            $fills[] = ['variant' => $variant, 'fields' => $fields];
        }

        return $fills;
    }

    /** @return array<string, Category> */
    private function categoriesBySlug(): array
    {
        $bySlug = [];
        foreach ($this->em->getRepository(Category::class)->findAll() as $category) {
            $bySlug[$category->getSlug()] = $category;
        }

        return $bySlug;
    }

    /**
     * The seed file's definitions without keys the admin already has above or below the category, for example
     * after moving a key up the tree. Those are skipped with a warning, the import goes on.
     *
     * @param array<string, Category> $all
     * @param list<string> $warnings
     *
     * @return list<array<string, mixed>>
     */
    private function seedDefinitions(array $seed, ?Category $parent, ?Category $existing, array $all, array &$warnings): array
    {
        $taken = array_column($this->effectiveAttributes($parent), 'key');
        if ($existing !== null) {
            foreach ($all as $category) {
                if ($this->isBelow($category, $existing)) {
                    array_push($taken, ...array_column($category->getAttributes(), 'key'));
                }
            }
        }
        $definitions = [];
        foreach ($seed['attributes'] ?? [] as $definition) {
            if (in_array($definition['key'] ?? null, $taken, true)) {
                $warnings[] = sprintf('Seed attribute "%s" of category "%s" skipped: the key is already defined above or below it.', $definition['key'], $seed['slug']);
                continue;
            }
            $definitions[] = $definition;
        }

        return AttributeSchema::normalizeDefinitions($definitions, $taken);
    }

    private function isBelow(Category $category, Category $ancestor): bool
    {
        $seen = [];
        for ($current = $category->getParent(); $current !== null && !isset($seen[spl_object_id($current)]); $current = $current->getParent()) {
            if ($current === $ancestor) {
                return true;
            }
            $seen[spl_object_id($current)] = true;
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function effectiveAttributes(?Category $category): array
    {
        $chain = [];
        for ($current = $category; $current !== null; $current = $current->getParent()) {
            array_unshift($chain, $current);
        }
        $definitions = [];
        foreach ($chain as $item) {
            array_push($definitions, ...$item->getAttributes());
        }

        return $definitions;
    }
}
