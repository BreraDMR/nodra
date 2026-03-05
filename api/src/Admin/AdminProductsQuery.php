<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AdminProductsQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Length(max: 80)]
        public ?string $q = null,
    ) {}
}
