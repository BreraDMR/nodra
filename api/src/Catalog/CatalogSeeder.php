<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared by the fixtures and app:catalog:import. Creates what's missing and only fills
 * blank fields on existing rows, so admin edits survive a repeated import.
 */
final class CatalogSeeder
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return array{added: int, filled: int} */
    public function seedCategories(): array
    {
        $bySlug = $this->categoriesBySlug();
        $added = 0;
        $filled = 0;
        foreach (CatalogSeed::categories() as $seed) {
            $parent = null;
            if ($seed['parent'] !== null) {
                $parent = $bySlug[$seed['parent']] ?? throw new \RuntimeException(sprintf('Parent category "%s" is missing; was its slug changed in the admin?', $seed['parent']));
            }
            $definitions = AttributeSchema::normalizeDefinitions($seed['attributes'] ?? [], array_column($this->effectiveAttributes($parent), 'key'));
            $existing = $bySlug[$seed['slug']] ?? null;
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

        return ['added' => $added, 'filled' => $filled];
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
            $attributes = AttributeSchema::normalizeValues($item['attributes'] ?? [], $this->effectiveAttributes($category));
            $existing = $products->findOneBy(['slug' => $item['slug']]);
            if ($existing !== null) {
                $source = $item['source'] ?? null;
                if (is_array($source) && isset($source['url']) && $offers->findOneBy(['product' => $existing, 'url' => $source['url'], 'variant' => null]) === null) {
                    $offer = SupplierOfferSeed::fromItem($existing, $item);
                    if ($offer !== null) {
                        $this->em->persist($offer);
                        ++$newOffers;
                    }
                }
                if (($existing->getBrand() === null && $brand !== null) || ($existing->getAttributes() === [] && $attributes !== [])) {
                    $existing->describe($existing->getBrand() ?? $brand, $existing->getAttributes() ?: $attributes);
                    ++$filled;
                }
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
            $offer = SupplierOfferSeed::fromItem($product, $item);
            if ($offer !== null) {
                $this->em->persist($offer);
                ++$newOffers;
            }
            foreach ($item['variants'] as $index => $option) {
                // demo stock still comes from the source snapshot, D02/D04 replace it with order-request availability
                $this->em->persist(new ProductVariant(
                    $product,
                    'ND-'.strtoupper(substr(hash('sha256', $item['slug']), 0, 12)).'-'.($index + 1),
                    $option['label'],
                    $item['priceCzk'] + ($option['priceDeltaCzk'] ?? 0),
                    $item['priceEur'] + ($option['priceDeltaEur'] ?? 0),
                    max(0, $item['stock'] - ($index * 3)),
                    $option['color'] ?? null,
                    $option['size'] ?? null,
                ));
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
