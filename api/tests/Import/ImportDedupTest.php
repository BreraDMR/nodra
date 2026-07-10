<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Entity\ImportRun;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D03.3: the same physical product from another feed or from the seed becomes another offer of the
 * existing variant — matched by the admin's binding, by EAN, by brand + MPN or by characteristics.
 * Disputed matches stay in conflicts and nothing merges by itself.
 */
final class ImportDedupTest extends ApiTestCase
{
    private string $csrf;
    private \App\Entity\Category $components;
    private \App\Entity\Category $tyres;
    private \App\Entity\Product $seed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csrf = $this->loginAdmin();
        $b = $this->builder();
        $this->components = $b->category('components', names: ['cs' => 'Komponenty', 'de' => 'Komponenten', 'en' => 'Components']);
        $this->tyres = $b->category('tyres', $this->components, [$b::attribute('colour', 'text'), $b::attribute('size', 'text')], names: ['cs' => 'Pláště', 'de' => 'Reifen', 'en' => 'Tyres']);
        $b->rule($this->components, 0, null, 3000);
        $this->seed = $b->product('seed-marathon', $this->tyres, 'Schwalbe');
    }

    public function testACharacteristicsRowMatchesTheOneVariantAndAddsAnOffer(): void
    {
        $variant = $this->builder()->variant($this->seed, 'SEED-MAR-1', ['colour' => 'Black', 'size' => '40-622'], priceCzk: 100000);

        // no EAN, no MPN: only the characteristics (brand + colour + size in the guessed category) can find the variant
        $row = AwinFeed::row(['ean' => '', 'mpn' => '', 'model_number' => '', 'brand_name' => 'Schwalbe', 'colour' => 'black', 'size' => '40-622', 'merchant_category' => 'Tyres', 'merchant_product_category_path' => 'Components > Tyres', 'product_name' => 'Marathon Supreme from the feed'], 1);
        $report = $this->preview(AwinFeed::csv([$row]));

        self::assertSame(0, $report['counts']['newProducts'], 'the row is not a second product');
        self::assertSame(1, $report['counts']['updates']);
        self::assertSame($variant->getId()->toRfc4122(), $report['updates'][0]['variantId']);

        $this->apply(AwinFeed::csv([$row]), $report['runId']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM supplier_offer WHERE variant_id = :v', ['v' => $variant->getId()->toRfc4122()]), 'the feed became an offer of the existing variant');
    }

    public function testTwoVariantsWithTheSameCharacteristicsAreAConflict(): void
    {
        $b = $this->builder();
        $b->variant($this->seed, 'SEED-MAR-1', ['colour' => 'Black', 'size' => '40-622']);
        $b->variant($this->seed, 'SEED-MAR-2', ['colour' => 'Black', 'size' => '40-622']);

        $row = AwinFeed::row(['ean' => '', 'mpn' => '', 'model_number' => '', 'brand_name' => 'Schwalbe', 'colour' => 'Black', 'size' => '40-622', 'merchant_category' => 'Tyres', 'merchant_product_category_path' => 'Components > Tyres'], 1);
        $report = $this->preview(AwinFeed::csv([$row]));

        self::assertSame(1, $report['counts']['conflicts']);
        self::assertSame(0, $report['counts']['updates']);
        self::assertStringContainsStringIgnoringCase('characteristics', $report['conflicts'][0]['message']);
        self::assertSame('BC-1001', $report['conflicts'][0]['sku'], 'the conflict carries the supplier SKU so the admin can bind it');
    }

    public function testAContradictingSizeStillWinsAsAConflict(): void
    {
        $b = $this->builder();
        $b->variant($this->seed, 'SEED-MAR-1', ['colour' => 'Black', 'size' => '40-622'], ean: AwinFeed::ean(1));

        $row = AwinFeed::row(['colour' => 'Black', 'size' => '28-622', 'merchant_category' => 'Tyres', 'merchant_product_category_path' => 'Components > Tyres'], 1);
        $report = $this->preview(AwinFeed::csv([$row]));

        self::assertSame(1, $report['counts']['conflicts']);
        self::assertStringContainsStringIgnoringCase('says size "28-622"', $report['conflicts'][0]['message']);
    }

    public function testARowTheCharacteristicsCannotSettleStaysANewDraft(): void
    {
        $b = $this->builder();
        $b->variant($this->seed, 'SEED-MAR-1', ['colour' => 'Black', 'size' => '40-622']);

        // another size: no characteristics match, so the row goes the new-product way
        $row = AwinFeed::row(['ean' => AwinFeed::ean(9), 'mpn' => '', 'model_number' => '', 'brand_name' => 'Schwalbe', 'colour' => 'Black', 'size' => '28-622', 'merchant_category' => 'Tyres', 'merchant_product_category_path' => 'Components > Tyres'], 1);
        $report = $this->preview(AwinFeed::csv([$row]));

        self::assertSame(1, $report['counts']['newProducts']);
        self::assertSame(0, $report['counts']['updates']);
    }

    public function testABoundSkuMatchesTheVariantOnTheNextRun(): void
    {
        $variant = $this->builder()->variant($this->seed, 'SEED-MAR-1', priceCzk: 100000);
        $row = AwinFeed::row(['ean' => '', 'mpn' => '', 'model_number' => '', 'brand_name' => 'Schwalbe'], 1);

        // unbound, a row with no identity keys cannot match anything
        $report = $this->preview(AwinFeed::csv([$row]));
        self::assertSame(0, $report['counts']['updates']);

        $this->sendJson('POST', '/api/admin/imports/bindings', ['supplier' => 'bike_components', 'supplierSku' => 'BC-1001', 'variantId' => $variant->getId()->toRfc4122()], $this->csrf);
        self::assertResponseIsSuccessful();

        $report = $this->preview(AwinFeed::csv([$row]));
        self::assertSame(1, $report['counts']['updates'], 'the binding is remembered for the following runs');
        self::assertSame($variant->getId()->toRfc4122(), $report['updates'][0]['variantId']);

        $this->apply(AwinFeed::csv([$row]), $report['runId']);
        $offer = $this->db()->fetchAssociative('SELECT supplier_sku, variant_id, verification_status FROM supplier_offer WHERE variant_id = ?', [$variant->getId()->toRfc4122()]);
        self::assertSame('BC-1001', $offer['supplier_sku']);
        self::assertSame('matched', $offer['verification_status']);
    }

    public function testABindingSurvivesABrandOrEanDisagreement(): void
    {
        // the binding is the admin's word: the row matches the bound variant even with an unknown EAN
        $variant = $this->builder()->variant($this->seed, 'SEED-MAR-1');
        $this->sendJson('POST', '/api/admin/imports/bindings', ['supplier' => 'bike_components', 'supplierSku' => 'BC-1001', 'variantId' => $variant->getId()->toRfc4122()], $this->csrf);
        self::assertResponseIsSuccessful();

        $row = AwinFeed::row(['ean' => AwinFeed::ean(77)], 1);
        $report = $this->preview(AwinFeed::csv([$row]));
        self::assertSame(1, $report['counts']['updates']);
        self::assertSame($variant->getId()->toRfc4122(), $report['updates'][0]['variantId']);
    }

    public function testBindingApiValidatesAndLists(): void
    {
        $variant = $this->builder()->variant($this->seed, 'SEED-MAR-1');
        $variantId = $variant->getId()->toRfc4122();

        $this->sendJson('POST', '/api/admin/imports/bindings', ['supplier' => 'nowhere', 'supplierSku' => 'X1', 'variantId' => $variantId], $this->csrf);
        self::assertResponseStatusCodeSame(422);

        $this->sendJson('POST', '/api/admin/imports/bindings', ['supplier' => 'bike_components', 'supplierSku' => 'X1', 'variantId' => '00000000-0000-0000-0000-000000000000'], $this->csrf);
        self::assertResponseStatusCodeSame(404);

        $this->sendJson('POST', '/api/admin/imports/bindings', ['supplier' => 'bike_components', 'supplierSku' => '', 'variantId' => $variantId], $this->csrf);
        self::assertResponseStatusCodeSame(422);

        $this->sendJson('POST', '/api/admin/imports/bindings', ['supplier' => 'bike_components', 'supplierSku' => 'BC-1001', 'variantId' => $variantId], $this->csrf);
        self::assertResponseIsSuccessful();
        // the same supplier SKU cannot be bound twice
        $this->sendJson('POST', '/api/admin/imports/bindings', ['supplier' => 'bike_components', 'supplierSku' => 'BC-1001', 'variantId' => $variantId], $this->csrf);
        self::assertResponseStatusCodeSame(409);

        $list = $this->getJson('/api/admin/imports/bindings?variantId='.$variantId);
        self::assertCount(1, $list['items']);
        self::assertSame(['id', 'supplier', 'supplierSku', 'variantId', 'createdBy', 'createdAt'], array_keys($list['items'][0]));
        self::assertSame('test-admin@nodra.test', $list['items'][0]['createdBy']);

        // the variant card reads its bindings
        $byVariant = $this->getJson('/api/admin/imports/bindings', ['variantId' => $variantId]);
        self::assertCount(1, $byVariant['items']);

        $bindingId = $byVariant['items'][0]['id'];
        $this->sendJson('DELETE', '/api/admin/imports/bindings/'.$bindingId, [], $this->csrf);
        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM import_feed_binding'));

        // writes need the CSRF token like every other admin write
        $this->sendJson('POST', '/api/admin/imports/bindings', ['supplier' => 'bike_components', 'supplierSku' => 'BC-9', 'variantId' => $variantId]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testABoundRowIsAnUpdateEvenWithoutColourOrSize(): void
    {
        // the demo cards have neither EAN nor MPN: the binding is the only way a real feed reaches them
        $variant = $this->builder()->variant($this->seed, 'SEED-MAR-1');
        $this->sendJson('POST', '/api/admin/imports/bindings', ['supplier' => 'bike_components', 'supplierSku' => 'BC-1001', 'variantId' => $variant->getId()->toRfc4122()], $this->csrf);

        $row = AwinFeed::row(['ean' => '', 'mpn' => '', 'model_number' => '', 'colour' => '', 'size' => ''], 1);
        $report = $this->preview(AwinFeed::csv([$row]));
        self::assertSame(1, $report['counts']['updates']);
        self::assertSame(0, $report['counts']['unknowns'], 'no no_identity note for a row the binding settles');
    }

    public function testMergeRePointsOffersCopiesIdentityAndArchivesTheSource(): void
    {
        $b = $this->builder();
        // the draft a feed created: one identified variant with an offer, next to the seeded twin without one
        $draft = $b->product('feed-dupe', $this->tyres, 'Schwalbe', status: 'draft');
        $draftVariant = $b->variant($draft, 'ND-FEED1', ['colour' => 'Black', 'size' => '40-622'], ean: AwinFeed::ean(1));
        $offer = $b->pricedOffer($draft, $draftVariant, 199000, leadTimeMinDays: 2, leadTimeMaxDays: 4);
        // a second variant nothing in the target corresponds to: it moves as a whole
        $spareVariant = $b->variant($draft, 'ND-FEED2', ean: AwinFeed::ean(2));
        $spareOffer = $b->pricedOffer($draft, $spareVariant, 299000);
        $seedVariant = $b->variant($this->seed, 'SEED-MAR-9', ['colour' => 'Black', 'size' => '40-622']);

        $this->sendJson('POST', '/api/admin/products/'.$draft->getId()->toRfc4122().'/merge', ['targetId' => $this->seed->getId()->toRfc4122()], $this->csrf);
        self::assertResponseIsSuccessful();

        $db = $this->db();
        // the twin's offer survived on the seeded variant, and the twin's EAN moved over
        self::assertSame($seedVariant->getId()->toRfc4122(), $db->fetchOne('SELECT variant_id FROM supplier_offer WHERE id = ?', [$offer->getId()->toRfc4122()]));
        self::assertSame($this->seed->getId()->toRfc4122(), $db->fetchOne('SELECT product_id FROM supplier_offer WHERE id = ?', [$offer->getId()->toRfc4122()]));
        self::assertSame(AwinFeed::ean(1), $db->fetchOne('SELECT ean FROM product_variant WHERE id = ?', [$seedVariant->getId()->toRfc4122()]));
        // the variant without a counterpart moved to the target with its offer
        self::assertSame($this->seed->getId()->toRfc4122(), $db->fetchOne('SELECT product_id FROM product_variant WHERE id = ?', [$spareVariant->getId()->toRfc4122()]));
        self::assertSame($this->seed->getId()->toRfc4122(), $db->fetchOne('SELECT product_id FROM supplier_offer WHERE id = ?', [$spareOffer->getId()->toRfc4122()]));
        // the emptied twin variant is deactivated, the source product is archived, nothing was deleted
        self::assertSame(0, (int) $db->fetchOne('SELECT active FROM product_variant WHERE id = ?', [$draftVariant->getId()->toRfc4122()]));
        self::assertSame('archived', $db->fetchOne('SELECT status FROM product WHERE id = ?', [$draft->getId()->toRfc4122()]));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM supplier_offer WHERE product_id = ?', [$this->seed->getId()->toRfc4122()]));

        // the merge journaled itself and the copied identity carries the origin
        $runs = $this->getJson('/api/admin/imports/runs');
        self::assertSame('merge', $runs['items'][0]['source']);
        self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM import_field_origin WHERE entity_id = ? AND field = 'ean' AND run_id = ?", [$seedVariant->getId()->toRfc4122(), $runs['items'][0]['id']]));

        // CSRF still guards the write
        $this->sendJson('POST', '/api/admin/products/'.$draft->getId()->toRfc4122().'/merge', ['targetId' => $this->seed->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testMergeRefusesItselfAndAnArchivedTarget(): void
    {
        $b = $this->builder();
        $source = $b->product('feed-dupe', $this->tyres, 'Schwalbe', status: 'draft');
        $b->variant($source, 'ND-FEED1');
        $targetId = $this->seed->getId()->toRfc4122();

        $this->sendJson('POST', '/api/admin/products/'.$targetId.'/merge', ['targetId' => $targetId], $this->csrf);
        self::assertResponseStatusCodeSame(409);

        $this->sendJson('POST', '/api/admin/products/'.$source->getId()->toRfc4122().'/merge', ['targetId' => '01890000-0000-7000-8000-000000000000'], $this->csrf);
        self::assertResponseStatusCodeSame(404);

        // merging into an archived product would bury the offers
        $this->sendJson('POST', '/api/admin/products/'.$source->getId()->toRfc4122().'/merge', ['targetId' => $targetId], $this->csrf);
        self::assertResponseIsSuccessful();
        $this->sendJson('POST', '/api/admin/products/'.$this->seed->getId()->toRfc4122().'/merge', ['targetId' => $this->seed->getId()->toRfc4122()], $this->csrf);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAnArchivedProductLeavesTheStorefront(): void
    {
        $b = $this->builder();
        $extra = $b->product('archived-card', $this->tyres, 'Schwalbe');
        $b->variant($extra, 'ARCH-1');

        $this->sendJson('POST', '/api/admin/products/'.$extra->getId()->toRfc4122().'/merge', ['targetId' => $this->seed->getId()->toRfc4122()], $this->csrf);
        self::assertResponseIsSuccessful();

        $list = $this->getJson('/api/products', ['locale' => 'en']);
        foreach ($list['items'] as $item) {
            self::assertNotSame('archived-card', $item['slug']);
        }
        $this->client->request('GET', '/api/products/archived-card', ['locale' => 'en']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateRowsCarryTheFeedCurrency(): void
    {
        $this->builder()->variant($this->seed, 'SEED-MAR-1', ean: AwinFeed::ean(1));
        $report = $this->preview(AwinFeed::csv());

        self::assertSame('EUR', $report['updates'][0]['currency']);
        self::assertSame('offer', $report['updates'][0]['changes'][0]['field'], 'the raw minor values stay in the API; the admin renders them as money');
    }

    /** @param array<string, mixed> $query */
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
}
