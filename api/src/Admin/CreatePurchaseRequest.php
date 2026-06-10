<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\SupplierOffer;
use Symfony\Component\Validator\Constraints as Assert;

/** A purchase a human already placed on the supplier's site, recorded for lines of one supplier. */
final readonly class CreatePurchaseRequest
{
    /** @param list<PurchaseLineRequest> $lines */
    public function __construct(
        #[Assert\Choice(choices: SupplierOffer::SUPPLIERS)]
        public string $supplier,
        #[Assert\NotBlank(normalizer: 'trim'), Assert\Length(max: 120)]
        public string $reference,
        #[Assert\Choice(choices: ['CZK', 'EUR', 'PLN'])]
        public string $currency,
        #[Assert\Count(min: 1, max: 100), Assert\Valid]
        public array $lines,
        /** CZK per currency unit in millionths; leave it out for CZK */
        #[Assert\Positive]
        public ?int $fxRateCzk = null,
        public ?\DateTimeImmutable $fxRateDate = null,
        /** for the whole purchase, in its currency */
        #[Assert\PositiveOrZero]
        public int $inboundShippingMinor = 0,
        #[Assert\Length(max: 120)]
        public ?string $seller = null,
        #[Assert\Length(max: 500)]
        public ?string $note = null,
    ) {}
}
