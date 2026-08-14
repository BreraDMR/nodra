<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Payment;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RecordPaymentRequest
{
    public function __construct(
        #[Assert\Choice(choices: Payment::KINDS)]
        public string $kind,
        #[Assert\Choice(choices: Payment::METHODS)]
        public string $method,
        #[Assert\Positive]
        public int $amountMinor,
        #[Assert\Uuid]
        public ?string $shipmentId = null,
        #[Assert\Uuid]
        public ?string $claimId = null,
        #[Assert\Length(max: 500)]
        public ?string $note = null,
    ) {}
}
