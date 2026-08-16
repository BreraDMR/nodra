<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class InstallationRescheduleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $from,
        #[Assert\NotBlank]
        public string $to,
    ) {}
}
