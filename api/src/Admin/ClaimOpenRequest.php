<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\ReturnClaim;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClaimOpenRequest
{
    public function __construct(
        #[Assert\Uuid]
        public string $orderId,
        #[Assert\NotNull]
        #[Assert\Choice(choices: ReturnClaim::KINDS)]
        public string $kind,
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $contactedOn,
        #[Assert\Uuid]
        public ?string $itemId = null,
        #[Assert\Length(max: 500)]
        public ?string $note = null,
    ) {}
}
