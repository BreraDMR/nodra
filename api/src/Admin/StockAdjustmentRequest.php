<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StockAdjustmentRequest
{
    public function __construct(
        #[Assert\Uuid] public string $variantId,
        #[Assert\NotEqualTo(0)] public int $delta,
        #[Assert\NotBlank, Assert\Length(max: 200)] public string $reason,
    ) {}
}
