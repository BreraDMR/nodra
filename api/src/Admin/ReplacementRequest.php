<?php

declare(strict_types=1);

namespace App\Admin;

use App\Order\OrderRules;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReplacementRequest
{
    /** @param array{channel: string, note: string} $customerAgreedVia a model is never swapped without the customer */
    public function __construct(
        #[Assert\Uuid]
        public string $variantId,
        #[Assert\NotNull]
        #[Assert\Collection(fields: [
            'channel' => [new Assert\NotBlank(), new Assert\Choice(choices: OrderRules::AGREEMENT_CHANNELS)],
            'note' => [new Assert\NotBlank(), new Assert\Type('string'), new Assert\Length(max: 500)],
        ])]
        public array $customerAgreedVia,
        #[Assert\Range(min: 1, max: 10)]
        public ?int $quantity = null,
        #[Assert\PositiveOrZero]
        public ?int $unitPriceMinor = null,
    ) {}
}
