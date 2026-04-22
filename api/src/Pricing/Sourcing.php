<?php

declare(strict_types=1);

namespace App\Pricing;

/** Where a variant would be bought from right now, and what the customer is told about it. */
final readonly class Sourcing
{
    public const ORDERABLE = 'orderable';
    public const CHECK_NEEDED = 'check_needed';
    public const UNAVAILABLE = 'unavailable';

    private const RANK = [self::ORDERABLE => 0, self::CHECK_NEEDED => 1, self::UNAVAILABLE => 2];

    /**
     * @param ?OfferFacts $offer the best fresh matched offer, also when its lead time is unknown
     * @param ?string $reason why it isn't orderable, admin-only
     */
    public function __construct(
        public string $status,
        public ?string $reason = null,
        public ?OfferFacts $offer = null,
        public ?int $landedCostCzk = null,
        public ?int $leadTimeMinDays = null,
        public ?int $leadTimeMaxDays = null,
    ) {}

    public static function unavailable(string $reason = 'no_offers'): self
    {
        return new self(self::UNAVAILABLE, $reason);
    }

    /** What public responses may show. */
    public function toPublic(): array
    {
        return ['status' => $this->status, 'leadTimeMinDays' => $this->leadTimeMinDays, 'leadTimeMaxDays' => $this->leadTimeMaxDays];
    }

    /**
     * The card-level status: the best one among the variants, and for orderable the quickest variant.
     *
     * @param list<self> $all
     */
    public static function best(array $all): self
    {
        $best = self::unavailable();
        foreach ($all as $sourcing) {
            $better = self::RANK[$sourcing->status] <=> self::RANK[$best->status];
            if ($better < 0 || ($better === 0 && $sourcing->status === self::ORDERABLE
                && [$sourcing->leadTimeMaxDays, $sourcing->leadTimeMinDays] < [$best->leadTimeMaxDays, $best->leadTimeMinDays])) {
                $best = $sourcing;
            }
        }

        return $best;
    }
}
