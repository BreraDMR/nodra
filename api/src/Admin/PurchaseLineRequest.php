<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PurchaseLineRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Uuid] public string $itemId,
        /** per unit, in the purchase currency; the screen prefills it from the offer */
        #[Assert\PositiveOrZero] public int $unitPriceMinor,
    ) {}
}
