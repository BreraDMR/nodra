<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\ShopOrder;
use PHPUnit\Framework\TestCase;

final class CommerceInvariantTest extends TestCase
{
    private function variant(): ProductVariant
    {
        $product = new Product('sample-bag', 'bags', ['en' => ['name' => 'Sample', 'short' => 'Sample', 'description' => 'Sample', 'details' => []]], '/images/pannier.png');
        return new ProductVariant($product, 'ND-SAMPLE', ['en' => '12 L'], 329000, 13900, 2);
    }

    public function testStockCannotBecomeNegative(): void
    {
        $variant = $this->variant();
        $variant->adjustStock(-2);
        self::assertSame(0, $variant->getStock());
        $this->expectException(\DomainException::class);
        $variant->adjustStock(-1);
    }

    public function testOrderTotalAndTransitionSequence(): void
    {
        $order = new ShopOrder(str_repeat('a', 20), str_repeat('f', 64), 'en', 'EUR', 'Demo Rider', 'rider@example.test', 'DE', 'Demo 12', '10115', 13900, 690);
        self::assertSame(14590, $order->getTotalMinor());
        $order->advanceTo('processing');
        $order->advanceTo('shipped');
        $order->advanceTo('completed');
        self::assertSame('completed', $order->getStatus());
        $this->expectException(\DomainException::class);
        $order->advanceTo('cancelled');
    }

    public function testCancellationCannotBeShipped(): void
    {
        $order = new ShopOrder(str_repeat('b', 20), str_repeat('f', 64), 'cs', 'CZK', 'Demo Rider', 'rider@example.test', 'CZ', 'Demo 12', '11000', 329000, 8900);
        $order->advanceTo('cancelled');
        $this->expectException(\DomainException::class);
        $order->advanceTo('shipped');
    }
}
