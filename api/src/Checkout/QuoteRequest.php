<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Delivery\DeliveryMethod;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class QuoteRequest
{
    /** @param array{method: string, postalCode?: ?string} $delivery */
    public function __construct(
        #[Assert\Choice(choices: ['cs', 'de', 'en'])]
        public string $locale,
        #[Assert\Count(min: 1, max: 20)]
        public array $items,
        #[Assert\Collection(fields: [
            'method' => [new Assert\NotBlank(), new Assert\Choice(choices: DeliveryMethod::ALL)],
            'postalCode' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 24)]),
        ])]
        public array $delivery,
    ) {}
}
