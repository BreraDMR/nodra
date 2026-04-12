<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\Category;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CatalogBuilder;
use Doctrine\ORM\EntityManagerInterface;

final class ProductAdminApiTest extends ApiTestCase
{
    private string $token;
    private Category $tyres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginAdmin();
        $b = $this->builder();
        $root = $b->category('t-parts', attributes: [CatalogBuilder::attribute('tubeless', 'choice', options: ['yes', 'no'])]);
        $this->tyres = $b->category('t-tyres', $root, [CatalogBuilder::attribute('width_mm', 'number', unit: 'mm')]);
    }

    public function testCreateStoresCategoryBrandAndNormalisedAttributes(): void
    {
        $created = $this->sendJson('POST', '/api/admin/products', $this->payload('t-gp5000', [
            'brand' => '  Continental ',
            'attributes' => ['width_mm' => '28,0', 'tubeless' => 'yes'],
            'inBoxCs' => 'Plášť',
        ]), $this->token);

        self::assertResponseStatusCodeSame(201);
        $item = $this->adminItem('t-gp5000');
        self::assertSame($created['id'], $item['id']);
        self::assertSame('t-tyres', $item['category']);
        self::assertSame('Continental', $item['brand']);
        self::assertSame(['tubeless' => 'yes', 'width_mm' => '28'], $item['attributes']);
        self::assertSame('Plášť', $item['copy']['cs']['inBox']);
        self::assertArrayNotHasKey('inBox', $item['copy']['en']);
        self::assertCount(1, $item['variants']);
        self::assertSame(0, $item['variants'][0]['stock']);

        $public = $this->getJson('/api/products/t-gp5000', ['locale' => 'cs']);
        self::assertSame(['t-parts', 't-tyres'], array_column($public['breadcrumbs'], 'slug'));
        self::assertSame(['tubeless', 'width_mm'], array_column($public['specs'], 'key'));
        self::assertSame('Plášť', $public['inBox']);
    }

    public function testNumberAttributeVariantsAreNormalised(): void
    {
        foreach (['40,0' => '40', '40.50' => '40.5', '0.0' => '0', ' 7 ' => '7'] as $input => $stored) {
            $slug = 't-width-'.md5($input);
            $this->sendJson('POST', '/api/admin/products', $this->payload($slug, ['attributes' => ['width_mm' => $input]]), $this->token);
            self::assertResponseStatusCodeSame(201, $input);
            self::assertSame(['width_mm' => $stored], $this->adminItem($slug)['attributes'], $input);
        }
    }

    public function testInvalidAttributeValuesAreRejected(): void
    {
        $cases = [
            'unknown choice' => ['tubeless' => 'maybe'],
            'unknown key' => ['colour' => 'black'],
            'not a number' => ['width_mm' => 'wide'],
            'negative number' => ['width_mm' => '-28'],
            'nested value' => ['width_mm' => ['28']],
        ];
        foreach ($cases as $case => $attributes) {
            $this->sendJson('POST', '/api/admin/products', $this->payload('t-bad', ['attributes' => $attributes]), $this->token);
            self::assertResponseStatusCodeSame(422, $case);
        }
        self::assertFalse($this->db()->fetchOne("SELECT id FROM product WHERE slug = 't-bad'"));
    }

    public function testNewProductNeedsAnActiveCategory(): void
    {
        $b = $this->builder();
        $off = $b->category('t-off', active: false);
        $b->category('t-under-off', $off);

        foreach (['t-off', 't-under-off', 't-no-such-category'] as $slug) {
            $this->sendJson('POST', '/api/admin/products', $this->payload('t-new', ['category' => $slug]), $this->token);
            self::assertResponseStatusCodeSame(422, $slug);
        }
        $this->sendJson('POST', '/api/admin/products', $this->payload('t-new', ['slug' => 'Not A Slug']), $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateKeepsLongCopyAndGallery(): void
    {
        $product = $this->builder()->product('t-gp5000', $this->tyres, 'Continental', ['width_mm' => '25'], copy: [
            'cs' => ['description' => 'Dlouhý popis pláště.', 'details' => ['Hmotnost 215 g', 'Vector Plus'], 'inBox' => 'Plášť'],
            'en' => ['description' => 'Long tyre description.', 'details' => ['Weight 215 g', 'Vector Plus']],
        ], images: ['/images/gp-1.png', '/images/gp-2.png', '/images/gp-3.png']);
        $this->builder()->variant($product, 'T-GP-25', priceCzk: 150000);
        $id = $product->getId()->toRfc4122();

        $this->sendJson('PUT', '/api/admin/products/'.$id, $this->payload('t-gp5000', [
            'image' => '/images/gp-new.png', 'brand' => 'Conti', 'attributes' => ['width_mm' => '28'], 'priceCzk' => 159000, 'nameEn' => 'GP 5000 S TR',
        ]), $this->token);

        self::assertResponseIsSuccessful();
        $public = $this->getJson('/api/products/t-gp5000', ['locale' => 'en']);
        self::assertSame('GP 5000 S TR', $public['name']);
        self::assertSame('Conti', $public['brand']);
        self::assertSame('Long tyre description.', $public['description']);
        self::assertSame(['Weight 215 g', 'Vector Plus'], $public['details']);
        self::assertSame(['/images/gp-new.png', '/images/gp-1.png', '/images/gp-2.png', '/images/gp-3.png'], $public['images']);
        self::assertSame('/images/gp-new.png', $public['image']);
        self::assertSame(['28'], array_column($public['specs'], 'value'));
        $cs = $this->getJson('/api/products/t-gp5000', ['locale' => 'cs']);
        self::assertSame(159000, $cs['variants'][0]['price']['amount']);
        self::assertSame('Dlouhý popis pláště.', $cs['description']);
        self::assertSame(['Hmotnost 215 g', 'Vector Plus'], $cs['details']);
        // inBox comes from the form; the payload sent none, so it's cleared
        self::assertNull($cs['inBox']);
    }

    public function testUpdateRejectsBadValuesAndTakenSlug(): void
    {
        $b = $this->builder();
        $product = $b->sellable('t-gp5000', $this->tyres, 'Continental');
        $b->sellable('t-taken', $this->tyres);
        $uri = '/api/admin/products/'.$product->getId()->toRfc4122();

        $this->sendJson('PUT', $uri, $this->payload('t-taken'), $this->token);
        self::assertResponseStatusCodeSame(409);
        $this->sendJson('PUT', $uri, $this->payload('t-gp5000', ['attributes' => ['tubeless' => 'maybe']]), $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Continental', $this->adminItem('t-gp5000')['brand']);
        $this->sendJson('PUT', '/api/admin/products/01890000-0000-7000-8000-000000000000', $this->payload('t-ghost'), $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDraftMayKeepItsDeactivatedCategory(): void
    {
        $b = $this->builder();
        $old = $b->category('t-old-range');
        $draft = $b->sellable('t-old-tyre', $old, status: 'draft');
        $other = $b->sellable('t-other', $this->tyres, status: 'draft');
        $old->update('t-old-range', $old->getNames(), null, 0, false, []);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->sendJson('PUT', '/api/admin/products/'.$draft->getId()->toRfc4122(), $this->payload('t-old-tyre', ['category' => 't-old-range', 'status' => 'draft', 'nameEn' => 'Renamed']), $this->token);
        self::assertResponseIsSuccessful();
        // moving another product into it is still refused
        $this->sendJson('PUT', '/api/admin/products/'.$other->getId()->toRfc4122(), $this->payload('t-other', ['category' => 't-old-range', 'status' => 'draft']), $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEmptyAttributesStayJsonObjects(): void
    {
        $created = $this->sendJson('POST', '/api/admin/products', $this->payload('t-plain'), $this->token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['{}', '{}'], $this->storedAttributes('t-plain'));
        $item = $this->rawAdminItem('t-plain');
        self::assertEquals(new \stdClass(), $item->attributes);
        self::assertEquals(new \stdClass(), $item->variants[0]->attributes);

        // clearing values that were set goes back to {} as well
        $uri = '/api/admin/products/'.$created['id'];
        $this->sendJson('PUT', $uri, $this->payload('t-plain', ['attributes' => ['width_mm' => '25']]), $this->token);
        $this->sendJson('PUT', '/api/admin/variants/'.$created['variantId'], $this->variantPayload($item->variants[0]->sku, ['width_mm' => '23']), $this->token);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $uri, $this->payload('t-plain', ['attributes' => ['width_mm' => '']]), $this->token);
        $this->sendJson('PUT', '/api/admin/variants/'.$created['variantId'], $this->variantPayload($item->variants[0]->sku, []), $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(['{}', '{}'], $this->storedAttributes('t-plain'));
        self::assertEquals(new \stdClass(), $this->rawAdminItem('t-plain')->variants[0]->attributes);
    }

    public function testPublishingNeedsAVisibleCategory(): void
    {
        $b = $this->builder();
        $old = $b->category('t-old-range');
        $parent = $b->category('t-closing');
        $child = $b->category('t-closing-child', $parent);
        $inactive = $b->sellable('t-old-tyre', $old, status: 'draft');
        $hidden = $b->sellable('t-hidden-tyre', $child, status: 'draft');
        $old->update('t-old-range', $old->getNames(), null, 0, false, []);
        $parent->update('t-closing', $parent->getNames(), null, 0, false, []);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->sendJson('PUT', '/api/admin/products/'.$inactive->getId()->toRfc4122(), $this->payload('t-old-tyre', ['category' => 't-old-range']), $this->token);
        self::assertResponseStatusCodeSame(422);
        // an active category under an inactive one is just as hidden
        $this->sendJson('PUT', '/api/admin/products/'.$hidden->getId()->toRfc4122(), $this->payload('t-hidden-tyre', ['category' => 't-closing-child']), $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', '/api/admin/products', $this->payload('t-new-hidden', ['category' => 't-closing-child']), $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['draft', 'draft'], $this->db()->fetchFirstColumn("SELECT status FROM product WHERE slug IN ('t-old-tyre', 't-hidden-tyre')"));

        // as a draft it may stay, and once moved to a visible category it can go live
        $this->sendJson('PUT', '/api/admin/products/'.$hidden->getId()->toRfc4122(), $this->payload('t-hidden-tyre', ['category' => 't-closing-child', 'status' => 'draft']), $this->token);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', '/api/admin/products/'.$inactive->getId()->toRfc4122(), $this->payload('t-old-tyre'), $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame('published', $this->db()->fetchOne("SELECT status FROM product WHERE slug = 't-old-tyre'"));
    }

    public function testMovingAProductRechecksVariantOverrides(): void
    {
        $b = $this->builder();
        $from = $b->category('t-from', attributes: [
            CatalogBuilder::attribute('speeds', 'number'),
            CatalogBuilder::attribute('finish', 'choice', options: ['silver', 'black']),
            CatalogBuilder::attribute('width', 'text'),
            CatalogBuilder::attribute('mount', 'text'),
        ]);
        $b->category('t-to', attributes: [
            CatalogBuilder::attribute('speeds', 'number'),
            CatalogBuilder::attribute('width', 'number'),
            CatalogBuilder::attribute('mount', 'choice', options: ['frame']),
        ]);
        $product = $b->product('t-mover', $from, 'KMC');
        $b->variant($product, 'T-MOVER-1', ['finish' => 'black', 'mount' => 'rack', 'speeds' => '12', 'width' => '28.0']);
        $b->variant($product, 'T-MOVER-2', ['finish' => 'silver']);
        $uri = '/api/admin/products/'.$product->getId()->toRfc4122();

        // saving in the same category leaves the overrides alone
        $this->sendJson('PUT', $uri, $this->payload('t-mover', ['category' => 't-from']), $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(['finish' => 'black', 'mount' => 'rack', 'speeds' => '12', 'width' => '28.0'], $this->variantAttributes('T-MOVER-1'));

        $this->sendJson('PUT', $uri, $this->payload('t-mover', ['category' => 't-to']), $this->token);

        self::assertResponseIsSuccessful();
        // finish is unknown there and "rack" isn't a mount option, width becomes a number
        self::assertSame(['speeds' => '12', 'width' => '28'], $this->variantAttributes('T-MOVER-1'));
        self::assertSame([], $this->variantAttributes('T-MOVER-2'));
        self::assertSame('{}', $this->db()->fetchOne("SELECT attributes::text FROM product_variant WHERE sku = 'T-MOVER-2'"));
    }

    private function variantAttributes(string $sku): array
    {
        $attributes = json_decode($this->db()->fetchOne('SELECT attributes FROM product_variant WHERE sku = :sku', ['sku' => $sku]), true);
        // jsonb keeps its own key order
        ksort($attributes);

        return $attributes;
    }

    /** @return array{string, string} product and variant attributes as PostgreSQL has them */
    private function storedAttributes(string $slug): array
    {
        $row = $this->db()->fetchNumeric('SELECT p.attributes::text, v.attributes::text FROM product p JOIN product_variant v ON v.product_id = p.id WHERE p.slug = :slug', ['slug' => $slug]);

        return [$row[0], $row[1]];
    }

    /** Admin list item decoded without the assoc flag, so {} and [] stay different. */
    private function rawAdminItem(string $slug): object
    {
        $this->client->request('GET', '/api/admin/products', ['q' => $slug], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), flags: JSON_THROW_ON_ERROR)->items;
        self::assertCount(1, $items);

        return $items[0];
    }

    private function variantPayload(string $sku, array $attributes): array
    {
        return ['sku' => $sku, 'labelCs' => 'Standardní', 'labelDe' => 'Standard', 'labelEn' => 'Standard', 'priceCzk' => 129000, 'priceEur' => 5200, 'attributes' => $attributes];
    }

    private function payload(string $slug, array $overrides = []): array
    {
        return $overrides + [
            'slug' => $slug, 'category' => 't-tyres', 'status' => 'published',
            'nameCs' => 'Plášť', 'nameDe' => 'Reifen', 'nameEn' => 'Tyre',
            'shortCs' => 'Silniční plášť', 'shortDe' => 'Straßenreifen', 'shortEn' => 'Road tyre',
            'image' => '/images/tyre.png', 'priceCzk' => 129000, 'priceEur' => 5200,
        ];
    }

    private function adminItem(string $slug): array
    {
        $page = $this->getJson('/api/admin/products', ['q' => $slug]);
        self::assertResponseIsSuccessful();
        $items = array_column($page['items'], null, 'slug');
        self::assertArrayHasKey($slug, $items);

        return $items[$slug];
    }
}
