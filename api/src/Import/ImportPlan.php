<?php

declare(strict_types=1);

namespace App\Import;

/** The writes apply would do, already matched against the current catalogue. */
final class ImportPlan
{
    /**
     * @param list<PlannedNewProduct> $newProducts
     * @param list<PlannedUpdate> $updates
     * @param list<array{row: int, message: string}> $errors parser errors, kept for the report
     */
    public function __construct(
        public readonly array $newProducts,
        public readonly array $updates,
        public readonly array $errors,
    ) {}
}
