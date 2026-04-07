<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\AttributeSchema;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Small catalogue factory for database tests. Everything is flushed right away,
 * so HTTP requests made afterwards see the rows (the test transaction is rolled back later).
 */
final class CatalogBuilder
{
    public function __construct(private EntityManagerInterface $em) {}

    /** Attribute definition in the seed file shape. */
    public static function attribute(string $key, string $type, bool $filterable = true, array $options = [], ?string $unit = null, ?array $labels = null): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'unit' => $unit,
            'labels' => $labels ?? ['cs' => $key.' cs', 'de' => $key.' de', 'en' => $key.' en'],
            'filterable' => $filterable,
            'options' => $options,
        ];
    }

    public function category(string $slug, ?Category $parent = null, array $attributes = [], bool $active = true, ?array $names = null): Category
    {
        $names ??= ['cs' => $slug.' (cs)', 'de' => $slug.' (de)', 'en' => $slug.' (en)'];
        $category = new Category($slug, $names, $parent);
        $category->update($slug, $names, $parent, 0, $active, AttributeSchema::normalizeDefinitions($attributes));
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /**
     * @param array<string, string> $attributes stored as given, so pass normalised values
     * @param array<string, mixed> $copy per-locale overrides merged into the default copy
     */
    public function product(string $slug, Category $category, ?string $brand = null, array $attributes = [], string $status = 'published', array $copy = [], ?array $images = null): Product
    {
        $fullCopy = [];
        foreach (['cs', 'de', 'en'] as $locale) {
            $fullCopy[$locale] = ($copy[$locale] ?? []) + [
                'name' => $slug.' '.$locale,
                'short' => 'Short '.$locale,
                'description' => 'Description '.$locale,
                'details' => [],
            ];
        }
        $image = '/images/'.$slug.'.png';
        $product = new Product($slug, $category, $fullCopy, $image);
        $product->update($slug, $category, $fullCopy, $image, $images ?? [$image], null, 100, $status);
        $product->describe($brand, $attributes);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    public function variant(Product $product, string $sku, array $attributes = [], bool $active = true, ?string $mpn = null, ?string $ean = null, int $stock = 3, int $priceCzk = 100000): ProductVariant
    {
        $labels = ['cs' => $sku.' cs', 'de' => $sku.' de', 'en' => $sku.' en'];
        $variant = new ProductVariant($product, $sku, $labels, $priceCzk, intdiv($priceCzk, 25), $stock);
        $variant->update($labels, $priceCzk, intdiv($priceCzk, 25), $active, null, null);
        $variant->identify($mpn, $ean, $attributes);
        $this->em->persist($variant);
        $this->em->flush();

        return $variant;
    }

    /** Product with one plain active variant, the usual shape for list tests. */
    public function sellable(string $slug, Category $category, ?string $brand = null, array $attributes = [], string $status = 'published'): Product
    {
        $product = $this->product($slug, $category, $brand, $attributes, $status);
        $this->variant($product, strtoupper($slug).'-1');

        return $product;
    }

    public function offer(Product $product, string $url, ?string $seller = null, ?ProductVariant $variant = null): SupplierOffer
    {
        $offer = new SupplierOffer($product, 'allegro_cz', $url, 'Listing '.$product->getSlug(), 'CZK', 99900, 4, new \DateTimeImmutable('-1 day'));
        $offer->update('allegro_cz', $url, 'Listing '.$product->getSlug(), $seller, 'CZK', 99900, 4, new \DateTimeImmutable('-1 day'), 2, 5, $variant, $variant === null ? 'snapshot' : 'matched');
        $this->em->persist($offer);
        $this->em->flush();

        return $offer;
    }
}
