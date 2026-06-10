<?php

declare(strict_types=1);

namespace App\Admin;

use App\Purchase\PurchaseStatus;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AdminPurchasesQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Choice(choices: PurchaseStatus::ALL)]
        public ?string $status = null,
    ) {}
}
