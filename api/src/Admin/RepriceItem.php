<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RepriceItem
{
    public function __construct(
        #[Assert\NotBlank, Assert\Uuid] public string $variantId,
        #[Assert\PositiveOrZero] public int $suggestedPriceCzk,
    ) {}
}
