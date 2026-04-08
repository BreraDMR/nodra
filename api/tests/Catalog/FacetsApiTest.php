<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CatalogBuilder;

final class FacetsApiTest extends ApiTestCase
{
    public function testFacetsCountBrandsAndFilterableValuesInTheSubtree(): void
    {
        $b = $this->builder();
        $root = $b->category('t-drive', attributes: [
            CatalogBuilder::attribute('speeds', 'number', labels: ['cs' => 'Rychlosti', 'de' => 'Gänge', 'en' => 'Speeds']),
            CatalogBuilder::attribute('notes', 'text', false),
            CatalogBuilder::attribute('coating', 'choice', options: ['none', 'nickel']),
        ]);
        $chains = $b->category('t-chains', $root, [
            CatalogBuilder::attribute('width_mm', 'number', unit: 'mm', labels: ['cs' => 'Šířka', 'de' => 'Breite', 'en' => 'Width']),
        ]);
        $hidden = $b->category('t-hidden', $root, active: false);

        $mixed = $b->product('t-mixed', $chains, 'Shimano', ['speeds' => '11', 'notes' => 'quiet', 'width_mm' => '5.5']);
        $b->variant($mixed, 'T-MIXED-A', ['speeds' => '12']);
        $b->variant($mixed, 'T-MIXED-B');
        $retired = $b->product('t-retired', $chains, 'Shimano', ['speeds' => '11']);
        $b->variant($retired, 'T-RETIRED-A', ['speeds' => '12'], active: false);
        $b->variant($retired, 'T-RETIRED-B');
        $b->sellable('t-nine', $root, 'KMC', ['speeds' => '9']);
        $b->sellable('t-ten', $root, 'KMC', ['speeds' => '10']);
        $b->sellable('t-draft', $root, 'Campagnolo', ['speeds' => '13'], 'draft');
        $b->sellable('t-hidden-item', $hidden, 'Hidden Brand', ['speeds' => '14']);

        $facets = $this->getJson('/api/categories/t-drive/facets', ['locale' => 'cs']);

        self::assertResponseIsSuccessful();
        self::assertSame('t-drive', $facets['category']);
        self::assertEqualsCanonicalizing([['value' => 'KMC', 'count' => 2], ['value' => 'Shimano', 'count' => 2]], $facets['brands']);
        // text attribute isn't filterable, the choice one has no values yet, width lives on the child only
        self::assertSame(['speeds'], array_column($facets['attributes'], 'key'));
        self::assertSame([
            'key' => 'speeds', 'label' => 'Rychlosti', 'type' => 'number', 'unit' => null,
            'values' => [
                ['value' => '9', 'label' => '9', 'count' => 1],
                ['value' => '10', 'label' => '10', 'count' => 1],
                ['value' => '11', 'label' => '11', 'count' => 2],
                ['value' => '12', 'label' => '12', 'count' => 1],
            ],
        ], $facets['attributes'][0]);

        $child = $this->getJson('/api/categories/t-chains/facets', ['locale' => 'cs']);
        self::assertSame([['value' => 'Shimano', 'count' => 2]], $child['brands']);
        self::assertSame(['speeds', 'width_mm'], array_column($child['attributes'], 'key'));
        self::assertSame([['value' => '5.5', 'label' => '5,5', 'count' => 1]], $child['attributes'][1]['values']);
        self::assertSame('mm', $child['attributes'][1]['unit']);
    }

    public function testChoiceValuesUseLocalizedLabels(): void
    {
        $b = $this->builder();
        $lights = $b->category('t-lights', attributes: [CatalogBuilder::attribute('position', 'choice', options: [
            ['value' => 'front', 'labels' => ['cs' => 'Přední', 'de' => 'Vorne', 'en' => 'Front']],
            ['value' => 'rear', 'labels' => ['cs' => 'Zadní', 'de' => 'Hinten', 'en' => 'Rear']],
        ])]);
        $b->sellable('t-front', $lights, 'CatEye', ['position' => 'front']);
        $b->sellable('t-rear', $lights, 'CatEye', ['position' => 'rear']);

        $facets = $this->getJson('/api/categories/t-lights/facets', ['locale' => 'de']);

        self::assertSame([
            ['value' => 'rear', 'label' => 'Hinten', 'count' => 1],
            ['value' => 'front', 'label' => 'Vorne', 'count' => 1],
        ], $facets['attributes'][0]['values']);
    }

    public function testUnknownOrInactiveCategoryIsNotFound(): void
    {
        $b = $this->builder();
        $off = $b->category('t-off', active: false);
        $b->category('t-under-off', $off);

        foreach (['t-no-such-category', 't-off', 't-under-off'] as $slug) {
            $this->getJson('/api/categories/'.$slug.'/facets');
            self::assertResponseStatusCodeSame(404, $slug);
        }
    }
}
