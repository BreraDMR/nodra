<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/** Cancelling, failing, returning, refusing or a correction: the reason goes to the order journal, so spaces alone don't count. */
final readonly class ReasonRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim'), Assert\Length(max: 500)]
        public string $reason,
    ) {}
}
