<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AccountHistoryQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
    ) {}
}
