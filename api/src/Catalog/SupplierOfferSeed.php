<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\Product;
use App\Entity\SupplierOffer;

final class SupplierOfferSeed
{
    public static function fromItem(Product $product, array $item): ?SupplierOffer
    {
        $source = $item['source'] ?? null;
        if (!is_array($source) || !isset($source['url'], $source['priceCzk'], $source['checkedAt'])) {
            return null;
        }

        return new SupplierOffer(
            $product,
            'allegro_cz',
            $source['url'],
            substr((string) ($source['title'] ?? $item['name']['en']), 0, 200),
            'CZK',
            (int) $source['priceCzk'] * 100,
            isset($source['stock']) ? (int) $source['stock'] : null,
            new \DateTimeImmutable($source['checkedAt'].' 00:00:00 Europe/Prague'),
        );
    }
}
