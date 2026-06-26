<?php

declare(strict_types=1);

namespace App\Import;

/** One new variant of a planned new product, from one feed row. */
final class PlannedNewVariant
{
    /**
     * @param array<string, string> $attributes colour/size values the category schema defines
     */
    public function __construct(
        public readonly AwinRow $row,
        public readonly ?string $ean,
        public readonly ?string $mpn,
        public readonly string $label,
        public readonly array $attributes,
        public readonly ?int $leadTimeMinDays,
        public readonly ?int $leadTimeMaxDays,
    ) {}
}
