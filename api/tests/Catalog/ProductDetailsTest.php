<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogService;
use App\Tests\Support\PricingDefaults;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ProductDetailsTest extends TestCase
{
    public function testSemicolonSeparatedDetailsBecomeAListInTheProductApi(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'id' => 'demo-product',
            'slug' => 'demo-light',
            'category_id' => 'category-1',
            'category_slug' => 'lights',
            'brand' => null,
            'attributes' => '{}',
            'copy' => json_encode(['cs' => [
                'name' => 'Demo light',
                'short' => 'Front light',
                'description' => 'Front light',
                'details' => '800 lm; USB charging; handlebar mount',
            ]], JSON_THROW_ON_ERROR),
            'image' => '/images/light.png',
            'images' => '[]',
            'badge' => null,
            'from_czk' => 159000,
            'from_eur' => 6400,
            'available_units' => 6,
        ]);
        $db->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'FROM category') ? [[
            'id' => 'category-1', 'parent_id' => null, 'slug' => 'lights', 'names' => '{"cs":"Světla","de":"Beleuchtung","en":"Lights"}',
            'position' => 30, 'active' => true, 'attributes' => '[]',
        ]] : []);

        $product = (new CatalogService($db, PricingDefaults::availability($db)))->product('demo-light', 'cs');

        self::assertSame(['800 lm', 'USB charging', 'handlebar mount'], $product['details']);
        self::assertSame([['slug' => 'lights', 'name' => 'Světla']], $product['breadcrumbs']);
    }
}
