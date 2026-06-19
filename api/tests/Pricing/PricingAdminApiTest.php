<?php

declare(strict_types=1);

namespace App\Tests\Pricing;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PricingAdminApiTest extends ApiTestCase
{
    private string $token;
    private Category $drivetrain;
    private Category $chains;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginAdmin();
        $b = $this->builder();
        $parts = $b->category('t-parts');
        $this->drivetrain = $b->category('t-drivetrain', $parts);
        $this->chains = $b->category('t-chains', $this->drivetrain);
        $b->rule($this->drivetrain, 0, 100000, 3500);
        $b->rule($this->chains, 50000, 80000, 2500);
        $this->product = $b->product('t-chain', $this->chains);
    }

    public function testDefaultRulesAreSeededAndListed(): void
    {
        $rules = $this->getJson('/api/admin/pricing-rules');

        self::assertResponseIsSuccessful();
        $defaults = array_values(array_filter($rules, static fn (array $r): bool => $r['categoryId'] === null));
        self::assertSame([[0, 30000, 6000], [30000, 100000, 4000], [100000, 300000, 3000], [300000, null, 2000]],
            array_map(static fn (array $r): array => [$r['minCostCzkMinor'], $r['maxCostCzkMinor'], $r['markupBp']], $defaults));
        // default rules come first, then by category
        self::assertNull($rules[0]['categoryId']);
        self::assertSame(['t-chains', 't-drivetrain'], array_values(array_filter(array_column($rules, 'categorySlug'))));
    }

    public function testPricingThresholdsAreExposed(): void
    {
        $settings = $this->getJson('/api/admin/pricing/settings');

        self::assertResponseIsSuccessful();
        self::assertSame(['minMarginBp' => 1000, 'aboveMarketBp' => 1500], $settings);
    }

    public function testRulesAreWrittenAndBandsMayNotOverlap(): void
    {
        $chains = $this->chains->getId()->toRfc4122();
        $created = $this->sendJson('POST', '/api/admin/pricing-rules', ['categoryId' => $chains, 'minCostCzkMinor' => 80000, 'markupBp' => 2000], $this->token);
        self::assertResponseStatusCodeSame(201);

        // [70000, 90000) would overlap both [50000, 80000) and [80000, ∞)
        $this->sendJson('POST', '/api/admin/pricing-rules', ['categoryId' => $chains, 'minCostCzkMinor' => 70000, 'maxCostCzkMinor' => 90000, 'markupBp' => 2000], $this->token);
        self::assertResponseStatusCodeSame(409);
        // an inactive one may, and so may the same band on another category
        $this->sendJson('POST', '/api/admin/pricing-rules', ['categoryId' => $chains, 'minCostCzkMinor' => 70000, 'maxCostCzkMinor' => 90000, 'markupBp' => 2000, 'active' => false], $this->token);
        self::assertResponseStatusCodeSame(201);
        $this->sendJson('POST', '/api/admin/pricing-rules', ['categoryId' => $this->drivetrain->getId()->toRfc4122(), 'minCostCzkMinor' => 100000, 'markupBp' => 2000], $this->token);
        self::assertResponseStatusCodeSame(201);

        $this->sendJson('POST', '/api/admin/pricing-rules', ['minCostCzkMinor' => 500, 'maxCostCzkMinor' => 400, 'markupBp' => 2000], $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', '/api/admin/pricing-rules', ['categoryId' => '01890000-0000-7000-8000-000000000000', 'minCostCzkMinor' => 0, 'markupBp' => 2000], $this->token);
        self::assertResponseStatusCodeSame(422);
        $error = $this->sendJson('POST', '/api/admin/pricing-rules', ['minCostCzkMinor' => -1, 'markupBp' => 100001], $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['minCostCzkMinor', 'markupBp'], array_column($error['violations'], 'field'));
        // default bands overlap the seeded ones
        $this->sendJson('POST', '/api/admin/pricing-rules', ['minCostCzkMinor' => 0, 'maxCostCzkMinor' => 10000, 'markupBp' => 9000], $this->token);
        self::assertResponseStatusCodeSame(409);

        $this->sendJson('PUT', '/api/admin/pricing-rules/'.$created['id'], ['categoryId' => $chains, 'minCostCzkMinor' => 80000, 'maxCostCzkMinor' => 200000, 'markupBp' => 1800], $this->token);
        self::assertResponseIsSuccessful();
        $rule = array_column($this->getJson('/api/admin/pricing-rules'), null, 'id')[$created['id']];
        self::assertSame([$chains, 't-chains', 80000, 200000, 1800, true], [$rule['categoryId'], $rule['categorySlug'], $rule['minCostCzkMinor'], $rule['maxCostCzkMinor'], $rule['markupBp'], $rule['active']]);

        $this->sendJson('DELETE', '/api/admin/pricing-rules/'.$created['id'], [], $this->token);
        self::assertResponseStatusCodeSame(204);
        $this->sendJson('DELETE', '/api/admin/pricing-rules/'.$created['id'], [], $this->token);
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('PUT', '/api/admin/pricing-rules/'.$created['id'], ['minCostCzkMinor' => 0, 'markupBp' => 1], $this->token);
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('DELETE', '/api/admin/pricing-rules/'.$created['id'], []);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPanelShowsTheLandedCostBreakdownRuleAndSuggestion(): void
    {
        $b = $this->builder();
        $variant = $b->variant($this->product, 'T-CHAIN-EUR');
        // 20 € + 2 € shipping at 25.0 = 550 Kč, chains band 500-800 Kč: +25 % = 687.50 -> 690 Kč
        $offer = $b->pricedOffer($this->product, $variant, 2000, 'EUR', 25_000_000, 200, 2, 5);
        $b->pricedOffer($this->product, $variant, 2300, 'EUR', 25_000_000); // 575 Kč, dearer, not used

        $panel = $this->getJson('/api/admin/variants/'.$variant->getId()->toRfc4122().'/pricing');

        self::assertResponseIsSuccessful();
        self::assertSame([100000, 4000, 8181], [$panel['priceCzk'], $panel['priceEur'], $panel['currentMarginBp']]);
        self::assertSame(['status' => 'orderable', 'leadTimeMinDays' => 3, 'leadTimeMaxDays' => 6, 'reason' => null], $panel['availability']);
        self::assertSame([$offer->getId()->toRfc4122(), 'bike24', 'EUR', 2000, 200, 25_000_000, '2026-09-25', 2, 5, 55000], [
            $panel['cost']['offerId'], $panel['cost']['supplier'], $panel['cost']['currency'], $panel['cost']['priceMinor'], $panel['cost']['inboundShippingMinor'],
            $panel['cost']['fxRateCzk'], $panel['cost']['fxRateDate'], $panel['cost']['leadTimeMinDays'], $panel['cost']['leadTimeMaxDays'], $panel['cost']['landedCostCzk'],
        ]);
        self::assertSame(['t-chains', 50000, 80000, 2500], [$panel['rule']['categorySlug'], $panel['rule']['minCostCzkMinor'], $panel['rule']['maxCostCzkMinor'], $panel['rule']['markupBp']]);
        self::assertSame(['priceCzk' => 69000, 'priceEur' => 2760, 'markupPriceCzk' => 69000, 'rrpCapCzk' => null, 'rrpCapped' => false,
            'floorPriceCzk' => 61000, 'marginBp' => 2545, 'aboveMarket' => false], $panel['suggestion']);
        self::assertSame([[], true], [$panel['flags'], $panel['applicable']]);

        $this->getJson('/api/admin/variants/01890000-0000-7000-8000-000000000000/pricing');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRulePrecedenceThroughTheTree(): void
    {
        $b = $this->builder();
        $inBand = $b->variant($this->product, 'T-IN-BAND');
        $b->pricedOffer($this->product, $inBand, 60000);
        $parentBand = $b->variant($this->product, 'T-PARENT-BAND');
        $b->pricedOffer($this->product, $parentBand, 40000);
        $defaultBand = $b->variant($this->product, 'T-DEFAULT-BAND');
        $b->pricedOffer($this->product, $defaultBand, 150000);

        self::assertSame(['t-chains', 75000], $this->ruleAndPrice($inBand));
        self::assertSame(['t-drivetrain', 54000], $this->ruleAndPrice($parentBand));
        self::assertSame([null, 195000], $this->ruleAndPrice($defaultBand));
    }

    public function testRrpCapFloorAndMarketFlags(): void
    {
        $b = $this->builder();
        $capped = $b->variant($this->product, 'T-CAPPED');
        $capped->setReferencePrices(2800, 'EUR', 'https://maker.example/rrp', new \DateTimeImmutable('2026-09-20'), null, null, null);
        $b->pricedOffer($this->product, $capped, 60000); // 750 Kč by the rule, RRP 700 Kč caps it at 670 Kč
        $low = $b->variant($this->product, 'T-LOW');
        $low->setReferencePrices(60000, 'CZK', null, null, null, null, null);
        $b->pricedOffer($this->product, $low, 60000); // cap 580 Kč < floor 660 Kč
        $market = $b->variant($this->product, 'T-MARKET');
        $market->setReferencePrices(null, null, null, null, 60000, 'Heureka', new \DateTimeImmutable('2026-09-27'));
        $b->pricedOffer($this->product, $market, 60000);
        $this->flush();

        $panel = $this->panel($capped);
        self::assertSame([70000, 67000, true, 670 * 100, []], [$panel['rrpCzk'], $panel['suggestion']['rrpCapCzk'], $panel['suggestion']['rrpCapped'], $panel['suggestion']['priceCzk'], $panel['flags']]);
        self::assertSame([2800, 'EUR', 'https://maker.example/rrp', '2026-09-20'], [$panel['rrpMinor'], $panel['rrpCurrency'], $panel['rrpSource'], $panel['rrpCheckedAt']]);

        $panel = $this->panel($low);
        self::assertSame([66000, ['margin_too_low'], false], [$panel['suggestion']['priceCzk'], $panel['flags'], $panel['applicable']]);

        // current 1000 Kč and suggested 750 Kč are both above 600 Kč x 1.15
        $panel = $this->panel($market);
        self::assertSame([['above_market'], true, true, 60000, 'Heureka', '2026-09-27'], [$panel['flags'], $panel['suggestion']['aboveMarket'], $panel['applicable'],
            $panel['marketPriceMinor'], $panel['marketPriceSource'], $panel['marketCheckedAt']]);
    }

    public function testApplySuggestionWritesHistoryAndRefusesStaleOrFlaggedPrices(): void
    {
        $b = $this->builder();
        $variant = $b->variant($this->product, 'T-APPLY');
        $offer = $b->pricedOffer($this->product, $variant, 60000);
        $low = $b->variant($this->product, 'T-APPLY-LOW');
        $low->setReferencePrices(60000, 'CZK', null, null, null, null, null);
        $b->pricedOffer($this->product, $low, 60000);
        $this->flush();
        $uri = '/api/admin/variants/'.$variant->getId()->toRfc4122().'/pricing/apply';

        $this->sendJson('POST', $uri, ['suggestedPriceCzk' => 75000]);
        self::assertResponseStatusCodeSame(403);
        $applied = $this->sendJson('POST', $uri, ['suggestedPriceCzk' => 75000], $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(['variantId' => $variant->getId()->toRfc4122(), 'oldPriceCzk' => 100000, 'newPriceCzk' => 75000, 'oldPriceEur' => 4000, 'newPriceEur' => 3000, 'changed' => true], $applied);
        self::assertSame([75000, 3000], $this->prices($variant));
        $history = $this->getJson('/api/admin/variants/'.$variant->getId()->toRfc4122().'/price-history');
        self::assertSame(1, $history['total']);
        self::assertSame(['reprice', 'test-admin@nodra.test', 100000, 75000, 4000, 3000], [
            $history['items'][0]['reason'], $history['items'][0]['changedBy'], $history['items'][0]['oldPriceCzk'],
            $history['items'][0]['newPriceCzk'], $history['items'][0]['oldPriceEur'], $history['items'][0]['newPriceEur'],
        ]);

        // same price again: nothing to write
        self::assertFalse($this->sendJson('POST', $uri, ['suggestedPriceCzk' => 75000], $this->token)['changed']);
        self::assertSame(1, $this->historyCount($variant));

        // the supplier got dearer after the panel was loaded
        $this->db()->executeStatement('UPDATE supplier_offer SET price_minor = 64000 WHERE id = :id', ['id' => $offer->getId()->toRfc4122()]);
        $stale = $this->sendJson('POST', $uri, ['suggestedPriceCzk' => 75000], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([$variant->getId()->toRfc4122()], $stale['staleVariantIds']);
        self::assertSame([75000, 3000], $this->prices($variant));

        $this->sendJson('POST', '/api/admin/variants/'.$low->getId()->toRfc4122().'/pricing/apply', ['suggestedPriceCzk' => 66000], $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([100000, 4000], $this->prices($low));

        $this->sendJson('POST', '/api/admin/variants/01890000-0000-7000-8000-000000000000/pricing/apply', ['suggestedPriceCzk' => 1], $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRepricePreviewAndApplyForASubtree(): void
    {
        $b = $this->builder();
        $a = $b->variant($this->product, 'T-RP-A');
        $b->pricedOffer($this->product, $a, 60000); // 750 Kč
        $same = $b->variant($this->product, 'T-RP-SAME', priceCzk: 54000);
        $b->pricedOffer($this->product, $same, 40000); // 540 Kč, already the price
        $flagged = $b->variant($this->product, 'T-RP-LOW');
        $flagged->setReferencePrices(60000, 'CZK', null, null, null, null, null);
        $b->pricedOffer($this->product, $flagged, 60000);
        $b->variant($this->product, 'T-RP-NO-OFFER');
        $b->variant($this->product, 'T-RP-OFF', active: false);
        $elsewhere = $b->product('t-elsewhere', $b->category('t-bags'));
        $b->pricedOffer($elsewhere, $b->variant($elsewhere, 'T-RP-ELSEWHERE'), 60000);
        $this->flush();

        $preview = $this->getJson('/api/admin/pricing/reprice', ['categoryId' => $this->drivetrain->getId()->toRfc4122()]);

        self::assertResponseIsSuccessful();
        self::assertSame([4, 1], [$preview['variants'], $preview['withoutSuggestion']]);
        $rows = array_column($preview['rows'], null, 'sku');
        self::assertSame(['T-RP-A', 'T-RP-LOW', 'T-RP-SAME'], array_keys($rows));
        self::assertSame([100000, 60000, 6666, 75000, 3000, 2500, true, true, []], [
            $rows['T-RP-A']['priceCzk'], $rows['T-RP-A']['landedCostCzk'], $rows['T-RP-A']['currentMarginBp'], $rows['T-RP-A']['suggestedPriceCzk'],
            $rows['T-RP-A']['suggestedPriceEur'], $rows['T-RP-A']['suggestedMarginBp'], $rows['T-RP-A']['changed'], $rows['T-RP-A']['applicable'], $rows['T-RP-A']['flags'],
        ]);
        self::assertSame([false, true], [$rows['T-RP-SAME']['changed'], $rows['T-RP-SAME']['applicable']]);
        self::assertSame([['margin_too_low'], false], [$rows['T-RP-LOW']['flags'], $rows['T-RP-LOW']['applicable']]);
        // the whole catalogue also has the bags variant
        self::assertContains('T-RP-ELSEWHERE', array_column($this->getJson('/api/admin/pricing/reprice')['rows'], 'sku'));

        $item = static fn (ProductVariant $v, int $price): array => ['variantId' => $v->getId()->toRfc4122(), 'suggestedPriceCzk' => $price];

        // a flagged row can't be applied, and then nothing is
        $this->sendJson('POST', '/api/admin/pricing/reprice', ['items' => [$item($a, 75000), $item($flagged, 66000)]], $this->token);
        self::assertResponseStatusCodeSame(422);
        // one stale row stops the whole batch
        $stale = $this->sendJson('POST', '/api/admin/pricing/reprice', ['items' => [$item($a, 75000), $item($same, 99000)]], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([$same->getId()->toRfc4122()], $stale['staleVariantIds']);
        self::assertSame([100000, 4000], $this->prices($a));
        self::assertSame(0, $this->historyCount($a));

        $result = $this->sendJson('POST', '/api/admin/pricing/reprice', ['items' => [$item($a, 75000), $item($same, 54000)]], $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $result['applied']);
        self::assertSame([75000, 3000], $this->prices($a));
        self::assertSame([[true, 1], [false, 0]], [[$result['items'][0]['changed'], $this->historyCount($a)], [$result['items'][1]['changed'], $this->historyCount($same)]]);
        self::assertSame('reprice', $this->db()->fetchOne('SELECT reason FROM price_change WHERE variant_id = :id', ['id' => $a->getId()->toRfc4122()]));

        $this->sendJson('POST', '/api/admin/pricing/reprice', ['items' => [$item($a, 75000), $item($a, 75000)]], $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', '/api/admin/pricing/reprice', ['items' => []], $this->token);
        self::assertResponseStatusCodeSame(422);
        $error = $this->sendJson('POST', '/api/admin/pricing/reprice', ['items' => [['variantId' => 'nope', 'suggestedPriceCzk' => -1]]], $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['items[0].variantId', 'items[0].suggestedPriceCzk'], array_column($error['violations'], 'field'));
        $this->sendJson('POST', '/api/admin/pricing/reprice', ['items' => [['variantId' => $a->getId()->toRfc4122()]]], $this->token);
        self::assertResponseStatusCodeSame(422);
        // an upper-case id is the same category
        self::assertSame(4, $this->getJson('/api/admin/pricing/reprice', ['categoryId' => strtoupper($this->drivetrain->getId()->toRfc4122())])['variants']);
        $this->sendJson('POST', '/api/admin/pricing/reprice', ['items' => [['variantId' => '01890000-0000-7000-8000-000000000000', 'suggestedPriceCzk' => 1]]], $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->getJson('/api/admin/pricing/reprice', ['categoryId' => '01890000-0000-7000-8000-000000000000']);
        self::assertResponseStatusCodeSame(404);
        $this->getJson('/api/admin/pricing/reprice', ['categoryId' => 'nope']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAlertsCountPublishedVariantsNeedingAttention(): void
    {
        $b = $this->builder();
        $low = $b->variant($this->product, 'T-AL-LOW');
        $low->setReferencePrices(60000, 'CZK', null, null, null, null, null);
        $b->pricedOffer($this->product, $low, 60000);
        $market = $b->variant($this->product, 'T-AL-MARKET');
        $market->setReferencePrices(null, null, null, null, 80000, null, null); // 1000 Kč > 920 Kč
        $b->pricedOffer($this->product, $market, 60000);
        $stale = $b->variant($this->product, 'T-AL-STALE');
        $b->pricedOffer($this->product, $stale, 60000, checkedAt: new \DateTimeImmutable('-8 days'));
        $b->variant($this->product, 'T-AL-NOTHING'); // unavailable, not a check
        $draft = $b->product('t-draft', $this->chains, status: 'draft');
        $b->pricedOffer($draft, $b->variant($draft, 'T-AL-DRAFT'), 60000, checkedAt: new \DateTimeImmutable('-8 days'));
        $this->flush();

        self::assertSame(['marginTooLow' => 1, 'aboveMarket' => 1, 'checkNeeded' => 1], $this->getJson('/api/admin/pricing/alerts'));
    }

    public function testCheckNeededLeavesOutVariantsSoldFromOwnStock(): void
    {
        $b = $this->builder();
        $b->pricedOffer($this->product, $b->variant($this->product, 'T-AL-STALE-2'), 60000, checkedAt: new \DateTimeImmutable('-8 days'));
        // NODRA holds two, the stale offer doesn't matter until they're sold
        $b->pricedOffer($this->product, $b->variant($this->product, 'T-AL-HELD', stock: 2), 60000, checkedAt: new \DateTimeImmutable('-8 days'));
        $this->flush();

        self::assertSame(1, $this->getJson('/api/admin/pricing/alerts')['checkNeeded']);
    }

    private function ruleAndPrice(ProductVariant $variant): array
    {
        $panel = $this->panel($variant);

        return [$panel['rule']['categorySlug'], $panel['suggestion']['priceCzk']];
    }

    private function panel(ProductVariant $variant): array
    {
        $panel = $this->getJson('/api/admin/variants/'.$variant->getId()->toRfc4122().'/pricing');
        self::assertResponseIsSuccessful();

        return $panel;
    }

    private function prices(ProductVariant $variant): array
    {
        $row = $this->db()->fetchAssociative('SELECT price_czk, price_eur FROM product_variant WHERE id = :id', ['id' => $variant->getId()->toRfc4122()]);

        return [(int) $row['price_czk'], (int) $row['price_eur']];
    }

    private function historyCount(ProductVariant $variant): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM price_change WHERE variant_id = :id', ['id' => $variant->getId()->toRfc4122()]);
    }

    private function flush(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }
}
