<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClaimWaitRequest
{
    public function __construct(
        #[Assert\Length(max: 500)]
        public ?string $note = null,
    ) {}
}
