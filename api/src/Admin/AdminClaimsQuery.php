<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\ReturnClaim;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AdminClaimsQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Choice(choices: ReturnClaim::STATUSES)]
        public ?string $status = null,
        #[Assert\Choice(choices: ReturnClaim::KINDS)]
        public ?string $kind = null,
        /** claim number, order reference, customer name or email */
        #[Assert\Length(max: 100)]
        public ?string $q = null,
    ) {}
}
