<?php

declare(strict_types=1);

namespace App\Import;

/** The parsed file: its good rows, the per-row errors (bad rows are skipped, the rest continues) and the row count. */
final class AwinParsedFile
{
    /**
     * @param list<AwinRow> $rows
     * @param list<array{row: int, message: string}> $errors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors,
        public readonly int $totalRows,
    ) {}
}
