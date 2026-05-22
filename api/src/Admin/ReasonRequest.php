<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/** Cancelling, failing, returning or refusing: the reason goes to the order journal. */
final readonly class ReasonRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 500)]
        public string $reason,
    ) {}
}
