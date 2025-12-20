<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OrderStatusRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['processing', 'shipped', 'completed', 'cancelled'])]
        public string $status,
    ) {}
}
