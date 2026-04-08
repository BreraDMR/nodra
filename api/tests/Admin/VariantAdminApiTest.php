<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CatalogBuilder;

final class VariantAdminApiTest extends ApiTestCase
{
    private string $token;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginAdmin();
        $b = $this->builder();
        $chains = $b->category('t-chains', attributes: [
            CatalogBuilder::attribute('speeds', 'number'),
            CatalogBuilder::attribute('finish', 'choice', options: ['silver', 'black']),
        ]);
        $this->productId = $b->sellable('t-chain', $chains, 'KMC', ['speeds' => '11', 'finish' => 'silver'])->getId()->toRfc4122();
    }

    public function testVariantStoresIdentifiersAndAttributeOverrides(): void
    {
        $created = $this->sendJson('POST', $this->variantsUri(), $this->payload('T-CHAIN-12', [
            'mpn' => ' X12-TI ', 'ean' => '8594-0000-0001-3', 'attributes' => ['speeds' => '12,0', 'finish' => ''],
        ]), $this->token);

        self::assertResponseStatusCodeSame(201);
        $variant = $this->adminVariant('T-CHAIN-12');
        self::assertSame($created['id'], $variant['id']);
        self::assertSame(['X12-TI', '8594000000013', ['speeds' => '12']], [$variant['mpn'], $variant['ean'], $variant['attributes']]);

        $public = $this->getJson('/api/products/t-chain', ['locale' => 'en']);
        $bySku = array_column($public['variants'], null, 'sku');
        self::assertSame([['key' => 'speeds', 'label' => 'speeds en', 'value' => '12', 'unit' => null]], $bySku['T-CHAIN-12']['specs']);
        self::assertSame(['t-chain'], array_column($this->getJson('/api/products', ['category' => 't-chains', 'attr' => ['speeds' => '12']])['items'], 'slug'));
        self::assertSame(['t-chain'], array_column($this->getJson('/api/products', ['q' => '8594000000013'])['items'], 'slug'));

        $this->sendJson('PUT', '/api/admin/variants/'.$created['id'], $this->payload('T-CHAIN-12', ['attributes' => ['finish' => 'black'], 'active' => false]), $this->token);
        self::assertResponseIsSuccessful();
        $variant = $this->adminVariant('T-CHAIN-12');
        self::assertSame([null, null, ['finish' => 'black'], false], [$variant['mpn'], $variant['ean'], $variant['attributes'], $variant['active']]);
    }

    public function testEanMustBeValidAndUnique(): void
    {
        $this->sendJson('POST', $this->variantsUri(), $this->payload('T-BAD-EAN', ['ean' => '8594000000010']), $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', $this->variantsUri(), $this->payload('T-SHORT-EAN', ['ean' => '12345']), $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->db()->fetchOne("SELECT id FROM product_variant WHERE sku IN ('T-BAD-EAN', 'T-SHORT-EAN')"));

        $first = $this->sendJson('POST', $this->variantsUri(), $this->payload('T-FIRST', ['ean' => '8594000000013']), $this->token);
        self::assertResponseStatusCodeSame(201);
        // same code written with spaces is still the same EAN
        $this->sendJson('POST', $this->variantsUri(), $this->payload('T-COPY', ['ean' => '859 4000 000 013']), $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertFalse($this->db()->fetchOne("SELECT id FROM product_variant WHERE sku = 'T-COPY'"));

        $second = $this->sendJson('POST', $this->variantsUri(), $this->payload('T-SECOND', ['ean' => '8594000000020']), $this->token);
        self::assertResponseStatusCodeSame(201);
        $this->sendJson('PUT', '/api/admin/variants/'.$second['id'], $this->payload('T-SECOND', ['ean' => '8594000000013']), $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('8594000000020', $this->adminVariant('T-SECOND')['ean']);
        // saving a variant with its own EAN is fine
        $this->sendJson('PUT', '/api/admin/variants/'.$first['id'], $this->payload('T-FIRST', ['ean' => '8594000000013', 'mpn' => 'NEW']), $this->token);
        self::assertResponseIsSuccessful();
    }

    public function testVariantRejectsUnknownAttributesAndSkuChanges(): void
    {
        $this->sendJson('POST', $this->variantsUri(), $this->payload('T-ODD', ['attributes' => ['colour' => 'red']]), $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', $this->variantsUri(), $this->payload('T-ODD', ['attributes' => ['finish' => 'gold']]), $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', $this->variantsUri(), $this->payload('T-CHAIN-1'), $this->token);
        self::assertResponseStatusCodeSame(409);

        $created = $this->sendJson('POST', $this->variantsUri(), $this->payload('T-OK'), $this->token);
        self::assertResponseStatusCodeSame(201);
        $this->sendJson('PUT', '/api/admin/variants/'.$created['id'], $this->payload('T-RENAMED'), $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    private function variantsUri(): string
    {
        return '/api/admin/products/'.$this->productId.'/variants';
    }

    private function payload(string $sku, array $overrides = []): array
    {
        return $overrides + ['sku' => $sku, 'labelCs' => 'Stříbrná', 'labelDe' => 'Silber', 'labelEn' => 'Silver', 'priceCzk' => 89000, 'priceEur' => 3600, 'active' => true];
    }

    private function adminVariant(string $sku): array
    {
        $page = $this->getJson('/api/admin/products', ['q' => 't-chain']);
        $variants = array_column(array_column($page['items'], null, 'slug')['t-chain']['variants'], null, 'sku');
        self::assertArrayHasKey($sku, $variants);

        return $variants[$sku];
    }
}
