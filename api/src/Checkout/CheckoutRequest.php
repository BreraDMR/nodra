<?php

declare(strict_types=1);

namespace App\Checkout;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CheckoutRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['cs', 'de', 'en'])]
        public string $locale,
        #[Assert\NotBlank]
        public array $customer,
        #[Assert\Count(min: 1, max: 20)]
        public array $items,
        public ?string $promotionCode = null,
    ) {}
}
