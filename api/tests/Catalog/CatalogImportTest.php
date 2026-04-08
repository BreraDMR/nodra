<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Admin\AdminService;
use App\Admin\CategoryAdminService;
use App\Admin\CategoryWriteRequest;
use App\Admin\ProductWriteRequest;
use App\Admin\SupplierOfferAdminService;
use App\Admin\SupplierOfferWriteRequest;
use App\Catalog\AttributeSchema;
use App\Catalog\CatalogSeed;
use App\Catalog\CatalogService;
use App\Entity\Category;
use App\Entity\Product;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CatalogImportTest extends KernelTestCase
{
    public function testRepeatedImportAddsNothingAndKeepsAdminEdits(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        $seedItems = CatalogSeed::items();
        $seedSlugs = array_column(CatalogSeed::categories(), 'slug');
        $existingSlugs = $db->fetchFirstColumn('SELECT slug FROM category');

        $first = $this->import();
        self::assertStringContainsString(sprintf('Categories: %d added', count(array_diff($seedSlugs, $existingSlugs))), $first);
        self::assertStringContainsString(sprintf('Products: %d added, 0 filled', count($seedItems)), $first);
        $counts = $this->counts($db);
        self::assertSame(count($seedItems), $counts['products']);
        self::assertGreaterThan(0, $counts['offers']);

        // a bags product with both attributes, edited by the admin before the next import
        $item = current(array_filter($seedItems, static fn (array $i): bool => $i['category'] === 'bags' && isset($i['attributes']['volume_l'], $i['brand'])));
        self::assertIsArray($item, 'Seed data needs a bag with a volume');
        $this->editProduct($item['slug'], 'Edited Brand', ['mount' => 'rack']);
        $this->editBagsCategory();
        $this->matchSeedOffer($item['slug']);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $second = $this->import();

        self::assertStringContainsString('Categories: 0 added, 0 filled. Products: 0 added, 0 filled, 0 supplier offers added.', $second);
        self::assertSame($counts, $this->counts($db));
        $product = $db->fetchAssociative('SELECT brand, attributes FROM product WHERE slug = :slug', ['slug' => $item['slug']]);
        self::assertSame('Edited Brand', $product['brand']);
        self::assertSame(['mount' => 'rack'], json_decode($product['attributes'], true));
        $bags = json_decode($db->fetchOne("SELECT attributes FROM category WHERE slug = 'bags'"), true);
        self::assertSame(['mount'], array_column($bags, 'key'));
        self::assertSame('Mounting', $bags[0]['labels']['en']);
    }

    public function testImportFillsBlankBrandAndAttributesOnly(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        $this->import();
        $item = current(array_filter(CatalogSeed::items(), static fn (array $i): bool => isset($i['brand'], $i['attributes']) && $i['attributes'] !== []));
        $db->executeStatement("UPDATE product SET brand = NULL, attributes = '{}' WHERE slug = :slug", ['slug' => $item['slug']]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertStringContainsString('Products: 0 added, 1 filled', $this->import());

        $product = $db->fetchAssociative('SELECT brand, attributes FROM product WHERE slug = :slug', ['slug' => $item['slug']]);
        self::assertSame($item['brand'], $product['brand']);
        self::assertEqualsCanonicalizing(array_keys($item['attributes']), array_keys(json_decode($product['attributes'], true)));
    }

    public function testImportedCatalogueHasWorkingFacets(): void
    {
        self::bootKernel();
        $this->import();
        $lights = array_filter(CatalogSeed::items(), static fn (array $i): bool => $i['category'] === 'lights' && isset($i['attributes']['position']));

        // seeded variants carry no overrides, which used to break the facet query
        $facets = static::getContainer()->get(CatalogService::class)->facets('lights', 'en');

        $position = array_column($facets['attributes'], null, 'key')['position'];
        self::assertSame(count($lights), array_sum(array_column($position['values'], 'count')));
        self::assertSame(count(array_filter(CatalogSeed::items(), static fn (array $i): bool => $i['category'] === 'lights')), array_sum(array_column($facets['brands'], 'count')));
    }

    private function import(): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        self::assertSame(0, $tester->execute([]));

        return $tester->getDisplay();
    }

    private function counts(Connection $db): array
    {
        return $db->fetchAssociative('SELECT (SELECT COUNT(*) FROM category) AS categories, (SELECT COUNT(*) FROM product) AS products,
            (SELECT COUNT(*) FROM product_variant) AS variants, (SELECT COUNT(*) FROM supplier_offer) AS offers');
    }

    private function editProduct(string $slug, string $brand, array $attributes): void
    {
        $product = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Product::class)->findOneBy(['slug' => $slug]);
        $copy = $product->getCopy();
        $variant = static::getContainer()->get(Connection::class)->fetchAssociative('SELECT price_czk, price_eur FROM product_variant WHERE product_id = :id ORDER BY sku LIMIT 1', ['id' => $product->getId()->toRfc4122()]);
        static::getContainer()->get(AdminService::class)->updateProduct($product->getId()->toRfc4122(), new ProductWriteRequest(
            $slug, $product->getCategory()->getSlug(),
            $copy['cs']['name'], $copy['de']['name'], $copy['en']['name'],
            $copy['cs']['short'], $copy['de']['short'], $copy['en']['short'],
            $product->getImage(), $product->getStatus(), (int) $variant['price_czk'], (int) $variant['price_eur'],
            brand: $brand, attributes: $attributes,
        ));
    }

    /** Drops volume_l and renames the mount label, the way the admin form would send it. */
    private function editBagsCategory(): void
    {
        $bags = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->findOneBy(['slug' => 'bags']);
        $attributes = array_values(array_filter(AttributeSchema::forAdmin($bags->getAttributes()), static fn (array $a): bool => $a['key'] !== 'volume_l'));
        self::assertSame(['mount'], array_column($attributes, 'key'));
        $attributes[0]['labelEn'] = 'Mounting';
        $names = $bags->getNames();
        static::getContainer()->get(CategoryAdminService::class)->update($bags->getId()->toRfc4122(), new CategoryWriteRequest(
            'bags', $names['cs'], $names['de'], $names['en'], null, $bags->getPosition(), true, $attributes,
        ));
    }

    /** The admin confirms the seeded listing is exactly the first variant. */
    private function matchSeedOffer(string $slug): void
    {
        $db = static::getContainer()->get(Connection::class);
        $offer = $db->fetchAssociative('SELECT o.* FROM supplier_offer o JOIN product p ON p.id = o.product_id WHERE p.slug = :slug', ['slug' => $slug]);
        self::assertIsArray($offer, 'Seed product should come with a supplier offer');
        $variantId = $db->fetchOne('SELECT id FROM product_variant WHERE product_id = :id ORDER BY sku LIMIT 1', ['id' => $offer['product_id']]);
        static::getContainer()->get(SupplierOfferAdminService::class)->update($offer['id'], new SupplierOfferWriteRequest(
            $offer['supplier'], $offer['url'], $offer['title'], $offer['currency'], (int) $offer['price_minor'], $offer['checked_at'],
            'matched', 'Seed Seller', $offer['reported_quantity'] === null ? null : (int) $offer['reported_quantity'], 3, 6, $variantId,
        ));
    }
}
