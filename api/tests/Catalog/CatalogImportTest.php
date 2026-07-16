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
use App\Catalog\CatalogSeeder;
use App\Catalog\CatalogService;
use App\Entity\Category;
use App\Entity\Product;
use App\Pricing\AvailabilityService;
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
        // a card from before brands and attributes existed: blank and never described
        $db->executeStatement("UPDATE product SET brand = NULL, attributes = '{}', described_at = NULL WHERE slug = :slug", ['slug' => $item['slug']]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertStringContainsString('Products: 0 added, 1 filled', $this->import());

        $product = $db->fetchAssociative('SELECT brand, attributes FROM product WHERE slug = :slug', ['slug' => $item['slug']]);
        self::assertSame($item['brand'], $product['brand']);
        self::assertEqualsCanonicalizing(array_keys($item['attributes']), array_keys(json_decode($product['attributes'], true)));
    }

    public function testImportKeepsBrandAndAttributesTheAdminCleared(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        $this->import();
        $item = current(array_filter(CatalogSeed::items(), static fn (array $i): bool => isset($i['brand'], $i['attributes']) && $i['attributes'] !== []));
        $this->editProduct($item['slug'], '', []);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertStringContainsString('Products: 0 added, 0 filled', $this->import());

        $product = $db->fetchAssociative('SELECT brand, attributes::text AS attributes FROM product WHERE slug = :slug', ['slug' => $item['slug']]);
        self::assertSame(['brand' => null, 'attributes' => '{}'], $product);
    }

    public function testImportSkipsSeedAttributesTheAdminMovedUpTheTree(): void
    {
        self::bootKernel();
        $this->import();
        // chains keeps speeds, cables is left with nothing of its own
        $this->moveAttributeUp('links', 'chains');
        $this->moveAttributeUp('cable_type', 'cables');
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $output = $this->import();

        // the seeded marker keeps the emptied cables category out of the seed entirely: no refill, no clash
        self::assertStringContainsString('Categories: 0 added, 0 filled.', $output);
        self::assertStringNotContainsString('"links"', $output);
        self::assertSame([], $this->categoryKeys('cables'));
        self::assertSame(['speeds'], $this->categoryKeys('chains'));
        self::assertSame(['links', 'cable_type'], $this->categoryKeys('components'));
    }

    public function testImportDoesNotRefillKeysTheAdminMovedDown(): void
    {
        self::bootKernel();
        $this->import();
        $categories = static::getContainer()->get(CategoryAdminService::class);
        $bags = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->findOneBy(['slug' => 'bags']);
        $definitions = AttributeSchema::forAdmin($bags->getAttributes());
        $this->saveCategory($bags, []);
        $categories->create(new CategoryWriteRequest('t-frame-bags', 'Rámové', 'Rahmen', 'Frame', $bags->getId()->toRfc4122(), 0, true, $definitions));
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $output = $this->import();

        // bags carries the seeded marker, so the seed never tries to refill the moved key
        self::assertStringContainsString('Categories: 0 added, 0 filled.', $output);
        self::assertSame([], $this->categoryKeys('bags'));
        self::assertSame(['mount', 'volume_l'], $this->categoryKeys('t-frame-bags'));
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

    public function testAnEmptiedCategoryStaysEmpty(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        $this->import();
        self::assertNotSame([], $this->categoryKeys('bags'));

        // the admin empties the bags category in the form and saves
        $bags = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->findOneBy(['slug' => 'bags']);
        $names = $bags->getNames();
        static::getContainer()->get(CategoryAdminService::class)->update($bags->getId()->toRfc4122(), new CategoryWriteRequest(
            'bags', $names['cs'], $names['de'], $names['en'], $bags->getParent()?->getId()->toRfc4122(), $bags->getPosition(), true, [],
        ));
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertStringContainsString('Categories: 0 added, 0 filled.', $this->import());
        self::assertSame([], $this->categoryKeys('bags'), 'the emptied category is never refilled by the seed');
    }

    public function testSeedAttributesThatFailTheDefinitionsBecomeARowError(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $seeder = static::getContainer()->get(CatalogSeeder::class);
        // categories first, then the admin tightens volume_l into a choice the seed values don't fit
        $seeder->seedCategories();
        $bags = $em->getRepository(Category::class)->findOneBy(['slug' => 'bags']);
        $names = $bags->getNames();
        $definitions = array_column(AttributeSchema::forAdmin($bags->getAttributes()), null, 'key');
        $volume = $definitions['volume_l'];
        self::assertSame('number', $volume['type']);
        $volume['type'] = 'choice';
        $volume['options'] = [
            ['value' => 'small', 'labelCs' => 'Malý', 'labelDe' => 'Klein', 'labelEn' => 'Small'],
            ['value' => 'large', 'labelCs' => 'Velký', 'labelDe' => 'Groß', 'labelEn' => 'Large'],
        ];
        static::getContainer()->get(CategoryAdminService::class)->update($bags->getId()->toRfc4122(), new CategoryWriteRequest(
            'bags', $names['cs'], $names['de'], $names['en'], $bags->getParent()?->getId()->toRfc4122(), $bags->getPosition(), true, [$volume],
        ));
        $em->clear();

        $result = $seeder->seedProducts();

        self::assertGreaterThan(0, count($result['errors']));
        self::assertStringContainsString('volume_l', $result['errors'][0]['message']);
        self::assertStringContainsString('Seed product', $result['errors'][0]['message']);
        // the rest of the seed still imported; only the failing cards are missing
        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM product');
        self::assertSame(count(CatalogSeed::items()) - count($result['errors']), $total);
        self::assertFalse($db->fetchOne("SELECT 1 FROM product WHERE slug = 'sks-explorer-edge-1l'"), 'the card that fails the changed definitions is skipped');

        // and the run landed in the journal with its row errors
        $output = $this->import();
        self::assertStringContainsString('Row error:', $output);
        $run = $db->fetchAssociative("SELECT counts::text AS counts, errors::text AS errors FROM import_run WHERE source = 'seed' ORDER BY started_at DESC LIMIT 1");
        self::assertIsArray($run);
        self::assertSame(count($result['errors']), json_decode($run['counts'], true)['errors']);
        self::assertCount(json_decode($run['counts'], true)['errors'], json_decode($run['errors'], true));
    }

    public function testSeededDemoOffersGiveVariantsOrderableLeadTimes(): void
    {
        $db = static::getContainer()->get(Connection::class);
        $this->import();

        $rows = $db->fetchAllAssociative("SELECT o.*, v.product_id FROM supplier_offer o JOIN product_variant v ON v.id = o.variant_id WHERE o.supplier = 'demo'");
        // every second seeded variant carries a demo offer, deterministic with the seed file
        self::assertSame((int) ($db->fetchOne('SELECT COUNT(*) FROM product_variant') / 2), count($rows));
        self::assertGreaterThan(0, count($rows));
        foreach ($rows as $row) {
            self::assertSame('matched', $row['verification_status'], $row['url']);
            self::assertNotNull($row['lead_time_min_days'], $row['url']);
            self::assertNotNull($row['lead_time_max_days'], $row['url']);
            self::assertGreaterThanOrEqual(new \DateTimeImmutable('-7 days'), new \DateTimeImmutable($row['checked_at']), 'the check is fresh');
            self::assertStringStartsWith('https://demo.nodra.test/', $row['url']);
        }

        // a demo-offer variant is orderable on the shop, with the offer's lead time plus one handling day
        $availability = static::getContainer()->get(AvailabilityService::class)->forProducts([$rows[0]['product_id']]);
        $public = $availability[$rows[0]['product_id']]['variants'][$rows[0]['variant_id']];
        self::assertSame('orderable', $public['status']);
        self::assertSame($rows[0]['lead_time_min_days'] + 1, $public['leadTimeMinDays']);
        self::assertSame($rows[0]['lead_time_max_days'] + 1, $public['leadTimeMaxDays']);

        // a repeated import adds no demo offers
        self::assertStringContainsString('0 supplier offers added', $this->import());
        self::assertSame(count($rows), (int) $db->fetchOne("SELECT COUNT(*) FROM supplier_offer WHERE supplier = 'demo'"));

        // and a stale demo check is refreshed, so the stand never drifts back to "check needed"
        $db->executeStatement("UPDATE supplier_offer SET checked_at = NOW() - INTERVAL '30 days' WHERE supplier = 'demo'");
        // the raw update bypassed the identity map, the next import must re-read the rows
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->import();
        self::assertSame(count($rows), (int) $db->fetchOne("SELECT COUNT(*) FROM supplier_offer WHERE supplier = 'demo'"));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM supplier_offer WHERE supplier = 'demo' AND checked_at < NOW() - INTERVAL '8 days'"));
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

    /** Takes the key off the category and adds it to its parent, in the order the admin form allows. */
    private function moveAttributeUp(string $key, string $from): void
    {
        $category = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->findOneBy(['slug' => $from]);
        $definitions = AttributeSchema::forAdmin($category->getAttributes());
        $moved = array_values(array_filter($definitions, static fn (array $d): bool => $d['key'] === $key));
        self::assertCount(1, $moved, $key);
        $this->saveCategory($category, array_values(array_filter($definitions, static fn (array $d): bool => $d['key'] !== $key)));
        $parent = $category->getParent();
        $this->saveCategory($parent, [...AttributeSchema::forAdmin($parent->getAttributes()), ...$moved]);
    }

    private function saveCategory(Category $category, array $attributes): void
    {
        $names = $category->getNames();
        static::getContainer()->get(CategoryAdminService::class)->update($category->getId()->toRfc4122(), new CategoryWriteRequest(
            $category->getSlug(), $names['cs'], $names['de'], $names['en'], $category->getParent()?->getId()->toRfc4122(), $category->getPosition(), $category->isActive(), $attributes,
        ));
    }

    /** @return list<string> */
    private function categoryKeys(string $slug): array
    {
        return array_column(json_decode(static::getContainer()->get(Connection::class)->fetchOne('SELECT attributes FROM category WHERE slug = :slug', ['slug' => $slug]), true), 'key');
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
