<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Tests\Support\ApiTestCase;

/**
 * The money is CZK in every language (D00.5): the catalog card, the basket quote and the order show the same
 * koruna amount for cs, de and en, and euro only rides along as a reference the shop never calculates with.
 */
final class ProductCurrencyTest extends ApiTestCase
{
    public function testCatalogQuoteAndOrderAgreeOnOneKorunaAmountForEveryLocale(): void
    {
        $b = $this->builder();
        $product = $b->product('t-money', $b->category('t-money-cat'));
        $inStock = $b->variant($product, 'T-MONEY-IN', stock: 2, priceCzk: 100000);
        $b->variant($product, 'T-MONEY-OUT', stock: 0, priceCzk: 120000);

        // the card price is the same CZK amount on every language; euro is a reference, never the calculation
        $byLocale = [];
        foreach (['cs', 'de', 'en'] as $locale) {
            $detail = $this->getJson('/api/products/t-money', ['locale' => $locale]);
            self::assertResponseIsSuccessful();
            [$in, $out] = $detail['variants'];
            self::assertSame(['amount' => 100000, 'currency' => 'CZK'], $in['price'], $locale);
            self::assertSame(['amount' => 4000, 'currency' => 'EUR'], $in['priceEur'], $locale);
            self::assertSame(['amount' => 100000, 'currency' => 'CZK'], $detail['fromPrice'], $locale);
            self::assertSame(['amount' => 4000, 'currency' => 'EUR'], $detail['fromPriceEur'], $locale);
            $byLocale[$locale] = [$detail['fromPrice']['amount'], $in['price']['amount']];
        }
        self::assertSame(['cs' => [100000, 100000], 'de' => [100000, 100000], 'en' => [100000, 100000]], $byLocale);

        $listing = $this->getJson('/api/products', ['locale' => 'de', 'category' => 't-money-cat']);
        self::assertSame(['amount' => 100000, 'currency' => 'CZK'], $listing['items'][0]['fromPrice'], 'de listing');
        self::assertSame(['amount' => 4000, 'currency' => 'EUR'], $listing['items'][0]['fromPriceEur'], 'de listing');

        // the basket quote prices the de basket from the same koruna amount the card showed
        $quote = $this->quote([$inStock->getId()->toRfc4122() => 1], 'prague_personal', '120 00', 'de');
        self::assertSame('CZK', $quote['currency']);
        self::assertSame(100000, $quote['lines'][0]['unitPrice']);
        self::assertSame(100000, $quote['subtotal']['amount'], 'free personal delivery from 500 Kč');

        // and the order charges exactly that amount
        $receipt = $this->checkout([$inStock->getId()->toRfc4122() => 1], 'prague_personal', 'together', locale: 'de');
        self::assertResponseStatusCodeSame(201);
        $this->loginAdmin();
        $order = $this->getJson('/api/admin/orders/'.$this->orderId($receipt['reference']));
        self::assertSame(['amount' => 100000, 'currency' => 'CZK'], $order['total']);
        self::assertSame('CZK', $order['currency']);
    }
}
