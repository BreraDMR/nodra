<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\PricingRule;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class PricingRuleWriteRequest
{
    public function __construct(
        #[Assert\PositiveOrZero] public int $minCostCzkMinor,
        #[Assert\Range(min: 0, max: PricingRule::MAX_MARKUP_BP)] public int $markupBp,
        #[Assert\Positive] public ?int $maxCostCzkMinor = null,
        #[Assert\Uuid] public ?string $categoryId = null,
        public bool $active = true,
    ) {}
}
