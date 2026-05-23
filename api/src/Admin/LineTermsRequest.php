<?php

declare(strict_types=1);

namespace App\Admin;

use App\Order\OrderRules;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LineTermsRequest
{
    /** @param ?array{channel: string, note: string} $customerAgreedVia */
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $unitPriceMinor = null,
        #[Assert\Range(min: 0, max: 365)]
        public ?int $leadTimeMinDays = null,
        #[Assert\Range(min: 0, max: 365)]
        public ?int $leadTimeMaxDays = null,
        #[Assert\Collection(fields: [
            'channel' => [new Assert\NotBlank(), new Assert\Choice(choices: OrderRules::AGREEMENT_CHANNELS)],
            'note' => [new Assert\NotBlank(), new Assert\Type('string'), new Assert\Length(max: 500)],
        ])]
        public ?array $customerAgreedVia = null,
    ) {}
}
