<?php

declare(strict_types=1);

namespace App\Admin;

use App\Order\OrderStatus;
use App\Order\PaymentStatus;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AdminOrdersQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Choice(choices: OrderStatus::ALL)]
        public ?string $status = null,
        #[Assert\Choice(choices: PaymentStatus::ALL)]
        public ?string $paymentStatus = null,
    ) {}
}
