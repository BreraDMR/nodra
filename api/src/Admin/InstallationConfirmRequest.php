<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class InstallationConfirmRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public string $compatibilityNote,
        #[Assert\Length(max: 40)]
        public ?string $workFrom = null,
        #[Assert\Length(max: 40)]
        public ?string $workTo = null,
    ) {}
}
