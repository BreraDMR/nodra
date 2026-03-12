<?php

declare(strict_types=1);

namespace App\Catalog;

final class CatalogSeed
{
    public static function items(): array
    {
        $items = [];
        foreach (glob(__DIR__.'/../../data/products/*.json') as $path) {
            $items[] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        }
        if ($items === []) {
            throw new \RuntimeException('Catalog seed files are missing');
        }
        $slugs = array_column($items, 'slug');
        if (count($slugs) !== count(array_unique($slugs))) {
            throw new \RuntimeException('Catalog seed slugs must be unique');
        }
        usort($items, static fn (array $a, array $b): int => ($a['featuredRank'] ?? 100) <=> ($b['featuredRank'] ?? 100));

        return $items;
    }

    /** @return list<array> categories with parents listed before their children */
    public static function categories(): array
    {
        $categories = json_decode(file_get_contents(__DIR__.'/../../data/categories.json'), true, flags: JSON_THROW_ON_ERROR);
        $known = [];
        foreach ($categories as $category) {
            if ($category['parent'] !== null && !isset($known[$category['parent']])) {
                throw new \RuntimeException(sprintf('Seed category "%s" is listed before its parent', $category['slug']));
            }
            $known[$category['slug']] = true;
        }

        return $categories;
    }
}
