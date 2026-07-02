<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Entity\Category;
use App\Entity\Product;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The feed import over HTTP: preview report, matching rules, apply, the run journal and the
 * supplier settings. Nothing here touches the storefront data a real import would.
 */
final class ImportApiTest extends ApiTestCase
{
    private string $csrf;
    private Category $components;
    private Category $cassettes;
    private Product $seed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csrf = $this->loginAdmin();
    }

    public function testPreviewReportsCountsAndCostWithoutWriting(): void
    {
        $this->catalog();
        $before = $this->counts();

        $report = $this->preview(AwinFeed::csv());

        self::assertResponseIsSuccessful();
        self::assertSame(3, $report['totalRows']);
        self::assertSame(['totalRows' => 3, 'newProducts' => 3, 'updates' => 0, 'conflicts' => 0, 'unknowns' => 0, 'errors' => 0, 'rowsWithCost' => 3, 'suggestionsMarginTooLow' => 1], $report['counts']);
        self::assertSame(['row' => 1, 'productName' => 'Shimano XT CS-M8100 cassette 12-speed', 'brand' => 'Shimano', 'ean' => AwinFeed::ean(1), 'mpn' => 'CSM8100122', 'category' => 'cassettes', 'priceMinor' => 8990, 'currency' => 'EUR'], $report['newProducts'][0]);

        // the cost section works from the feed price, the default EUR rate and the category rule
        $cost = $report['cost']['rows'][0];
        self::assertSame([8990, 'EUR', 25_000_000, 0, 224_750], [$cost['feedPriceMinor'], $cost['currency'], $cost['fxRateCzk'], $cost['inboundShippingMinor'], $cost['landedCostCzk']]);
        // 30 % markup is capped by 97 % of the RRP, which falls under the 10 % minimum margin: flagged, never auto-applied
        self::assertSame([248_000, true], [$cost['suggestedPriceCzk'], $cost['marginTooLow']]);
        self::assertSame(1, $report['cost']['marginTooLow']);
        self::assertNotNull($report['runId']);
        self::assertSame('feed.csv', $report['fileName']);
        self::assertSame('awin_csv', $report['source']);

        // preview writes nothing but its own run row
        self::assertSame($before, $this->counts());
        $runs = $this->getJson('/api/admin/imports/runs');
        self::assertSame(1, $runs['total']);
        self::assertSame('previewed', $runs['items'][0]['status']);
        self::assertSame('test-admin@nodra.test', $runs['items'][0]['adminEmail']);
    }

    public function testEanWithSpacesAndDashesMatchesTheVariant(): void
    {
        $this->catalog();
        $variant = $this->builder()->variant($this->seed, 'SEED-XT-1', ean: AwinFeed::ean(1));

        $report = $this->preview(AwinFeed::csv([AwinFeed::row(['ean' => $this->spaced(AwinFeed::ean(1))], 1)]));

        self::assertSame(0, $report['counts']['newProducts']);
        self::assertSame(1, $report['counts']['updates']);
        self::assertSame($variant->getId()->toRfc4122(), $report['updates'][0]['variantId']);
        self::assertSame('offer', $report['updates'][0]['changes'][0]['field']);
    }

    public function testBrandAndMpnFallbackMatches(): void
    {
        $this->catalog();
        $variant = $this->builder()->variant($this->seed, 'SEED-XT-1', mpn: 'CSM8100122');

        $report = $this->preview(AwinFeed::csv([AwinFeed::row(['ean' => ''], 1)]));

        self::assertSame(1, $report['counts']['updates']);
        self::assertSame($variant->getId()->toRfc4122(), $report['updates'][0]['variantId']);
    }

    public function testSupplierSkuMatchesOnReimport(): void
    {
        $this->catalog();
        $preview = $this->preview(AwinFeed::csv());
        $this->apply(AwinFeed::csv(), $preview['runId']);

        // the same feed again, row 1 with a new price and no identity keys: the supplier SKU settles it
        $row = AwinFeed::row(['price' => '99.90', 'ean' => '', 'mpn' => '', 'model_number' => ''], 1);
        $report = $this->preview(AwinFeed::csv([$row]));

        self::assertSame(0, $report['counts']['newProducts']);
        self::assertSame(1, $report['counts']['updates']);
        $byField = array_column($report['updates'][0]['changes'], 'old', 'field');
        self::assertSame(8990, $byField['price']);
        self::assertSame(9990, array_column($report['updates'][0]['changes'], 'new', 'field')['price']);
    }

    public function testOneRowMatchingTwoVariantsIsAConflict(): void
    {
        $this->catalog();
        $this->builder()->variant($this->seed, 'SEED-XT-1', mpn: 'CSM8100122');
        $second = $this->builder()->product('seed-xt-ii', $this->cassettes, 'Shimano');
        $this->builder()->variant($second, 'SEED-XT-2', mpn: 'CSM8100122');

        $report = $this->preview(AwinFeed::csv([AwinFeed::row(['ean' => ''], 1)]));

        self::assertSame(1, $report['counts']['conflicts']);
        self::assertStringContainsString('2 existing variants', $report['conflicts'][0]['message']);
        self::assertSame(0, $report['counts']['newProducts']);
        self::assertSame(0, $report['counts']['updates']);
    }

    public function testTwoRowsClaimingOneEanConflict(): void
    {
        $this->catalog();

        $report = $this->preview(AwinFeed::csv([AwinFeed::row([], 1), AwinFeed::row(['product_id' => 'BC-9999', 'mpn' => 'OTHER1'], 1)]));

        self::assertSame(2, $report['counts']['conflicts']);
        self::assertSame(0, $report['counts']['newProducts']);
        self::assertStringContainsStringIgnoringCase('claimed by 2 rows', $report['conflicts'][0]['message']);
    }

    public function testASizeContradictionIsAConflictButAMatchIsNot(): void
    {
        $b = $this->builder();
        $parent = $b->category('components', names: ['cs' => 'Komponenty', 'de' => 'Komponenten', 'en' => 'Components']);
        $cassettes = $b->category('cassettes', $parent, [$b::attribute('size', 'text')], names: ['cs' => 'Kazety', 'de' => 'Kassetten', 'en' => 'Cassettes']);
        $b->rule($parent, 0, null, 3000);
        $product = $b->product('seed-xt', $cassettes, 'Shimano');
        $b->variant($product, 'SEED-XT-1', ['size' => 'M'], ean: AwinFeed::ean(1));

        $report = $this->preview(AwinFeed::csv([AwinFeed::row(['size' => 'L'], 1)]));
        self::assertSame(1, $report['counts']['conflicts']);
        self::assertStringContainsStringIgnoringCase('says size "L"', $report['conflicts'][0]['message']);

        $report = $this->preview(AwinFeed::csv([AwinFeed::row(['size' => 'M'], 1)]));
        self::assertSame(0, $report['counts']['conflicts']);
        self::assertSame(1, $report['counts']['updates']);
    }

    public function testUnknownCategoryNewBrandAttributesAndDeliveryTimeAreListed(): void
    {
        $this->catalog();

        $report = $this->preview(AwinFeed::csv([
            AwinFeed::row(['merchant_product_category_path' => 'Scooters > Decks', 'merchant_category' => 'Decks'], 1),
            AwinFeed::row(['brand_name' => 'Acme'], 2),
            AwinFeed::row(['colour' => 'Black'], 3),
            AwinFeed::row(['delivery_time' => 'kvartal'], 4),
        ]));

        $byKind = [];
        foreach ($report['unknowns'] as $unknown) {
            $byKind[$unknown['kind']][] = $unknown;
        }
        self::assertSame('unknown_category', $report['unknowns'][0]['kind']);
        self::assertStringContainsStringIgnoringCase('parked', $report['unknowns'][0]['message']);
        self::assertCount(1, $byKind['new_brand'] ?? []);
        self::assertStringContainsString('Acme', $byKind['new_brand'][0]['message']);
        self::assertStringContainsStringIgnoringCase('colour', $byKind['unknown_attribute'][0]['message']);
        self::assertStringContainsStringIgnoringCase('no day range', $byKind['delivery_time'][0]['message']);
        // the parked row is gone from the new products, the others stay
        self::assertSame(3, $report['counts']['newProducts']);
        self::assertSame(4, $report['counts']['unknowns']);
    }

    public function testUnparseablePriceAndDuplicateSkuAreRowErrors(): void
    {
        $this->catalog();

        $report = $this->preview(AwinFeed::csv([
            AwinFeed::row(['price' => 'not a price'], 1),
            AwinFeed::row(['product_name' => ''], 2),
            AwinFeed::row([], 3),
            AwinFeed::row([], 3),
        ]));

        self::assertSame(3, $report['counts']['errors']);
        self::assertSame(1, $report['errors'][0]['row']);
        self::assertStringContainsStringIgnoringCase('unparseable price', $report['errors'][0]['message']);
        self::assertSame(2, $report['errors'][1]['row']);
        self::assertSame(4, $report['errors'][2]['row']);
        self::assertStringContainsStringIgnoringCase('duplicate', $report['errors'][2]['message']);
        self::assertSame(1, $report['counts']['newProducts']);
    }

    public function testListsAreTruncatedToTwoHundredWithCompleteCounts(): void
    {
        $this->catalog();
        $rows = [];
        foreach (range(1, 250) as $i) {
            $rows[] = AwinFeed::row(['product_id' => 'BC-R'.$i, 'product_name' => 'Feed item '.$i, 'ean' => AwinFeed::ean($i), 'mpn' => '', 'model_number' => '', 'price' => '10.00', 'rrp_price' => '', 'delivery_time' => ''], $i % 50 + 1);
        }

        $report = $this->preview(AwinFeed::csv($rows));

        self::assertSame(250, $report['counts']['newProducts']);
        self::assertCount(200, $report['newProducts']);
        self::assertSame(250, $report['counts']['rowsWithCost']);
    }

    /** Every section truncates its list at 200 rows while the counts stay complete, whatever the section. */
    public function testEverySectionTruncatesItsListButKeepsTheCompleteCount(): void
    {
        $this->catalog();
        $b = $this->builder();
        // 250 existing variants for the updates section
        $product = $b->product('bulk-matched', $this->cassettes, 'Shimano');
        $rows = [];
        foreach (range(1, 250) as $i) {
            $b->variant($product, 'BULK-'.$i, ean: AwinFeed::ean(500 + $i));
            $rows[] = AwinFeed::row(['product_id' => 'BC-M'.$i, 'product_name' => 'Bulk item '.$i, 'ean' => AwinFeed::ean(500 + $i), 'mpn' => '', 'model_number' => '', 'price' => '10.00', 'rrp_price' => '', 'delivery_time' => ''], 1);
        }
        // 240 rows claiming 120 EANs in pairs → conflicts
        foreach (range(1, 120) as $i) {
            $rows[] = AwinFeed::row(['product_id' => 'BC-C'.$i.'a', 'product_name' => 'Clash '.$i.' a', 'ean' => AwinFeed::ean(900 + $i), 'mpn' => '', 'model_number' => ''], 1);
            $rows[] = AwinFeed::row(['product_id' => 'BC-C'.$i.'b', 'product_name' => 'Clash '.$i.' b', 'ean' => AwinFeed::ean(900 + $i), 'mpn' => '', 'model_number' => ''], 1);
        }
        // 250 rows in a category the catalogue doesn't know → unknowns
        foreach (range(1, 250) as $i) {
            $rows[] = AwinFeed::row(['product_id' => 'BC-U'.$i, 'product_name' => 'Unknown cat '.$i, 'ean' => AwinFeed::ean(1200 + $i), 'mpn' => '', 'model_number' => '', 'merchant_product_category_path' => 'Nowhere > Void', 'merchant_category' => 'Void'], 1);
        }
        // 210 rows without a name → errors
        foreach (range(1, 210) as $i) {
            $rows[] = AwinFeed::row(['product_id' => 'BC-E'.$i, 'product_name' => '', 'ean' => ''], 1);
        }
        shuffle($rows);

        $report = $this->preview(AwinFeed::csv($rows));

        self::assertSame(950, $report['counts']['totalRows']);
        self::assertSame(['newProducts' => 0, 'updates' => 250, 'conflicts' => 240, 'unknowns' => 250, 'errors' => 210, 'rowsWithCost' => 250], [
            'newProducts' => $report['counts']['newProducts'], 'updates' => $report['counts']['updates'], 'conflicts' => $report['counts']['conflicts'],
            'unknowns' => $report['counts']['unknowns'], 'errors' => $report['counts']['errors'], 'rowsWithCost' => $report['counts']['rowsWithCost'],
        ]);
        self::assertCount(0, $report['newProducts']);
        self::assertCount(200, $report['updates']);
        self::assertCount(200, $report['conflicts']);
        self::assertCount(200, $report['unknowns']);
        self::assertCount(200, $report['errors']);
        self::assertCount(200, $report['cost']['rows']);
    }

    public function testApplyWritesDraftsOffersOriginsAndTheJournal(): void
    {
        $this->catalog();
        $preview = $this->preview(AwinFeed::csv());

        $result = $this->apply(AwinFeed::csv(), $preview['runId']);

        self::assertResponseIsSuccessful();
        // rows 2 and 3 have feed RRP values too, but row 2's is empty, so two RRP writes
        self::assertSame(['products' => 3, 'variants' => 3, 'offers' => 3, 'offerUpdates' => 0, 'rrpWrites' => 2], $result['written']);

        $db = $this->db();
        $product = $db->fetchAssociative("SELECT slug, status, brand, source_images FROM product WHERE slug = 'shimano-xt-cs-m8100-cassette-12-speed'");
        self::assertIsArray($product, 'the draft product exists');
        self::assertSame(['slug' => 'shimano-xt-cs-m8100-cassette-12-speed', 'status' => 'draft', 'brand' => 'Shimano', 'source_images' => ['https://img.example/xt.jpg', 'https://img.example/xt_large.jpg', 'https://img.example/xt_alt.jpg']], [
            'slug' => $product['slug'], 'status' => $product['status'], 'brand' => $product['brand'], 'source_images' => json_decode((string) $product['source_images'], true),
        ]);

        $variant = $db->fetchAssociative('SELECT v.id, v.ean, v.mpn, v.price_czk, v.stock FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.slug = :slug', ['slug' => 'shimano-xt-cs-m8100-cassette-12-speed']);
        self::assertSame([AwinFeed::ean(1), 'CSM8100122', 0, 0], [$variant['ean'], $variant['mpn'], (int) $variant['price_czk'], (int) $variant['stock']]);
        self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM price_change WHERE variant_id = ? AND reason = 'import'", [$variant['id']]));

        $offer = $db->fetchAssociative('SELECT o.supplier, o.supplier_sku, o.price_minor, o.currency, o.reported_quantity, o.lead_time_min_days, o.lead_time_max_days, o.verification_status, o.inbound_shipping_minor, o.fx_rate_czk FROM supplier_offer o JOIN product p ON p.id = o.product_id WHERE p.slug = :slug', ['slug' => 'shimano-xt-cs-m8100-cassette-12-speed']);
        self::assertSame(['bike_components', 'BC-1001', 8990, 'EUR', 7, 2, 4, 'matched', 0, 25_000_000], [$offer['supplier'], $offer['supplier_sku'], (int) $offer['price_minor'], $offer['currency'], (int) $offer['reported_quantity'], (int) $offer['lead_time_min_days'], (int) $offer['lead_time_max_days'], $offer['verification_status'], (int) $offer['inbound_shipping_minor'], (int) $offer['fx_rate_czk']]);

        // the feed RRP carries its origin and today's date
        $rrp = $db->fetchAssociative('SELECT rrp_minor, rrp_currency, rrp_source FROM product_variant WHERE id = :id', ['id' => $variant['id']]);
        self::assertSame([9990, 'EUR', 'feed bike_components'], [$rrp['rrp_minor'], $rrp['rrp_currency'], $rrp['rrp_source']]);

        // every written field records the run as its origin: 6 per product, 4-6 per variant, 8 per offer
        $origins = $db->fetchAssociative("SELECT
            (SELECT COUNT(*) FROM import_field_origin WHERE entity_type = 'product') AS products,
            (SELECT COUNT(*) FROM import_field_origin WHERE entity_type = 'variant') AS variants,
            (SELECT COUNT(*) FROM import_field_origin WHERE entity_type = 'offer') AS offers");
        self::assertSame(18, (int) $origins['products']);
        self::assertSame(17, (int) $origins['variants']);
        self::assertSame(24, (int) $origins['offers']);

        // the run row from the preview is the one apply updated; the journal keeps one row per run
        $runs = $this->getJson('/api/admin/imports/runs');
        self::assertSame(1, $runs['total']);
        self::assertSame('applied', $runs['items'][0]['status']);
        $detail = $this->getJson('/api/admin/imports/runs/'.$runs['items'][0]['id']);
        self::assertSame(3, $detail['report']['counts']['newProducts']);
        self::assertSame([], $detail['errors']);
    }

    public function testApplyRefusesAChangedFileWith409(): void
    {
        $this->catalog();
        $preview = $this->preview(AwinFeed::csv());
        $before = $this->counts();

        $result = $this->apply(AwinFeed::csv([AwinFeed::row(['price' => '99.90'], 1)]), $preview['runId']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('file_changed', $result['code']);
        self::assertSame($before, $this->counts(), 'nothing was written');
    }

    public function testApplyRunsOnce(): void
    {
        $this->catalog();
        $preview = $this->preview(AwinFeed::csv());
        $this->apply(AwinFeed::csv(), $preview['runId']);

        $result = $this->apply(AwinFeed::csv(), $preview['runId']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_applied', $result['code']);
    }

    public function testApplyNeverDeletesAnything(): void
    {
        $this->catalog();
        $preview = $this->preview(AwinFeed::csv());
        $this->apply(AwinFeed::csv(), $preview['runId']);
        $before = $this->counts();

        // a newer feed that lost two of the three rows: they age, nothing is removed
        $newer = AwinFeed::csv([AwinFeed::row(['price' => '94.90'], 1)]);
        $preview = $this->preview($newer);
        $this->apply($newer, $preview['runId']);

        $after = $this->counts();
        self::assertSame($before['products'], $after['products']);
        self::assertSame($before['offers'], $after['offers']);
        self::assertSame($before['variants'], $after['variants']);
        self::assertSame(9490, (int) $this->db()->fetchOne('SELECT price_minor FROM supplier_offer WHERE supplier_sku = :sku', ['sku' => 'BC-1001']));
        self::assertSame(4490, (int) $this->db()->fetchOne('SELECT price_minor FROM supplier_offer WHERE supplier_sku = :sku', ['sku' => 'BC-1002']), 'the offer missing from the newer feed keeps its old snapshot');
    }

    public function testAdminSetRrpSurvivesAndFeedRrpRefreshes(): void
    {
        $this->catalog();
        $admin = $this->builder()->variant($this->seed, 'SEED-XT-1', ean: AwinFeed::ean(1));
        $admin->setReferencePrices(249_000, 'CZK', 'MFG site', new \DateTimeImmutable('2026-09-01'), null, null, null);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $feed = AwinFeed::csv();
        $this->apply($feed, $this->preview($feed)['runId']);

        $state = $this->db()->fetchAssociative('SELECT rrp_minor, rrp_currency, rrp_source FROM product_variant WHERE id = :id', ['id' => $admin->getId()->toRfc4122()]);
        self::assertSame(['rrp_minor' => 249_000, 'rrp_currency' => 'CZK', 'rrp_source' => 'MFG site'], $state, 'the admin-set RRP is never overwritten');

        // a variant whose RRP came from the feed itself is refreshed by the next run (row 3 has no EAN, only brand+MPN, and carries rrp_price)
        $feedRrp = $this->db()->fetchOne('SELECT id FROM product_variant WHERE mpn = :mpn', ['mpn' => 'MT5PAD']);
        self::assertSame(2490, (int) $this->db()->fetchOne('SELECT rrp_minor FROM product_variant WHERE id = ?', [$feedRrp]));
        $newer = AwinFeed::csv([AwinFeed::row(['ean' => AwinFeed::ean(3), 'rrp_price' => '109.90'], 3)]);
        $this->apply($newer, $this->preview($newer)['runId']);
        self::assertSame(10_990, (int) $this->db()->fetchOne('SELECT rrp_minor FROM product_variant WHERE id = ?', [$feedRrp]));
    }

    public function testSupplierSettingsAffectTheCostReport(): void
    {
        $this->catalog();
        $settings = array_column($this->getJson('/api/admin/imports/settings')['settings'], 'inboundShippingMinor', 'supplier');
        self::assertSame(0, $settings['bike_components']);

        $this->client->request('PUT', '/api/admin/imports/settings/bike_components', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], content: '{"inboundShippingMinor": 495}');
        self::assertResponseIsSuccessful();

        $report = $this->preview(AwinFeed::csv());
        $cost = $report['cost']['rows'][0];
        self::assertSame(495, $cost['inboundShippingMinor']);
        self::assertSame((8990 + 495) * 25, $cost['landedCostCzk']);

        $this->client->request('PUT', '/api/admin/imports/settings/unknown_supplier', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], content: '{"inboundShippingMinor": 1}');
        self::assertResponseStatusCodeSame(422);

        $this->client->request('PUT', '/api/admin/imports/settings/bike_components', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], content: '{"inboundShippingMinor": -1}');
        self::assertResponseStatusCodeSame(422);

        $this->client->request('PUT', '/api/admin/imports/settings/bike_components', server: ['CONTENT_TYPE' => 'application/json'], content: '{"inboundShippingMinor": 1}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPublicJsonCarriesNoSupplierCostOrFeedData(): void
    {
        $this->catalog();
        $preview = $this->preview(AwinFeed::csv());
        $this->apply(AwinFeed::csv(), $preview['runId']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Product::class)->findAll() as $product) {
            $product->update($product->getSlug(), $product->getCategory(), $product->getCopy(), $product->getImage(), $product->getImages(), null, $product->getFeaturedRank(), 'published');
        }
        $em->flush();
        $em->clear();

        $responses = [
            'list' => $this->getJson('/api/products', ['locale' => 'en']),
            'detail' => $this->getJson('/api/products/shimano-xt-cs-m8100-cassette-12-speed', ['locale' => 'en']),
            'categories' => $this->getJson('/api/categories', ['locale' => 'en']),
            'facets' => $this->getJson('/api/categories/cassettes/facets', ['locale' => 'en']),
        ];
        self::assertSame('orderable', $responses['detail']['variants'][0]['availability']['status'], 'the imported offer was matched, so the card is live');
        foreach ($responses as $name => $payload) {
            $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            foreach (['supplier', 'seller', 'offer', 'cost', 'landed', 'fx', 'rrp', 'margin', 'markup', 'import', 'origin', 'source_images'] as $part) {
                self::assertStringNotContainsString($part, strtolower($raw), $name);
            }
            foreach (['bike_components', 't.example', 'img.example', 'feed bike_components'] as $value) {
                self::assertStringNotContainsString($value, $raw, $name);
            }
        }
    }

    public function testPreviewNeedsASessionAndAToken(): void
    {
        $this->catalog();
        $this->client->request('POST', '/api/admin/imports/preview', [], [
            'file' => ['tmp_name' => $this->fixtureFile(AwinFeed::csv()), 'name' => 'feed.csv', 'type' => 'text/csv', 'size' => 1, 'error' => 0],
        ]);
        self::assertResponseStatusCodeSame(403, 'no CSRF token');

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/admin/imports/preview');
        self::assertResponseStatusCodeSame(401, 'no admin session');
    }

    public function testUnknownRunIsNotFound(): void
    {
        $this->getJson('/api/admin/imports/runs/00000000-0000-0000-0000-000000000000');
        self::assertResponseStatusCodeSame(404);

        $this->upload(AwinFeed::csv(), '/api/admin/imports/apply', '00000000-0000-0000-0000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }

    /** The catalogue the fixture expects: the seed categories of the tree, a pricing rule and one Shimano card. */
    private function catalog(): void
    {
        $b = $this->builder();
        $this->components = $b->category('components', names: ['cs' => 'Komponenty', 'de' => 'Komponenten', 'en' => 'Components']);
        $this->cassettes = $b->category('cassettes', $this->components, names: ['cs' => 'Kazety', 'de' => 'Kassetten', 'en' => 'Cassettes']);
        $b->category('tyres', $this->components, [$b::attribute('colour', 'text'), $b::attribute('size', 'text')], names: ['cs' => 'Pláště', 'de' => 'Reifen', 'en' => 'Tyres']);
        $b->category('brake-pads', $this->components, names: ['cs' => 'Brzdové špalíky', 'de' => 'Bremsbeläge', 'en' => 'Brake pads']);
        $b->rule($this->components, 0, null, 3000);
        // a seeded Shimano card so the brand is known and the fixture rows stay new
        $this->seed = $b->product('seed-xt', $this->cassettes, 'Shimano');
        $b->variant($this->seed, 'SEED-XT-0', mpn: 'SEEDONLY');
    }

    private function preview(string $csv): array
    {
        return $this->upload($csv, '/api/admin/imports/preview', null);
    }

    private function apply(string $csv, string $runId): array
    {
        return $this->upload($csv, '/api/admin/imports/apply', $runId);
    }

    private function upload(string $csv, string $uri, ?string $runId): array
    {
        $this->client->request('POST', $uri, $runId === null ? [] : ['runId' => $runId], [
            'file' => ['tmp_name' => $this->fixtureFile($csv), 'name' => 'feed.csv', 'type' => 'text/csv', 'size' => strlen($csv), 'error' => 0],
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf, 'HTTP_ACCEPT' => 'application/json']);

        return $this->decode();
    }

    private function fixtureFile(string $csv): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'feed');
        file_put_contents($tmp, $csv);

        return $tmp;
    }

    /** The same digits with the grouping people paste from a label. */
    private function spaced(string $ean): string
    {
        return substr($ean, 0, 4).' '.substr($ean, 4, 4).'-'.substr($ean, 8, 4).'-'.substr($ean, 12);
    }

    /** @return array{products: int, variants: int, offers: int} */
    private function counts(): array
    {
        return $this->db()->fetchAssociative('SELECT (SELECT COUNT(*) FROM product) AS products, (SELECT COUNT(*) FROM product_variant) AS variants, (SELECT COUNT(*) FROM supplier_offer) AS offers');
    }
}
