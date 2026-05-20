<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Delivery\DeliveryMethod;
use App\Delivery\DeliveryOption;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CheckoutRequest
{
    /**
     * @param array<string, mixed> $customer checked by CustomerInput, which needs to know the delivery method
     * @param array{method: string, fulfilment: string} $delivery
     * @param array{privacy?: mixed, marketing?: mixed} $consents
     * @param int $expectedTotal total of the quote the customer saw, haléře; only used to notice a change
     */
    public function __construct(
        #[Assert\Choice(choices: ['cs', 'de', 'en'])]
        public string $locale,
        #[Assert\NotBlank]
        public array $customer,
        #[Assert\Count(min: 1, max: 20)]
        public array $items,
        #[Assert\Collection(fields: [
            'method' => [new Assert\NotBlank(), new Assert\Choice(choices: DeliveryMethod::ALL)],
            'fulfilment' => [new Assert\NotBlank(), new Assert\Choice(choices: [DeliveryOption::TOGETHER, DeliveryOption::SPLIT])],
        ])]
        public array $delivery,
        #[Assert\Collection(fields: [
            'privacy' => [new Assert\NotNull(), new Assert\IsTrue(message: 'Consent to the processing of personal data is required.')],
            'marketing' => new Assert\Optional([new Assert\Type('bool')]),
        ])]
        public array $consents,
        #[Assert\PositiveOrZero]
        public int $expectedTotal,
        public ?string $promotionCode = null,
    ) {}
}
