<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class VariantWriteRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 80)] public string $sku,
        #[Assert\NotBlank] public string $labelCs,
        #[Assert\NotBlank] public string $labelDe,
        #[Assert\NotBlank] public string $labelEn,
        #[Assert\PositiveOrZero] public int $priceCzk,
        #[Assert\PositiveOrZero] public int $priceEur,
        public bool $active = true,
        public ?string $color = null,
        public ?string $size = null,
    ) {}
}
