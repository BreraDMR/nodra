<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CatalogBuilder;

final class ProductDetailApiTest extends ApiTestCase
{
    public function testDetailShowsPathLocalizedSpecsAndVariantIdentifiers(): void
    {
        $b = $this->builder();
        $root = $b->category('t-parts', attributes: [CatalogBuilder::attribute('speeds', 'number', labels: ['cs' => 'Rychlosti', 'de' => 'Gänge', 'en' => 'Speeds'])],
            names: ['cs' => 'Díly', 'de' => 'Teile', 'en' => 'Parts']);
        $chains = $b->category('t-chains', $root, [
            CatalogBuilder::attribute('material', 'choice', options: [
                ['value' => 'steel', 'labels' => ['cs' => 'Ocel', 'de' => 'Stahl', 'en' => 'Steel']],
                ['value' => 'ti', 'labels' => ['cs' => 'Titan', 'de' => 'Titan', 'en' => 'Titanium']],
            ], labels: ['cs' => 'Materiál', 'de' => 'Material', 'en' => 'Material']),
            CatalogBuilder::attribute('weight_g', 'number', false, unit: 'g', labels: ['cs' => 'Hmotnost', 'de' => 'Gewicht', 'en' => 'Weight']),
        ], names: ['cs' => 'Řetězy', 'de' => 'Ketten', 'en' => 'Chains']);
        $chain = $b->product('t-chain', $chains, 'Shimano', ['speeds' => '12', 'material' => 'steel', 'weight_g' => '257.5'], copy: [
            'cs' => ['inBox' => 'Řetěz, spojka'],
            'en' => ['inBox' => 'Chain, quick link'],
        ]);
        $light = $b->variant($chain, 'T-CHAIN-TI', ['material' => 'ti', 'weight_g' => '230'], mpn: 'CN-M8100-TI', ean: '8594000000013');
        $b->variant($chain, 'T-CHAIN-STD', mpn: 'CN-M8100');
        $b->variant($chain, 'T-CHAIN-GONE', active: false, mpn: 'CN-OLD');
        $b->offer($chain, 'https://supplier.example/offer/47fbf2a9ab', 'Velo Seller s.r.o.');
        $b->offer($chain, 'https://bike24.example/secret-variant-listing', 'Other Seller GmbH', $light);

        $cs = $this->getJson('/api/products/t-chain', ['locale' => 'cs']);

        self::assertResponseIsSuccessful();
        self::assertSame([['slug' => 't-parts', 'name' => 'Díly'], ['slug' => 't-chains', 'name' => 'Řetězy']], $cs['breadcrumbs']);
        self::assertSame('Řetězy', $cs['categoryName']);
        self::assertSame('Shimano', $cs['brand']);
        self::assertSame([
            ['key' => 'speeds', 'label' => 'Rychlosti', 'value' => '12', 'unit' => null],
            ['key' => 'material', 'label' => 'Materiál', 'value' => 'Ocel', 'unit' => null],
            ['key' => 'weight_g', 'label' => 'Hmotnost', 'value' => '257,5', 'unit' => 'g'],
        ], $cs['specs']);
        self::assertSame('Řetěz, spojka', $cs['inBox']);
        self::assertSame(['T-CHAIN-STD', 'T-CHAIN-TI'], array_column($cs['variants'], 'sku'));
        [$standard, $titanium] = $cs['variants'];
        self::assertSame(['CN-M8100', null, []], [$standard['mpn'], $standard['ean'], $standard['specs']]);
        self::assertSame('CN-M8100-TI', $titanium['mpn']);
        self::assertSame('8594000000013', $titanium['ean']);
        self::assertSame([
            ['key' => 'material', 'label' => 'Materiál', 'value' => 'Titan', 'unit' => null],
            ['key' => 'weight_g', 'label' => 'Hmotnost', 'value' => '230', 'unit' => 'g'],
        ], $titanium['specs']);

        $en = $this->getJson('/api/products/t-chain', ['locale' => 'en']);
        self::assertSame(['12', 'Steel', '257.5'], array_column($en['specs'], 'value'));
        self::assertSame('Chain, quick link', $en['inBox']);
        $de = $this->getJson('/api/products/t-chain', ['locale' => 'de']);
        self::assertSame(['12', 'Stahl', '257,5'], array_column($de['specs'], 'value'));
        self::assertNull($de['inBox']);

        // supplier listings stay internal, on the detail page and in the list; since D02 only the computed
        // availability and lead time are public, see PublicResponsePrivacyTest for the full key check
        foreach (['cs', 'de', 'en'] as $locale) {
            foreach (['/api/products/t-chain', '/api/products'] as $uri) {
                $this->getJson($uri, ['locale' => $locale, 'category' => 't-parts']);
                $raw = strtolower((string) $this->client->getResponse()->getContent());
                self::assertStringContainsString('t-chain', $raw);
                foreach (['supplier', 'seller', 'allegro', 'bike24', 'secret', 'offer'] as $needle) {
                    self::assertStringNotContainsString($needle, $raw, $uri.' '.$locale);
                }
            }
        }
    }

    public function testUnpublishedProductIsNotFound(): void
    {
        $this->builder()->sellable('t-draft', $this->builder()->category('t-parts'), status: 'draft');

        $this->getJson('/api/products/t-draft');
        self::assertResponseStatusCodeSame(404);
        $this->getJson('/api/products/t-no-such-product');
        self::assertResponseStatusCodeSame(404);
    }
}
