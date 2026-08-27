#!/usr/bin/env php
<?php

declare(strict_types=1);

// Fill a THROWAWAY COPY of the database with synthetic catalog cards for the local
// performance measurement (D09.5, see docs/perf-local.md). Everything the script
// creates is prefixed perf- / PERF- and is deleted first, so a re-run is clean.
// The seed files and the stand database are never touched.
//
// Usage:
//   DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:55432/app_perf?serverVersion=16&charset=utf8" \
//   php scripts/perf-populate.php [products]
//
// Guard: the database in DATABASE_URL must equal PERF_DB (default app_perf) and must
// not be the stand or the test database. The live connection is checked once more.

require __DIR__.'/../api/vendor/autoload.php';

use App\Catalog\CategoryIndex;
use App\Entity\Category;
use App\Entity\PriceChange;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use App\Kernel;

$url = getenv('DATABASE_URL') ?: '';
$database = ltrim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
$allowed = getenv('PERF_DB') ?: 'app_perf';
if ($database === '' || $database !== $allowed || in_array($database, ['app', 'app_test'], true)) {
    fwrite(STDERR, "Refusing: DATABASE_URL must point at the throwaway copy \$$allowed (got '$database').\n");
    exit(1);
}

$count = max(1, (int) ($argv[1] ?? 1050));
$kernel = new Kernel('dev', false);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$live = (string) $em->getConnection()->fetchOne('SELECT current_database()');
if ($live !== $database) {
    fwrite(STDERR, "Refusing: the live connection points at '$live', expected '$database'.\n");
    exit(1);
}

// a previous run is rolled back first, children go over foreign keys
$gone = (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM product WHERE slug LIKE 'perf-%'");
$em->getConnection()->executeStatement(
    "DELETE FROM price_change WHERE variant_id IN (SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.slug LIKE 'perf-%')"
);
$em->getConnection()->executeStatement("DELETE FROM product WHERE slug LIKE 'perf-%'");

$index = CategoryIndex::load($em->getConnection());
// spread over the leaf component categories and the big roots, so a subtree browse and
// the facet aggregation have real volume; only visible categories can hold published cards
$hasChildren = [];
foreach ($em->getRepository(Category::class)->findAll() as $entity) {
    if ($entity->getParent() !== null) {
        $hasChildren[$entity->getParent()->getId()->toRfc4122()] = true;
    }
}
$categories = array_values(array_filter(
    $em->getRepository(Category::class)->findAll(),
    fn (Category $entity): bool => !isset($hasChildren[$entity->getId()->toRfc4122()]) && $index->isVisible($entity->getId()->toRfc4122()),
));
if ($categories === []) {
    fwrite(STDERR, "No visible leaf category found.\n");
    exit(1);
}

$brands = ['Synthia', 'Perfline', 'Testride', 'Mockup', 'Fictional', 'Nomark'];
$leadTimes = [[2, 4], [3, 6], [5, 9]];
$now = new \DateTimeImmutable();

echo "Removing $gone old synthetic cards, creating $count new ones...\n";
$added = 0;
for ($i = 0; $i < $count; ++$i) {
    /** @var Category $category */
    $category = $categories[$i % count($categories)];
    $definitions = $index->effectiveAttributes($category->getId()->toRfc4122());
    $attributes = [];
    foreach ($definitions as $position => $definition) {
        // facet values have to repeat across cards, otherwise every value is a bucket of one
        $attributes[$definition['key']] = match ($definition['type']) {
            'choice' => $definition['options'][($i + $position) % count($definition['options'])]['value'],
            'number' => (string) [25, 28, 32, 40, 50][($i + $position) % 5],
            default => 'synthetic',
        };
    }
    $copy = [];
    foreach (['cs', 'de', 'en'] as $locale) {
        $name = $category->getNames()[$locale].' Perf '.$i;
        $copy[$locale] = ['name' => $name, 'short' => 'Syntetická karta '.$i, 'description' => 'Synthetic card '.$i.' for the local performance run.', 'details' => ['Synthetic detail '.$i]];
    }
    $product = new Product('perf-'.$i.'-p', $category, $copy, '/images/perf-synth.png');
    $product->update('perf-'.$i.'-p', $category, $copy, '/images/perf-synth.png', ['/images/perf-synth.png'], null, 500 + $i, 'published');
    $product->describe($brands[$i % count($brands)], $attributes);
    $em->persist($product);

    $variantCount = 1 + ($i % 3);
    for ($v = 0; $v < $variantCount; ++$v) {
        $letter = chr(65 + $v);
        $priceCzk = 15000 + (($i * 137 + $v * 911) % 90000);
        $variant = new ProductVariant(
            $product,
            sprintf('PERF-%06d-%d', $i, $v + 1),
            ['cs' => 'Verze '.$letter, 'de' => 'Version '.$letter, 'en' => 'Version '.$letter],
            $priceCzk,
            intdiv($priceCzk, 25),
            $i % 7 === 0 ? 3 : 0,
            $v === 0 && $i % 2 === 0 ? 'černá' : null,
            $v === 1 ? ['S', 'M', 'L'][$i % 3] : null,
        );
        // a unique but valid EAN, so the search-by-code path has something to find
        $ean = ean13('489'.sprintf('%09d', $i * 10 + $v));
        // the variant overrides the first defined attribute, so the facet merge p||v has real work
        $variantAttributes = [];
        foreach (array_slice($definitions, 0, 1) as $definition) {
            $options = $definition['options'] ?? [];
            $variantAttributes[$definition['key']] = $options === []
                ? (string) [25, 28, 32, 40, 50][($i + $v) % 5]
                : $options[($i + $v) % count($options)]['value'];
        }
        $variant->identify(sprintf('PFM-%06d-%d', $i, $v + 1), $ean, $variantAttributes);
        $em->persist($variant);
        $em->persist(new PriceChange($variant, null, null, PriceChange::IMPORT, 'perf-populate', $now));

        if (($i + $v) % 2 === 0) {
            [$leadMin, $leadMax] = $leadTimes[($i + $v) % count($leadTimes)];
            $url = sprintf('https://demo.nodra.test/perf/%06d/%d', $i, $v + 1);
            $title = 'Perf offer '.$i.' version '.$letter;
            $offer = new SupplierOffer($product, 'demo', $url, $title, 'CZK', ($priceCzk - 500) * 100, 5, $now);
            $offer->update('demo', $url, $title, null, 'CZK', ($priceCzk - 500) * 100, 5, $now, $leadMin, $leadMax, $variant, 'matched');
            $em->persist($offer);
        }
    }
    ++$added;
    if ($added % 50 === 0) {
        // flush only, no clear(): the category entities must stay managed for the next cards
        $em->flush();
        echo "  $added cards...\n";
    }
}
$em->flush();

foreach (['product', 'product_variant', 'supplier_offer'] as $table) {
    $total = (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM $table");
    $synthetic = (int) $em->getConnection()->fetchOne(
        $table === 'product'
            ? "SELECT COUNT(*) FROM product WHERE slug LIKE 'perf-%'"
            : ($table === 'product_variant'
                ? "SELECT COUNT(*) FROM product_variant WHERE sku LIKE 'PERF-%'"
                : "SELECT COUNT(*) FROM supplier_offer WHERE url LIKE 'https://demo.nodra.test/perf/%'")
    );
    echo "$table: $synthetic synthetic of $total total\n";
}

/** EAN-13 with a correct check digit over a 12-digit base. */
function ean13(string $base12): string
{
    $sum = 0;
    foreach (str_split($base12) as $position => $digit) {
        $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
    }

    return $base12.strval((10 - $sum % 10) % 10);
}
