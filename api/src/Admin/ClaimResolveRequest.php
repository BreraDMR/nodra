<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\ReturnClaim;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClaimResolveRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Choice(choices: ReturnClaim::RESOLUTIONS)]
        public string $resolution,
        #[Assert\Length(max: 500)]
        public ?string $note = null,
    ) {}
}
