<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\ShopOrder;
use PHPUnit\Framework\TestCase;

final class CommerceInvariantTest extends TestCase
{
    private function variant(): ProductVariant
    {
        $product = new Product('sample-bag', new Category('bags', ['cs' => 'Brašny', 'de' => 'Taschen', 'en' => 'Bags']), ['en' => ['name' => 'Sample', 'short' => 'Sample', 'description' => 'Sample', 'details' => []]], '/images/pannier.png');
        return new ProductVariant($product, 'ND-SAMPLE', ['en' => '12 L'], 329000, 13900, 2);
    }

    private function order(): ShopOrder
    {
        return new ShopOrder(str_repeat('a', 20), str_repeat('f', 64), 'en', [
            'name' => 'Demo Rider', 'email' => 'rider@example.test', 'phone' => '+420777123456', 'contactChannel' => 'whatsapp',
            'address' => 'Demo 12', 'city' => 'Praha', 'postalCode' => '110 00', 'district' => 'Praha 1', 'deliveryNote' => null,
        ], 'together', 'draft-2026-09', false);
    }

    public function testStockCannotBecomeNegative(): void
    {
        $variant = $this->variant();
        $variant->adjustStock(-2);
        self::assertSame(0, $variant->getStock());
        $this->expectException(\DomainException::class);
        $variant->adjustStock(-1);
    }

    public function testNewOrderIsARequestInCzkWithItsConsent(): void
    {
        $order = $this->order();
        $order->setTotals(13900, 14900);

        self::assertSame(['requested', 'unpaid', 'CZK', 28800], [$order->getStatus(), $order->getPaymentStatus(), $order->getCurrency(), $order->getTotalMinor()]);
        self::assertSame('draft-2026-09', $order->getPrivacyTextVersion());
        self::assertNotNull($order->getPrivacyConsentedAt());
        self::assertNull($order->getMarketingConsentedAt());
    }

    public function testOrderTransitionSequence(): void
    {
        $order = $this->order();
        $order->confirm();
        // terms changed after the customer agreed
        $order->reopen();
        $order->confirm();
        $order->complete();
        self::assertSame('completed', $order->getStatus());
        $this->expectException(\DomainException::class);
        $order->cancel();
    }

    public function testRequestCannotCompleteAndCancelledIsFinal(): void
    {
        $order = $this->order();
        try {
            $order->complete();
            self::fail('A requested order completed');
        } catch (\DomainException) {
        }
        $order->cancel();
        $this->expectException(\DomainException::class);
        $order->confirm();
    }
}
