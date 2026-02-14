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
}
