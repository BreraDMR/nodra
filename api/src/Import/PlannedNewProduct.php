<?php

declare(strict_types=1);

namespace App\Import;

/**
 * One new draft product with its variants: the rows of the file that share brand and product name.
 * The category row is a CategoryIndex row; apply fetches the managed entity from it.
 */
final class PlannedNewProduct
{
    /**
     * @param array<string, mixed> $categoryRow
     * @param list<string> $sourceImages
     * @param list<PlannedNewVariant> $variants
     */
    public function __construct(
        public readonly string $slug,
        public readonly ?string $brand,
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $categoryRow,
        public readonly array $sourceImages,
        public readonly array $variants,
    ) {}
}
