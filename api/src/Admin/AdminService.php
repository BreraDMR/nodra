<?php

declare(strict_types=1);

namespace App\Admin;

use App\Catalog\AttributeSchema;
use App\Catalog\CategoryIndex;
use App\Catalog\Gtin;
use App\Checkout\CheckoutService;
use App\Entity\Category;
use App\Entity\OrderItem;
use App\Entity\LoyaltyEntry;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\ShopOrder;
use App\Entity\StockMovement;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AdminService
{
    public function __construct(private EntityManagerInterface $em, private Connection $db, private CheckoutService $checkout) {}

    public function dashboard(): array
    {
        $stats = $this->db->fetchAssociative("SELECT COUNT(*) AS orders, COALESCE(SUM(total_minor), 0) AS revenue,
            COUNT(*) FILTER (WHERE status IN ('placed', 'processing')) AS open_orders FROM shop_order");
        $lowStock = $this->db->fetchAllAssociative("SELECT v.id, v.sku, v.stock, p.copy -> 'en' ->> 'name' AS product
            FROM product_variant v JOIN product p ON p.id = v.product_id
            WHERE p.status = 'published' AND v.active = TRUE AND v.stock <= 5 ORDER BY v.stock ASC, v.sku ASC LIMIT 8");

        return [
            'orders' => (int) $stats['orders'],
            'openOrders' => (int) $stats['open_orders'],
            'revenueEurMinor' => (int) $this->db->fetchOne("SELECT COALESCE(SUM(total_minor), 0) FROM shop_order WHERE currency = 'EUR' AND status != 'cancelled'"),
            'products' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM product WHERE status = 'published'"),
            'lowStock' => array_map(static fn (array $row): array => ['id' => $row['id'], 'sku' => $row['sku'], 'stock' => (int) $row['stock'], 'product' => $row['product']], $lowStock),
            'recentOrders' => $this->orderRows(5, 0),
        ];
    }

    public function products(AdminProductsQuery $query): array
    {
        $params = [];
        $where = '';
        if ($query->q !== null && trim($query->q) !== '') {
            $params['search'] = '%'.strtr(trim($query->q), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%';
            $params['exact'] = preg_replace('/[\s-]/', '', trim($query->q));
            $where = " WHERE p.slug ILIKE :search OR p.brand ILIKE :search OR p.copy -> 'cs' ->> 'name' ILIKE :search OR p.copy -> 'de' ->> 'name' ILIKE :search OR p.copy -> 'en' ->> 'name' ILIKE :search
                OR EXISTS (SELECT 1 FROM product_variant sv WHERE sv.product_id = p.id AND (sv.mpn ILIKE :search OR sv.ean = :exact))";
        }
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM product p$where", $params);
        $pages = max(1, (int) ceil($total / 24));
        $page = min($query->page, $pages);
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.*, p.copy -> 'en' ->> 'name' AS name, c.slug AS category_slug FROM product p JOIN category c ON c.id = p.category_id$where ORDER BY p.featured_rank, p.slug LIMIT :limit OFFSET :offset",
            $params + ['limit' => 24, 'offset' => ($page - 1) * 24],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
        $variantsByProduct = [];
        $offersByProduct = [];
        if ($rows !== []) {
            $variants = $this->db->fetchAllAssociative(
                'SELECT product_id, id, sku, label, price_czk, price_eur, stock, active, color, size, mpn, ean, attributes FROM product_variant WHERE product_id IN (:ids) ORDER BY sku',
                ['ids' => array_column($rows, 'id')],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($variants as $variant) {
                $variantsByProduct[$variant['product_id']][] = $variant;
            }
            $offers = $this->db->fetchAllAssociative(
                'SELECT id, product_id, variant_id, supplier, seller, url, title, currency, price_minor, reported_quantity, checked_at, lead_time_min_days, lead_time_max_days, verification_status FROM supplier_offer WHERE product_id IN (:ids) ORDER BY checked_at DESC, id',
                ['ids' => array_column($rows, 'id')],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($offers as $offer) {
                $offersByProduct[$offer['product_id']][] = [
                    'id' => $offer['id'], 'variantId' => $offer['variant_id'], 'seller' => $offer['seller'],
                    'leadTimeMinDays' => $offer['lead_time_min_days'] === null ? null : (int) $offer['lead_time_min_days'],
                    'leadTimeMaxDays' => $offer['lead_time_max_days'] === null ? null : (int) $offer['lead_time_max_days'],
                    'supplier' => $offer['supplier'], 'url' => $offer['url'], 'title' => $offer['title'],
                    'currency' => $offer['currency'], 'priceMinor' => (int) $offer['price_minor'],
                    'reportedQuantity' => $offer['reported_quantity'] === null ? null : (int) $offer['reported_quantity'],
                    'checkedAt' => $offer['checked_at'], 'verificationStatus' => $offer['verification_status'],
                ];
            }
        }

        $items = array_map(function (array $row) use ($variantsByProduct, $offersByProduct): array {
            $variants = $variantsByProduct[$row['id']] ?? [];

            return [
                'id' => $row['id'], 'slug' => $row['slug'], 'category' => $row['category_slug'], 'categoryId' => $row['category_id'],
                'brand' => $row['brand'], 'attributes' => json_decode($row['attributes'], true, flags: JSON_THROW_ON_ERROR),
                'status' => $row['status'], 'name' => $row['name'], 'copy' => json_decode($row['copy'], true, flags: JSON_THROW_ON_ERROR),
                'image' => $row['image'], 'badge' => $row['badge'], 'featuredRank' => (int) $row['featured_rank'],
                'supplierOffers' => $offersByProduct[$row['id']] ?? [],
                'variants' => array_map(static fn (array $v): array => [
                    'id' => $v['id'], 'sku' => $v['sku'], 'label' => json_decode($v['label'], true, flags: JSON_THROW_ON_ERROR),
                    'priceCzk' => (int) $v['price_czk'], 'priceEur' => (int) $v['price_eur'], 'stock' => (int) $v['stock'],
                    'active' => (bool) $v['active'], 'color' => $v['color'], 'size' => $v['size'],
                    'mpn' => $v['mpn'], 'ean' => $v['ean'], 'attributes' => json_decode($v['attributes'], true, flags: JSON_THROW_ON_ERROR),
                ], $variants),
            ];
        }, $rows);

        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function createProduct(ProductWriteRequest $input): array
    {
        $this->validateSlug($input->slug);
        $category = $this->category($input->category);
        $copy = $this->copy($input);
        $product = new Product($input->slug, $category, $copy, $input->image);
        $product->update($input->slug, $category, $copy, $input->image, [$input->image], $input->badge, $input->featuredRank, $input->status);
        $product->describe($this->brand($input->brand), $this->productAttributes($input->attributes, $category));
        $variant = new ProductVariant($product, 'ND-'.strtoupper(bin2hex(random_bytes(4))), ['cs' => 'Standardní', 'de' => 'Standard', 'en' => 'Standard'], $input->priceCzk, $input->priceEur, 0);
        $this->em->persist($product);
        $this->em->persist($variant);
        $this->em->flush();

        return ['id' => $product->getId()->toRfc4122(), 'variantId' => $variant->getId()->toRfc4122()];
    }

    public function updateProduct(string $id, ProductWriteRequest $input): ?array
    {
        $product = $this->em->find(Product::class, Uuid::fromString($id));
        if ($product === null) {
            return null;
        }
        $this->validateSlug($input->slug, $id);
        $category = $this->category($input->category, $product->getCategory());
        $copy = $this->copy($input, $product->getCopy());
        $images = array_values(array_unique([$input->image, ...$product->getImages()]));
        $product->update($input->slug, $category, $copy, $input->image, $images, $input->badge, $input->featuredRank, $input->status);
        $product->describe($this->brand($input->brand), $this->productAttributes($input->attributes, $category));
        $baseId = $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = :id ORDER BY (active AND stock > 0) DESC, active DESC, sku ASC LIMIT 1', ['id' => $id]);
        $variant = $baseId === false ? null : $this->em->find(ProductVariant::class, Uuid::fromString($baseId));
        if ($variant !== null) {
            $variant->update($variant->getLabel(), $input->priceCzk, $input->priceEur, $variant->isActive(), $variant->getColor(), $variant->getSize());
        }
        $this->em->flush();

        return ['id' => $product->getId()->toRfc4122()];
    }

    public function adjustStock(StockAdjustmentRequest $input): array
    {
        return $this->db->transactional(function () use ($input): array {
            $affected = $this->db->executeStatement('UPDATE product_variant SET stock = stock + :delta WHERE id = :id AND stock + :delta >= 0', ['id' => $input->variantId, 'delta' => $input->delta]);
            if ($affected !== 1) {
                throw new \DomainException('Variant not found or stock would become negative');
            }
            $variant = $this->em->find(ProductVariant::class, Uuid::fromString($input->variantId));
            $this->em->persist(new StockMovement($variant, $input->delta, trim($input->reason)));
            $this->em->flush();

            return ['variantId' => $input->variantId, 'stock' => (int) $this->db->fetchOne('SELECT stock FROM product_variant WHERE id = :id', ['id' => $input->variantId])];
        });
    }

    public function createVariant(string $productId, VariantWriteRequest $input): ?array
    {
        $product = $this->em->find(Product::class, Uuid::fromString($productId));
        if ($product === null) {
            return null;
        }
        $this->validateSku($input->sku);
        $variant = new ProductVariant($product, trim($input->sku), $this->variantLabels($input), $input->priceCzk, $input->priceEur, 0, $input->color, $input->size);
        $variant->update($this->variantLabels($input), $input->priceCzk, $input->priceEur, $input->active, $input->color, $input->size);
        $this->identify($variant, $input);
        $this->em->persist($variant);
        $this->em->flush();

        return ['id' => $variant->getId()->toRfc4122(), 'sku' => $variant->getSku()];
    }

    public function updateVariant(string $id, VariantWriteRequest $input): ?array
    {
        $variant = $this->em->find(ProductVariant::class, Uuid::fromString($id));
        if ($variant === null) {
            return null;
        }
        if ($variant->getSku() !== $input->sku) {
            throw new \InvalidArgumentException('SKU cannot be changed after creation');
        }
        $variant->update($this->variantLabels($input), $input->priceCzk, $input->priceEur, $input->active, $input->color, $input->size);
        $this->identify($variant, $input);
        $this->em->flush();

        return ['id' => $id, 'sku' => $variant->getSku()];
    }

    public function orders(AdminOrdersQuery $query): array
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM shop_order');
        $pages = max(1, (int) ceil($total / 30));
        $page = min($query->page, $pages);

        return [
            'items' => $this->orderRows(30, ($page - 1) * 30),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    private function orderRows(int $limit, int $offset): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, reference, status, customer_name, email, country, currency, total_minor, created_at FROM shop_order ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'id' => $row['id'], 'reference' => $row['reference'], 'status' => $row['status'],
            'customerName' => $row['customer_name'], 'email' => $row['email'], 'country' => $row['country'],
            'total' => ['amount' => (int) $row['total_minor'], 'currency' => $row['currency']], 'createdAt' => $row['created_at'],
        ], $rows);
    }

    public function order(string $id): ?array
    {
        $order = $this->em->find(ShopOrder::class, Uuid::fromString($id));
        if ($order === null) {
            return null;
        }

        return $this->checkout->receipt($order) + [
            'id' => $id,
            'customer' => ['name' => $order->getCustomerName(), 'email' => $order->getEmail(), 'country' => $order->getCountry(), 'address' => $order->getAddress(), 'postalCode' => $order->getPostalCode(), 'district' => $order->getDistrict()],
        ];
    }

    public function advanceOrder(string $id, string $status): ?array
    {
        return $this->db->transactional(function () use ($id, $status): ?array {
            $this->db->fetchOne('SELECT id FROM shop_order WHERE id = :id FOR UPDATE', ['id' => $id]);
            $order = $this->em->find(ShopOrder::class, Uuid::fromString($id));
            if ($order === null) {
                return null;
            }
            $order->advanceTo($status);
            if ($status === 'completed' && $order->getAccount() !== null) {
                $step = $order->getCurrency() === 'CZK' ? 10000 : 400;
                $points = max(1, intdiv($order->getSubtotalMinor(), $step));
                $this->em->persist(new LoyaltyEntry($order->getAccount(), $order, $points));
            }
            if ($status === 'cancelled') {
                foreach ($this->em->getRepository(OrderItem::class)->findBy(['order' => $order]) as $item) {
                    $variant = $item->getVariant();
                    if ($variant === null) {
                        continue;
                    }
                    $this->db->executeStatement('UPDATE product_variant SET stock = stock + :quantity WHERE id = :id', ['quantity' => $item->getQuantity(), 'id' => $variant->getId()->toRfc4122()]);
                    $this->em->persist(new StockMovement($variant, $item->getQuantity(), 'Order '.$order->getReference().' cancelled'));
                }
            }
            $this->em->flush();

            return ['id' => $id, 'status' => $order->getStatus()];
        });
    }

    private function copy(ProductWriteRequest $input, array $existing = []): array
    {
        $copy = [];
        foreach (['cs' => ['nameCs', 'shortCs', 'inBoxCs'], 'de' => ['nameDe', 'shortDe', 'inBoxDe'], 'en' => ['nameEn', 'shortEn', 'inBoxEn']] as $locale => [$name, $short, $inBox]) {
            $copy[$locale] = ['name' => trim($input->$name), 'short' => trim($input->$short), 'description' => $existing[$locale]['description'] ?? trim($input->$short), 'details' => $existing[$locale]['details'] ?? []];
            $contents = trim((string) $input->$inBox);
            if ($contents !== '') {
                $copy[$locale]['inBox'] = $contents;
            }
        }

        return $copy;
    }

    private function validateSlug(string $slug, ?string $exceptId = null): void
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException('Slug must contain lowercase letters, numbers and hyphens');
        }
        $existing = $this->db->fetchOne('SELECT id FROM product WHERE slug = :slug', ['slug' => $slug]);
        if ($existing !== false && $existing !== $exceptId) {
            throw new \DomainException('A product with this slug already exists');
        }
    }

    private function validateSku(string $sku): void
    {
        if (!preg_match('/^[A-Z0-9-]+$/', $sku)) {
            throw new \InvalidArgumentException('SKU must contain uppercase letters, numbers and hyphens');
        }
        if ($this->db->fetchOne('SELECT id FROM product_variant WHERE sku = :sku', ['sku' => $sku]) !== false) {
            throw new \DomainException('A variant with this SKU already exists');
        }
    }

    /** Active category by slug; a product may keep its current category even after it was deactivated. */
    private function category(string $slug, ?Category $current = null): Category
    {
        $category = $this->em->getRepository(Category::class)->findOneBy(['slug' => $slug]);
        if ($category === null) {
            throw new \InvalidArgumentException('Unknown category');
        }
        if ($category !== $current && !CategoryIndex::load($this->db)->isVisible($category->getId()->toRfc4122())) {
            throw new \InvalidArgumentException('Choose an active category');
        }

        return $category;
    }

    private function brand(?string $brand): ?string
    {
        $brand = trim((string) $brand);

        return $brand === '' ? null : $brand;
    }

    private function productAttributes(array $values, Category $category): array
    {
        return AttributeSchema::normalizeValues($values, CategoryIndex::load($this->db)->effectiveAttributes($category->getId()->toRfc4122()));
    }

    private function identify(ProductVariant $variant, VariantWriteRequest $input): void
    {
        $ean = trim((string) $input->ean) === '' ? null : Gtin::normalize((string) $input->ean);
        if ($ean !== null) {
            $owner = $this->db->fetchOne('SELECT id FROM product_variant WHERE ean = :ean', ['ean' => $ean]);
            if ($owner !== false && $owner !== $variant->getId()->toRfc4122()) {
                throw new \DomainException('Another variant already has this EAN');
            }
        }
        $mpn = trim((string) $input->mpn);
        $categoryId = $variant->getProduct()->getCategory()->getId()->toRfc4122();
        $attributes = AttributeSchema::normalizeValues($input->attributes, CategoryIndex::load($this->db)->effectiveAttributes($categoryId));
        $variant->identify($mpn === '' ? null : $mpn, $ean, $attributes);
    }

    private function variantLabels(VariantWriteRequest $input): array
    {
        return ['cs' => trim($input->labelCs), 'de' => trim($input->labelDe), 'en' => trim($input->labelEn)];
    }
}
