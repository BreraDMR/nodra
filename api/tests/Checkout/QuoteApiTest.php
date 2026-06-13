<?php

declare(strict_types=1);

namespace App\Tests\Checkout;

use App\Entity\Product;
use App\Tests\Support\ApiTestCase;

final class QuoteApiTest extends ApiTestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = $this->builder()->product('t-quote', $this->builder()->category('t-quote-cat'));
    }

    public function testPragueDeliveryCosts149BelowAndNothingFromExactly500Kc(): void
    {
        $below = $this->offered('T-Q-BELOW', 49999);
        $exactly = $this->offered('T-Q-EXACT', 50000);

        $cheap = $this->quote([$below => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame([49999, 14900, 64899], [$cheap['subtotal']['amount'], $cheap['options']['together']['shipping']['amount'], $cheap['options']['together']['total']['amount']]);
        self::assertSame(['CZK', 'CZK'], [$cheap['currency'], $cheap['options']['together']['total']['currency']]);

        $free = $this->quote([$exactly => 1]);
        self::assertSame([50000, 0, 50000], [$free['subtotal']['amount'], $free['options']['together']['shipping']['amount'], $free['options']['together']['total']['amount']]);
        self::assertSame(['method' => 'prague_personal', 'available' => true, 'reason' => null, 'fee' => ['amount' => 0, 'currency' => 'CZK'], 'note' => null, 'freeFromMinor' => 50000], $free['methods'][1]);
        // the threshold comes with the method so the storefront can say "free from 500 Kč"
        self::assertSame([null, 50000], array_column($cheap['methods'], 'freeFromMinor'));
    }

    public function testSplitPaysEveryPartBelow500AndNoneFrom500(): void
    {
        $held = $this->held('T-Q-HELD', 20000);
        $offered = $this->offered('T-Q-OFFER', 20000);

        $below = $this->quote([$held => 1, $offered => 1]);
        self::assertSame([14900, 29800], [$below['options']['together']['shipping']['amount'], $below['options']['split']['shipping']['amount']]);
        self::assertSame([[$held], [$offered]], array_column($below['options']['split']['shipments'], 'variantIds'));
        self::assertSame([[1, 1], [3, 6]], array_map(static fn (array $s): array => [$s['leadTimeMinDays'], $s['leadTimeMaxDays']], $below['options']['split']['shipments']));
        self::assertSame([3, 6], [$below['options']['together']['shipments'][0]['leadTimeMinDays'], $below['options']['together']['shipments'][0]['leadTimeMaxDays']]);

        $from = $this->quote([$held => 2, $offered => 1]);
        self::assertSame([60000, 0, 0], [$from['subtotal']['amount'], $from['options']['together']['shipping']['amount'], $from['options']['split']['shipping']['amount']]);
    }

    public function testLinesShowAvailabilityAndTheDateToConfirmIsItsOwnPart(): void
    {
        $offered = $this->offered('T-Q-OFFERED', 30000);
        $snapshot = $this->builder()->variant($this->product, 'T-Q-SNAP', priceCzk: 30000);
        $this->builder()->pricedOffer($this->product, $snapshot, 30000, checkedAt: new \DateTimeImmutable('-20 days'));
        $snapshotId = $snapshot->getId()->toRfc4122();

        $quote = $this->quote([$offered => 1, $snapshotId => 1], 'pickup_andel');

        $lines = array_column($quote['lines'], 'availability', 'variantId');
        self::assertSame(['status' => 'check_needed', 'leadTimeMinDays' => null, 'leadTimeMaxDays' => null], $lines[$snapshotId]);
        self::assertSame([[$offered], [$snapshotId]], array_column($quote['options']['split']['shipments'], 'variantIds'));
        self::assertNull($quote['options']['together']['shipments'][0]['leadTimeMaxDays']);
        self::assertSame(['Místo a čas předání na Andělu domluvíme zprávou.', 0], [$quote['methods'][0]['note'], $quote['options']['split']['shipping']['amount']]);
        self::assertTrue($quote['canCheckout']);
    }

    public function testMethodsAreCheckedAgainstThePostalCodeAndCarrierIsOff(): void
    {
        $item = [$this->offered('T-Q-WHERE', 30000) => 1];

        $brno = $this->quote($item, 'prague_personal', '602 00');
        self::assertResponseIsSuccessful();
        self::assertSame(['pickup_andel', 'prague_personal'], array_column($brno['methods'], 'method'));
        self::assertSame([false, 'outside_prague', null], [$brno['methods'][1]['available'], $brno['methods'][1]['reason'], $brno['methods'][1]['fee']]);
        self::assertSame([null, false, '602 00'], [$brno['options'], $brno['canCheckout'], $brno['delivery']['postalCode']]);
        self::assertSame('postal_code_required', $this->quote($item, 'prague_personal', null)['methods'][1]['reason']);

        $carrier = $this->quote($item, 'carrier_cz', '602 00');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['method_unavailable', 'not_offered'], [$carrier['code'], $carrier['reason']]);
    }

    public function testPickupNoteIsInTheLanguageOfTheQuote(): void
    {
        $item = [$this->offered('T-Q-NOTE', 30000) => 1];

        $notes = [];
        foreach (['cs', 'de', 'en'] as $locale) {
            $notes[$locale] = $this->quote($item, 'pickup_andel', null, $locale)['methods'][0]['note'];
        }

        self::assertSame([
            'cs' => 'Místo a čas předání na Andělu domluvíme zprávou.',
            'de' => 'Ort und Zeit der Übergabe am Anděl vereinbaren wir per Nachricht.',
            'en' => 'We agree the place and time at Anděl by message.',
        ], $notes);
    }

    public function testUnavailableLineBlocksTheOptionsAndUnknownVariantIsRefused(): void
    {
        $gone = $this->builder()->variant($this->product, 'T-Q-GONE');
        $this->builder()->pricedOffer($this->product, $gone, 30000, reportedQuantity: 0);

        $quote = $this->quote([$gone->getId()->toRfc4122() => 1]);
        self::assertSame(['unavailable', null, false], [$quote['lines'][0]['availability']['status'], $quote['options'], $quote['canCheckout']]);

        $error = $this->quote(['01890000-0000-7000-8000-000000000000' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['product_unavailable', '01890000-0000-7000-8000-000000000000'], [$error['code'], $error['variantId']]);
    }

    public function testQuoteWritesNothing(): void
    {
        $held = $this->held('T-Q-NOWRITE', 30000);
        $count = fn (): array => array_map('intval', $this->db()->fetchAssociative('SELECT (SELECT COUNT(*) FROM shop_order) AS orders, (SELECT COUNT(*) FROM stock_movement) AS movements, (SELECT stock FROM product_variant WHERE id = :id) AS stock', ['id' => $held]));
        $before = $count();

        $this->quote([$held => 2]);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $count());
    }

    private function offered(string $sku, int $priceCzk): string
    {
        $variant = $this->builder()->variant($this->product, $sku, priceCzk: $priceCzk);
        $this->builder()->pricedOffer($this->product, $variant, 20000);

        return $variant->getId()->toRfc4122();
    }

    private function held(string $sku, int $priceCzk): string
    {
        return $this->builder()->variant($this->product, $sku, stock: 2, priceCzk: $priceCzk)->getId()->toRfc4122();
    }
}
