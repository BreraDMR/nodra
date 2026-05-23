<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkOrderedRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 120)]
        public string $supplierReference,
    ) {}
}
