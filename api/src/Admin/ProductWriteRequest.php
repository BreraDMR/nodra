<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProductWriteRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 120)] public string $slug,
        #[Assert\Choice(choices: ['bags', 'apparel', 'lights', 'accessories'])] public string $category,
        #[Assert\NotBlank] public string $nameCs,
        #[Assert\NotBlank] public string $nameDe,
        #[Assert\NotBlank] public string $nameEn,
        #[Assert\NotBlank] public string $shortCs,
        #[Assert\NotBlank] public string $shortDe,
        #[Assert\NotBlank] public string $shortEn,
        #[Assert\NotBlank] public string $image,
        #[Assert\Choice(choices: ['draft', 'published'])] public string $status,
        #[Assert\PositiveOrZero] public int $priceCzk,
        #[Assert\PositiveOrZero] public int $priceEur,
        public ?string $badge = null,
        public int $featuredRank = 100,
    ) {}
}
