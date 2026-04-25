<?php

declare(strict_types=1);

namespace App\Pricing;

final readonly class PriceSuggestion
{
    public function __construct(
        public int $priceCzk,
        public int $priceEur,
        /** landed cost plus the rule markup, rounded up to 10 Kč */
        public int $markupPriceCzk,
        /** RRP x factor rounded down to 10 Kč, null without RRP */
        public ?int $rrpCapCzk,
        public bool $rrpCapped,
        /** landed cost plus the minimum margin, rounded up to 10 Kč */
        public int $floorPriceCzk,
        /** the markup or RRP target was below the floor, the price was lifted to it */
        public bool $marginTooLow,
        public ?int $marginBp,
        public bool $aboveMarket,
    ) {}

    public function toArray(): array
    {
        return [
            'priceCzk' => $this->priceCzk, 'priceEur' => $this->priceEur, 'markupPriceCzk' => $this->markupPriceCzk,
            'rrpCapCzk' => $this->rrpCapCzk, 'rrpCapped' => $this->rrpCapped, 'floorPriceCzk' => $this->floorPriceCzk,
            'marginBp' => $this->marginBp, 'aboveMarket' => $this->aboveMarket,
        ];
    }
}
