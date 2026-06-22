<?php

declare(strict_types=1);

namespace App\Import;

/**
 * One parsed feed row. Identity and texts stay raw here (trimmed); the money fields are parsed,
 * because a row without them is broken, while everything optional is null when the column is missing.
 */
final class AwinRow
{
    /**
     * @param list<string> $images
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $productId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $brandName,
        public readonly ?string $ean,
        public readonly ?string $mpn,
        public readonly ?string $colour,
        public readonly ?string $size,
        public readonly int $priceMinor,
        public readonly string $currency,
        public readonly ?int $rrpMinor,
        public readonly ?bool $inStock,
        public readonly ?string $stockStatus,
        public readonly ?int $quantity,
        public readonly ?string $deliveryTime,
        public readonly array $images,
        public readonly ?string $merchantCategory,
        public readonly ?string $categoryPath,
        public readonly string $deepLink,
        public readonly ?string $lastUpdated,
    ) {}
}
