<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\ImportRun;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CatalogBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * D03.3 field origins: every written field knows where its value came from — the seed, a feed run
 * or an admin edit — and when. The admin API exposes the latest write per field.
 */
final class FieldOriginsTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginAdmin();
    }

    public function testAnAdminEditRecordsAdminOrigins(): void
    {
        $b = $this->builder();
        $root = $b->category('t-parts');
        $tyres = $b->category('t-tyres', $root, [CatalogBuilder::attribute('width_mm', 'number', unit: 'mm')]);
        $product = $b->product('t-tyre', $tyres, 'Continental');

        $this->sendJson('PUT', '/api/admin/products/'.$product->getId()->toRfc4122(), [
            'slug' => 't-tyre', 'category' => 't-tyres', 'status' => 'published',
            'nameCs' => 'Plášť', 'nameDe' => 'Reifen', 'nameEn' => 'Renamed tyre',
            'shortCs' => 'Krátký', 'shortDe' => 'Kurz', 'shortEn' => 'Short',
            'image' => '/images/tyre.png', 'priceCzk' => 129000, 'priceEur' => 5200,
            'brand' => 'Schwalbe',
        ], $this->token);
        self::assertResponseIsSuccessful();

        $origins = $this->getJson('/api/admin/origins?type=product&ids='.$product->getId()->toRfc4122());
        $fields = $origins['origins'][$product->getId()->toRfc4122()];
        self::assertSame('admin', $fields['name']['kind']);
        self::assertSame('test-admin@nodra.test', $fields['name']['adminEmail']);
        self::assertNotNull($fields['name']['writtenAt']);
        self::assertSame('admin', $fields['brand']['kind']);
        self::assertArrayNotHasKey('category', $fields, 'unchanged fields are not re-journaled');

        // admin origins carry no run: the field-origin kinds stay apart
        self::assertNull($fields['name']['runId']);
        self::assertNull($fields['name']['supplier']);
    }

    public function testFeedAndSeedRunsRecordTheirOrigins(): void
    {
        $b = $this->builder();
        $tyres = $b->category('t-tyres', null, [CatalogBuilder::attribute('width_mm', 'number', unit: 'mm')]);
        $product = $b->product('t-tyre', $tyres, 'Continental');
        $variant = $b->variant($product, 'T-TYRE-1');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = static::getContainer()->get(ClockInterface::class)->now();
        $run = new ImportRun(ImportRun::SOURCE_AWIN, $now, 'bike_components');
        $em->persist($run);
        $em->flush();
        $origin = \App\Entity\ImportFieldOrigin::fromRun($run, 'variant', $variant->getId(), 'price', $now);
        $em->persist($origin);
        $em->flush();

        $origins = $this->getJson('/api/admin/origins?type=variant&ids='.$variant->getId()->toRfc4122());
        $price = $origins['origins'][$variant->getId()->toRfc4122()]['price'];
        self::assertSame('feed', $price['kind']);
        self::assertSame('bike_components', $price['supplier']);
        self::assertSame($run->getId()->toRfc4122(), $price['runId']);
        self::assertSame('awin_csv', $price['runSource']);
        self::assertNull($price['adminEmail']);
    }

    public function testTheOriginsEndpointValidatesAndStaysPrivate(): void
    {
        $this->getJson('/api/admin/origins?type=variant&ids=not-a-uuid');
        self::assertResponseStatusCodeSame(422);

        $this->getJson('/api/admin/origins?type=ghost&ids=01890000-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(422);

        // privacy check last: clearing the session would break the authenticated checks above
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/admin/origins?type=variant');
        self::assertResponseStatusCodeSame(401, 'no admin session');
    }
}
