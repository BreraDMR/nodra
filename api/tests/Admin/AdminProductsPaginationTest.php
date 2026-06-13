<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\AdminProductsQuery;
use App\Admin\AdminService;
use App\Pricing\PriceHistory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;

final class AdminProductsPaginationTest extends TestCase
{
    public function testProductsUseOneVariantQueryForTheRequestedPage(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')->willReturn('92');
        $db->expects(self::exactly(3))->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params): array {
                if (str_contains($sql, 'FROM product p')) {
                    self::assertSame(24, $params['limit']);
                    self::assertSame(24, $params['offset']);

                    return [[
                        'id' => 'product-2', 'slug' => 'demo-light', 'category_id' => 'category-1', 'category_slug' => 'lights',
                        'brand' => 'Demo', 'attributes' => '{}',
                        'status' => 'published', 'name' => 'Demo light', 'copy' => '{"en":{"name":"Demo light"}}',
                        'image' => '/images/light.png', 'badge' => null, 'featured_rank' => 25,
                    ]];
                }

                self::assertSame(['product-2'], $params['ids']);
                if (str_contains($sql, 'FROM supplier_offer')) {
                    return [];
                }

                return [[
                    'product_id' => 'product-2', 'id' => 'variant-2', 'sku' => 'DEMO-2',
                    'label' => '{"en":"Standard"}', 'price_czk' => 100000,
                    'price_eur' => 4000, 'stock' => 4, 'active' => true,
                    'color' => null, 'size' => null, 'mpn' => null, 'ean' => null, 'attributes' => '{}',
                    'rrp_minor' => null, 'rrp_currency' => null, 'rrp_source' => null, 'rrp_checked_at' => null,
                    'market_price_minor' => null, 'market_price_source' => null, 'market_checked_at' => null,
                ]];
            },
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $history = new PriceHistory($em, $db, $this->createStub(Security::class), new MockClock());
        $service = new AdminService($em, $db, $history, new MockClock());

        $result = $service->products(new AdminProductsQuery(2));

        self::assertSame(['page' => 2, 'pages' => 4, 'total' => 92], array_intersect_key($result, array_flip(['page', 'pages', 'total'])));
        self::assertSame('DEMO-2', $result['items'][0]['variants'][0]['sku']);
        self::assertSame([], $result['items'][0]['supplierOffers']);
        self::assertSame('lights', $result['items'][0]['category']);
    }
}
