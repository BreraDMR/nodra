<?php

declare(strict_types=1);

namespace App\Admin;

use App\Order\OrderRules;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConfirmOrderRequest
{
    /** @param array{channel: string, note: string} $customerAgreedVia */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Collection(fields: [
            'channel' => [new Assert\NotBlank(), new Assert\Choice(choices: OrderRules::AGREEMENT_CHANNELS)],
            'note' => [new Assert\NotBlank(), new Assert\Type('string'), new Assert\Length(max: 500)],
        ])]
        public array $customerAgreedVia,
    ) {}
}
