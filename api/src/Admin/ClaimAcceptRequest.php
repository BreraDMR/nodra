<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClaimAcceptRequest
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $refundAmountMinor = null,
        #[Assert\Length(max: 500)]
        public ?string $note = null,
    ) {}
}
