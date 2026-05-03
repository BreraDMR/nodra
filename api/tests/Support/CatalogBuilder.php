<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\AttributeSchema;
use App\Entity\Category;
use App\Entity\PricingRule;
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

    /** Offer with the fields pricing reads; matched when a variant is given, a product-level snapshot otherwise. */
    public function pricedOffer(
        Product $product,
        ?ProductVariant $variant,
        int $priceMinor,
        string $currency = 'CZK',
        ?int $fxRateCzk = null,
        int $inboundShippingMinor = 0,
        ?int $leadTimeMinDays = 2,
        ?int $leadTimeMaxDays = 5,
        ?int $reportedQuantity = 4,
        ?\DateTimeImmutable $checkedAt = null,
        ?string $status = null,
    ): SupplierOffer {
        $url = 'https://supplier.example/'.$product->getSlug().'/'.bin2hex(random_bytes(4));
        $checkedAt ??= new \DateTimeImmutable('-1 day');
        $offer = new SupplierOffer($product, 'bike24', $url, 'Listing '.$product->getSlug(), $currency, $priceMinor, $reportedQuantity, $checkedAt);
        $offer->update('bike24', $url, 'Listing '.$product->getSlug(), 'Secret Seller GmbH', $currency, $priceMinor, $reportedQuantity, $checkedAt,
            $leadTimeMinDays, $leadTimeMaxDays, $variant, $status ?? ($variant === null ? 'snapshot' : 'matched'));
        $offer->setCost($inboundShippingMinor, $fxRateCzk, $fxRateCzk === null ? null : new \DateTimeImmutable('2026-09-25'));
        $this->em->persist($offer);
        $this->em->flush();

        return $offer;
    }

    public function rule(?Category $category, int $minCostCzkMinor, ?int $maxCostCzkMinor, int $markupBp, bool $active = true): PricingRule
    {
        $rule = new PricingRule($category, $minCostCzkMinor, $maxCostCzkMinor, $markupBp, $active);
        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }
}
