<?php

declare(strict_types=1);

namespace App\Admin;

use App\Checkout\CheckoutService;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\ShopOrder;
use App\Entity\StockMovement;
use Doctrine\DBAL\Connection;
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
            WHERE v.active = TRUE AND v.stock <= 5 ORDER BY v.stock ASC, v.sku ASC LIMIT 8");

        return [
            'orders' => (int) $stats['orders'],
            'openOrders' => (int) $stats['open_orders'],
            'revenueEurMinor' => (int) $this->db->fetchOne("SELECT COALESCE(SUM(total_minor), 0) FROM shop_order WHERE currency = 'EUR' AND status != 'cancelled'"),
            'products' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM product'),
            'lowStock' => array_map(static fn (array $row): array => ['id' => $row['id'], 'sku' => $row['sku'], 'stock' => (int) $row['stock'], 'product' => $row['product']], $lowStock),
            'recentOrders' => array_slice($this->orders(), 0, 5),
        ];
    }

    public function products(): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT p.*, p.copy -> 'en' ->> 'name' AS name FROM product p ORDER BY p.featured_rank, p.slug");

        return array_map(function (array $row): array {
            $variants = $this->db->fetchAllAssociative('SELECT id, sku, label, price_czk, price_eur, stock, active, color, size FROM product_variant WHERE product_id = :id ORDER BY sku', ['id' => $row['id']]);

            return [
                'id' => $row['id'], 'slug' => $row['slug'], 'category' => $row['category'],
                'status' => $row['status'], 'name' => $row['name'], 'copy' => json_decode($row['copy'], true, flags: JSON_THROW_ON_ERROR),
                'image' => $row['image'], 'badge' => $row['badge'], 'featuredRank' => (int) $row['featured_rank'],
                'variants' => array_map(static fn (array $v): array => [
                    'id' => $v['id'], 'sku' => $v['sku'], 'label' => json_decode($v['label'], true, flags: JSON_THROW_ON_ERROR),
                    'priceCzk' => (int) $v['price_czk'], 'priceEur' => (int) $v['price_eur'], 'stock' => (int) $v['stock'],
                    'active' => (bool) $v['active'], 'color' => $v['color'], 'size' => $v['size'],
                ], $variants),
            ];
        }, $rows);
    }

    public function createProduct(ProductWriteRequest $input): array
    {
        $this->validateSlug($input->slug);
        $copy = $this->copy($input);
        $product = new Product($input->slug, $input->category, $copy, $input->image);
        $product->update($input->slug, $input->category, $copy, $input->image, [$input->image], $input->badge, $input->featuredRank, $input->status);
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
        $copy = $this->copy($input);
        $product->update($input->slug, $input->category, $copy, $input->image, [$input->image], $input->badge, $input->featuredRank, $input->status);
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
        $this->em->flush();

        return ['id' => $id, 'sku' => $variant->getSku()];
    }

    public function orders(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, reference, status, customer_name, email, country, currency, total_minor, created_at FROM shop_order ORDER BY created_at DESC, id DESC LIMIT 100');

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

    private function copy(ProductWriteRequest $input): array
    {
        $copy = [];
        foreach (['cs' => ['nameCs', 'shortCs'], 'de' => ['nameDe', 'shortDe'], 'en' => ['nameEn', 'shortEn']] as $locale => [$name, $short]) {
            $copy[$locale] = ['name' => trim($input->$name), 'short' => trim($input->$short), 'description' => trim($input->$short), 'details' => []];
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

    private function variantLabels(VariantWriteRequest $input): array
    {
        return ['cs' => trim($input->labelCs), 'de' => trim($input->labelDe), 'en' => trim($input->labelEn)];
    }
}
