<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RepriceApplyRequest
{
    /** @param list<RepriceItem> $items */
    public function __construct(
        #[Assert\Count(min: 1, max: 500), Assert\Valid]
        public array $items,
    ) {}

    /** @return array<string, int> variant id => suggested CZK price */
    public function expected(): array
    {
        $expected = [];
        foreach ($this->items as $item) {
            $id = strtolower($item->variantId);
            if (isset($expected[$id])) {
                throw new \InvalidArgumentException('Each variant may be sent once');
            }
            $expected[$id] = $item->suggestedPriceCzk;
        }

        return $expected;
    }
}
