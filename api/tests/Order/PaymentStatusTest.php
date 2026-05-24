<?php

declare(strict_types=1);

namespace App\Tests\Order;

use App\Order\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    public function testStatusFollowsTheLedgerAndTheCurrentTotal(): void
    {
        $cases = [
            'nothing yet' => [0, 0, 64800, 'unpaid'],
            'part at the first handover' => [30000, 0, 64800, 'partially_paid'],
            'all of it' => [64800, 0, 64800, 'paid'],
            // a cancelled line lowered the total: still paid, the difference is a refund due
            'more than the new total' => [64800, 0, 34800, 'paid'],
            'part went back' => [64800, 30000, 34800, 'partially_refunded'],
            'everything went back' => [64800, 64800, 0, 'refunded'],
        ];
        foreach ($cases as $name => [$payments, $refunds, $total, $status]) {
            self::assertSame($status, PaymentStatus::of($payments, $refunds, $total), $name);
        }
    }

    public function testOrderCanCompleteOnlyWhenTheMoneyCoversTheTotal(): void
    {
        self::assertFalse(PaymentStatus::settles(0, 0, 0));
        self::assertFalse(PaymentStatus::settles(30000, 0, 64800));
        self::assertTrue(PaymentStatus::settles(64800, 0, 64800));
        // the money of a cancelled line was given back before completion
        self::assertTrue(PaymentStatus::settles(64800, 30000, 34800));
        self::assertFalse(PaymentStatus::settles(64800, 40000, 34800));
        self::assertFalse(PaymentStatus::settles(64800, 64800, 0));
    }
}
