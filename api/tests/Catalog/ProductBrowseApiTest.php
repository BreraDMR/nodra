<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CatalogBuilder;

final class ProductBrowseApiTest extends ApiTestCase
{
    public function testCategoryFilterIncludesActiveSubcategories(): void
    {
        $b = $this->builder();
        $root = $b->category('t-parts');
        $chains = $b->category('t-chains', $root);
        $pins = $b->category('t-pins', $chains);
        $hidden = $b->category('t-hidden', $root, active: false);
        $b->sellable('t-root-item', $root);
        $b->sellable('t-chain-item', $chains);
        $b->sellable('t-pin-item', $pins);
        $b->sellable('t-chain-draft', $chains, status: 'draft');
        $b->sellable('t-hidden-item', $hidden);

        self::assertSame(['t-chain-item', 't-pin-item', 't-root-item'], $this->slugs(['category' => 't-parts']));
        self::assertSame(['t-chain-item', 't-pin-item'], $this->slugs(['category' => 't-chains']));
        self::assertSame(['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0], $this->getJson('/api/products', ['category' => 't-no-such-category']));
        self::assertSame([], $this->slugs(['category' => 't-hidden']));
    }

    public function testAttributeFilterUsesTheVariantValueBeforeTheProductValue(): void
    {
        $b = $this->builder();
        $chains = $b->category('t-chains', attributes: [
            CatalogBuilder::attribute('speeds', 'number'),
            CatalogBuilder::attribute('material', 'choice', options: ['steel', 'alu']),
        ]);
        // A overrides to 12, B inherits 11 from the product
        $mixed = $b->product('t-mixed', $chains, 'Shimano', ['speeds' => '11', 'material' => 'steel']);
        $b->variant($mixed, 'T-MIXED-A', ['speeds' => '12']);
        $b->variant($mixed, 'T-MIXED-B');
        // the 12-speed override sits on an inactive variant, so only 11 is on sale
        $retired = $b->product('t-retired', $chains, 'SRAM', ['speeds' => '11', 'material' => 'alu']);
        $b->variant($retired, 'T-RETIRED-A', ['speeds' => '12'], active: false);
        $b->variant($retired, 'T-RETIRED-B');

        self::assertSame(['t-mixed', 't-retired'], $this->slugs(['category' => 't-chains', 'attr' => ['speeds' => '11']]));
        self::assertSame(['t-mixed'], $this->slugs(['category' => 't-chains', 'attr' => ['speeds' => '12']]));
        self::assertSame(['t-mixed', 't-retired'], $this->slugs(['category' => 't-chains', 'attr' => ['speeds' => '10, 12,11']]));
        self::assertSame(['t-retired'], $this->slugs(['category' => 't-chains', 'attr' => ['speeds' => '11', 'material' => 'alu']]));
        self::assertSame([], $this->slugs(['category' => 't-chains', 'attr' => ['speeds' => '12', 'material' => 'alu']]));
        self::assertSame(['t-retired'], $this->slugs(['category' => 't-chains', 'brand' => 'SRAM']));
        self::assertSame(['t-mixed'], $this->slugs(['category' => 't-chains', 'brand' => 'Shimano', 'attr' => ['speeds' => '12']]));
    }

    public function testSearchFindsPartialMpnAndExactEan(): void
    {
        $b = $this->builder();
        $parts = $b->category('t-parts');
        $derailleur = $b->product('t-derailleur', $parts, 'Shimano');
        $b->variant($derailleur, 'T-RD-1', mpn: 'RD-M8100-SGS', ean: '4006381333931');
        $inactive = $b->product('t-old-derailleur', $parts, 'Shimano');
        $b->variant($inactive, 'T-RD-OLD-1', active: false, mpn: 'RD-M8000-SGS');
        $b->variant($inactive, 'T-RD-OLD-2');
        $b->variant($b->product('t-grip', $parts, copy: ['en' => ['name' => 'Grip 100% merino']]), 'T-GRIP-1');
        $b->variant($b->product('t-plain', $parts, copy: ['en' => ['name' => 'Plain grip']]), 'T-PLAIN-1');

        self::assertSame(['t-derailleur'], $this->slugs(['category' => 't-parts', 'q' => 'm8100']));
        self::assertSame(['t-derailleur'], $this->slugs(['category' => 't-parts', 'q' => '4006381333931']));
        self::assertSame(['t-derailleur'], $this->slugs(['category' => 't-parts', 'q' => '4 006381 333931']));
        self::assertSame(['t-derailleur'], $this->slugs(['category' => 't-parts', 'q' => '4006381-333931']));
        // EAN has to match whole, part of it is not a hit
        self::assertSame([], $this->slugs(['category' => 't-parts', 'q' => '400638133']));
        // MPN on an inactive variant is not searchable
        self::assertSame([], $this->slugs(['category' => 't-parts', 'q' => 'M8000']));
        // % is a literal character, not a wildcard
        self::assertSame(['t-grip'], $this->slugs(['category' => 't-parts', 'q' => '%']));
        self::assertSame(['t-grip'], $this->slugs(['category' => 't-parts', 'q' => '100%']));
        self::assertSame([], $this->slugs(['category' => 't-parts', 'q' => 'grip_']));
    }

    /** @return list<string> product slugs of the first page, sorted */
    private function slugs(array $query): array
    {
        $result = $this->getJson('/api/products', $query);
        self::assertResponseIsSuccessful();
        $slugs = array_column($result['items'], 'slug');
        sort($slugs);

        return $slugs;
    }
}
