<?php

declare(strict_types=1);

namespace App\Import;

/** What apply would write for a variant that matched: the row's offer data against the existing offer. */
final class PlannedUpdate
{
    /**
     * @param list<array{field: string, old: string|int|null, new: string|int|null}> $changes
     * @param array{0: int, 1: string}|null $rrp feed RRP to write, when the variant may take one
     */
    public function __construct(
        public readonly AwinRow $row,
        public readonly \App\Entity\ProductVariant $variant,
        public readonly array $changes,
        public readonly ?array $rrp,
        public readonly ?int $leadTimeMinDays,
        public readonly ?int $leadTimeMaxDays,
        public readonly ?\App\Entity\SupplierOffer $existingOffer,
    ) {}
}
