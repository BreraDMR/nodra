<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RepricePreviewQuery
{
    public function __construct(
        #[Assert\Uuid] public ?string $categoryId = null,
    ) {}
}
