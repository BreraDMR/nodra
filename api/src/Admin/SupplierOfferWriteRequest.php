<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\SupplierOffer;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SupplierOfferWriteRequest
{
    public function __construct(
        #[Assert\Choice(choices: SupplierOffer::SUPPLIERS)] public string $supplier,
        #[Assert\NotBlank, Assert\Url(requireTld: true), Assert\Length(max: 2048)] public string $url,
        #[Assert\NotBlank, Assert\Length(max: 200)] public string $title,
        #[Assert\Choice(choices: ['CZK', 'EUR', 'PLN'])] public string $currency,
        #[Assert\PositiveOrZero] public int $priceMinor,
        #[Assert\NotBlank] public string $checkedAt,
        #[Assert\Choice(choices: SupplierOffer::STATUSES)] public string $verificationStatus = 'snapshot',
        #[Assert\Length(max: 120)] public ?string $seller = null,
        #[Assert\PositiveOrZero] public ?int $reportedQuantity = null,
        #[Assert\Range(min: 0, max: 365)] public ?int $leadTimeMinDays = null,
        #[Assert\Range(min: 0, max: 365)] public ?int $leadTimeMaxDays = null,
        #[Assert\Uuid] public ?string $variantId = null,
        #[Assert\PositiveOrZero] public int $inboundShippingMinor = 0,
        #[Assert\Positive] public ?int $fxRateCzk = null,
        #[Assert\Date] public ?string $fxRateDate = null,
    ) {}
}
