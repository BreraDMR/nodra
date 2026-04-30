<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\Category;
use App\Entity\PriceChange;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use App\Pricing\PriceHistory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared by the fixtures and app:catalog:import. Creates what's missing and only fills
 * blank fields on existing rows, so admin edits survive a repeated import.
 */
final class CatalogSeeder
{
    public function __construct(private EntityManagerInterface $em, private PriceHistory $history) {}

    /** @return array{added: int, filled: int, warnings: list<string>} */
    public function seedCategories(): array
    {
        $bySlug = $this->categoriesBySlug();
        $added = 0;
        $filled = 0;
        $warnings = [];
        foreach (CatalogSeed::categories() as $seed) {
            $parent = null;
            if ($seed['parent'] !== null) {
                $parent = $bySlug[$seed['parent']] ?? throw new \RuntimeException(sprintf('Parent category "%s" is missing; was its slug changed in the admin?', $seed['parent']));
            }
            $existing = $bySlug[$seed['slug']] ?? null;
            // seed definitions only matter for a new category or one without any of its own
            if ($existing !== null && $existing->getAttributes() !== []) {
                continue;
            }
            $definitions = $this->seedDefinitions($seed, $existing === null ? $parent : $existing->getParent(), $existing, $bySlug, $warnings);
            if ($existing === null) {
                $category = new Category($seed['slug'], $seed['names'], $parent, $seed['position'] ?? 0);
                $category->update($seed['slug'], $seed['names'], $parent, $seed['position'] ?? 0, true, $definitions);
                $this->em->persist($category);
                $bySlug[$seed['slug']] = $category;
                ++$added;
            } elseif ($existing->getAttributes() === [] && $definitions !== []) {
                $existing->update($existing->getSlug(), $existing->getNames(), $existing->getParent(), $existing->getPosition(), $existing->isActive(), $definitions);
                ++$filled;
            }
        }
        $this->em->flush();

        return ['added' => $added, 'filled' => $filled, 'warnings' => $warnings];
    }

    /** @return array{added: int, filled: int, offers: int} */
    public function seedProducts(): array
    {
        $bySlug = $this->categoriesBySlug();
        $products = $this->em->getRepository(Product::class);
        $offers = $this->em->getRepository(SupplierOffer::class);
        $added = 0;
        $filled = 0;
        $newOffers = 0;
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
                if ($blank && (($existing->getBrand() === null && $brand !== null) || ($existing->getAttributes() === [] && $attributes !== []))) {
                    $existing->describe($existing->getBrand() ?? $brand, $existing->getAttributes() ?: $attributes);
                    ++$filled;
                }
                continue;
            }

            $attributes = AttributeSchema::normalizeValues($item['attributes'] ?? [], $this->effectiveAttributes($category));
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
            $offer = SupplierOfferSeed::fromItem($product, $item);
            if ($offer !== null) {
                $this->em->persist($offer);
                ++$newOffers;
            }
            foreach ($item['variants'] as $index => $option) {
                // demo stock still comes from the source snapshot, D04 replaces it with order-request checkout
                $variant = new ProductVariant(
                    $product,
                    'ND-'.strtoupper(substr(hash('sha256', $item['slug']), 0, 12)).'-'.($index + 1),
                    $option['label'],
                    $item['priceCzk'] + ($option['priceDeltaCzk'] ?? 0),
                    $item['priceEur'] + ($option['priceDeltaEur'] ?? 0),
                    max(0, $item['stock'] - ($index * 3)),
                    $option['color'] ?? null,
                    $option['size'] ?? null,
                );
                $this->em->persist($variant);
                // the first price is history too; existing variants' prices are never touched by the import
                $this->history->record($variant, null, null, PriceChange::IMPORT);
            }
            ++$added;
        }
        $this->em->flush();

        return ['added' => $added, 'filled' => $filled, 'offers' => $newOffers];
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
