<?php

declare(strict_types=1);

namespace App\Admin;

use App\Catalog\AttributeSchema;
use App\Catalog\CategoryIndex;
use App\Catalog\Gtin;
use App\Entity\Category;
use App\Entity\ImportFieldOrigin;
use App\Entity\PriceChange;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StockMovement;
use App\Order\OrderQueues;
use App\Pricing\PriceHistory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class AdminService
{
    public function __construct(private EntityManagerInterface $em, private Connection $db, private PriceHistory $history, private ClockInterface $clock, private \App\Import\OriginRecorder $origins) {}

    public function dashboard(): array
    {
        $stats = $this->db->fetchAssociative("SELECT COUNT(*) AS orders, COUNT(*) FILTER (WHERE status IN ('requested', 'confirmed')) AS open_orders FROM shop_order");
        // goods NODRA holds itself, whatever the card's state: they are money on the shelf
        $ownStock = $this->db->fetchAllAssociative("SELECT v.id, v.sku, v.stock, p.copy -> 'en' ->> 'name' AS product
            FROM product_variant v JOIN product p ON p.id = v.product_id
            WHERE v.stock > 0 ORDER BY v.stock ASC, v.sku ASC LIMIT 20");

        return [
            'orders' => (int) $stats['orders'],
            'openOrders' => (int) $stats['open_orders'],
            'revenueCzkMinor' => (int) $this->db->fetchOne("SELECT COALESCE(SUM(total_minor), 0) FROM shop_order WHERE currency = 'CZK' AND status != 'cancelled'"),
            // older demo orders only, new orders are always CZK
            'revenueEurMinor' => (int) $this->db->fetchOne("SELECT COALESCE(SUM(total_minor), 0) FROM shop_order WHERE currency = 'EUR' AND status != 'cancelled'"),
            'products' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM product WHERE status = 'published'"),
            'ownStock' => array_map(static fn (array $row): array => ['id' => $row['id'], 'sku' => $row['sku'], 'stock' => (int) $row['stock'], 'product' => $row['product']], $ownStock),
            'ownStockVariants' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_variant WHERE stock > 0'),
            'queues' => $this->queues(),
            'recentOrders' => $this->orderRows(5, 0),
            'importProblems' => $this->importProblems(),
            'claims' => $this->claims(),
        ];
    }

    /** After-sale claims the admin still has to act on; the Claims screen shows the details (D08.4). */
    private function claims(): array
    {
        $today = $this->clock->now()->setTimezone(new \DateTimeZone(OrderQueues::TIMEZONE))->format('Y-m-d');

        return [
            'open' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM return_claim WHERE status IN ('open', 'waiting', 'accepted')"),
            'overdue' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM return_claim WHERE status = 'accepted' AND due_at < :today", ['today' => $today]),
        ];
    }

    /**
     * Import runs of the last 30 days the admin should look at: the whole run failed, or it finished
     * with row errors. The Import screen journal (D03.4) shows the details; this only counts them.
     */
    private function importProblems(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, source, status, jsonb_array_length(errors) AS error_count, finished_at
            FROM import_run
            WHERE started_at > NOW() - INTERVAL '30 days' AND (status = 'failed' OR errors <> '[]'::jsonb)
            ORDER BY started_at DESC, id DESC",
        );
        $last = $rows[0] ?? null;
        $finished = $last['finished_at'] ?? null;

        return [
            'runs' => count($rows),
            'last' => $last === null ? null : [
                'id' => $last['id'], 'source' => $last['source'], 'status' => $last['status'],
                'errorCount' => (int) $last['error_count'],
                'finishedAt' => $finished === null ? null : (is_string($finished) ? new \DateTimeImmutable($finished) : $finished)->format(\DATE_ATOM),
            ],
        ];
    }

    /** @return array<string, int> orders in each work queue */
    public function queues(): array
    {
        $counts = $this->db->fetchAssociative(OrderQueueSql::countsQuery(), ['today' => OrderQueueSql::today($this->clock->now())]);

        return array_map('intval', $counts);
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
                'SELECT product_id, id, sku, label, price_czk, price_eur, stock, active, color, size, mpn, ean, attributes,
                    rrp_minor, rrp_currency, rrp_source, rrp_checked_at, market_price_minor, market_price_source, market_checked_at
                FROM product_variant WHERE product_id IN (:ids) ORDER BY sku',
                ['ids' => array_column($rows, 'id')],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($variants as $variant) {
                $variantsByProduct[$variant['product_id']][] = $variant;
            }
            $offers = $this->db->fetchAllAssociative(
                'SELECT id, product_id, variant_id, supplier, seller, url, title, currency, price_minor, inbound_shipping_minor, fx_rate_czk, fx_rate_date,
                    reported_quantity, checked_at, lead_time_min_days, lead_time_max_days, verification_status
                FROM supplier_offer WHERE product_id IN (:ids) ORDER BY checked_at DESC, id',
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
                    'inboundShippingMinor' => (int) $offer['inbound_shipping_minor'],
                    'fxRateCzk' => $offer['fx_rate_czk'] === null ? null : (int) $offer['fx_rate_czk'], 'fxRateDate' => $offer['fx_rate_date'],
                    'reportedQuantity' => $offer['reported_quantity'] === null ? null : (int) $offer['reported_quantity'],
                    'checkedAt' => (new \DateTimeImmutable($offer['checked_at']))->format(\DATE_ATOM), 'verificationStatus' => $offer['verification_status'],
                ];
            }
        }

        $items = array_map(function (array $row) use ($variantsByProduct, $offersByProduct): array {
            $variants = $variantsByProduct[$row['id']] ?? [];

            return [
                'id' => $row['id'], 'slug' => $row['slug'], 'category' => $row['category_slug'], 'categoryId' => $row['category_id'],
                'brand' => $row['brand'], 'attributes' => self::attributeMap($row['attributes']),
                'status' => $row['status'], 'name' => $row['name'], 'copy' => json_decode($row['copy'], true, flags: JSON_THROW_ON_ERROR),
                'image' => $row['image'], 'badge' => $row['badge'], 'featuredRank' => (int) $row['featured_rank'],
                'supplierOffers' => $offersByProduct[$row['id']] ?? [],
                'variants' => array_map(static fn (array $v): array => [
                    'id' => $v['id'], 'sku' => $v['sku'], 'label' => json_decode($v['label'], true, flags: JSON_THROW_ON_ERROR),
                    'priceCzk' => (int) $v['price_czk'], 'priceEur' => (int) $v['price_eur'], 'stock' => (int) $v['stock'],
                    'active' => (bool) $v['active'], 'color' => $v['color'], 'size' => $v['size'],
                    'mpn' => $v['mpn'], 'ean' => $v['ean'], 'attributes' => self::attributeMap($v['attributes']),
                    'rrpMinor' => $v['rrp_minor'] === null ? null : (int) $v['rrp_minor'], 'rrpCurrency' => $v['rrp_currency'],
                    'rrpSource' => $v['rrp_source'], 'rrpCheckedAt' => $v['rrp_checked_at'],
                    'marketPriceMinor' => $v['market_price_minor'] === null ? null : (int) $v['market_price_minor'],
                    'marketPriceSource' => $v['market_price_source'], 'marketCheckedAt' => $v['market_checked_at'],
                ], $variants),
            ];
        }, $rows);

        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function createProduct(ProductWriteRequest $input): array
    {
        $this->validateSlug($input->slug);
        $category = $this->category($input->category, $input->status);
        $copy = $this->copy($input);
        $product = new Product($input->slug, $category, $copy, $input->image);
        $product->update($input->slug, $category, $copy, $input->image, [$input->image], $input->badge, $input->featuredRank, $input->status);
        $product->describe($this->brand($input->brand), $this->productAttributes($input->attributes, $category));
        $variant = new ProductVariant($product, 'ND-'.strtoupper(bin2hex(random_bytes(4))), ['cs' => 'Standardní', 'de' => 'Standard', 'en' => 'Standard'], $input->priceCzk, $input->priceEur, 0);
        $this->em->persist($product);
        $this->em->persist($variant);
        $this->history->record($variant, null, null, PriceChange::MANUAL);
        $this->em->flush();
        $this->origins->forAdmin(ImportFieldOrigin::ENTITY_PRODUCT, $product->getId(), ['category', 'name', 'short', 'description', 'brand', 'attributes']);
        $this->origins->forAdmin(ImportFieldOrigin::ENTITY_VARIANT, $variant->getId(), ['sku', 'label', 'price']);

        return ['id' => $product->getId()->toRfc4122(), 'variantId' => $variant->getId()->toRfc4122()];
    }

    public function updateProduct(string $id, ProductWriteRequest $input): ?array
    {
        $product = $this->em->find(Product::class, Uuid::fromString($id));
        if ($product === null) {
            return null;
        }
        $this->validateSlug($input->slug, $id);
        $category = $this->category($input->category, $input->status, $product->getCategory());
        $moved = $category !== $product->getCategory();
        $before = $this->productOriginState($product);
        $copy = $this->copy($input, $product->getCopy());
        $images = array_values(array_unique([$input->image, ...$product->getImages()]));
        $product->update($input->slug, $category, $copy, $input->image, $images, $input->badge, $input->featuredRank, $input->status);
        $product->describe($this->brand($input->brand), $this->productAttributes($input->attributes, $category));
        if ($moved) {
            $this->recheckVariantAttributes($product, $category);
        }
        $baseId = $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = :id ORDER BY (active AND stock > 0) DESC, active DESC, sku ASC LIMIT 1', ['id' => $id]);
        $variant = $baseId === false ? null : $this->em->find(ProductVariant::class, Uuid::fromString($baseId));
        $priceChanged = false;
        if ($variant !== null) {
            [$oldCzk, $oldEur] = [$variant->getPriceCzk(), $variant->getPriceEur()];
            $variant->changePrice($input->priceCzk, $input->priceEur);
            $this->history->record($variant, $oldCzk, $oldEur, PriceChange::MANUAL);
            $priceChanged = $oldCzk !== $variant->getPriceCzk() || $oldEur !== $variant->getPriceEur();
        }
        $this->em->flush();
        $this->origins->forAdmin(ImportFieldOrigin::ENTITY_PRODUCT, $product->getId(), $this->productOriginFields($before, $product));
        if ($priceChanged && $variant !== null) {
            $this->origins->forAdmin(ImportFieldOrigin::ENTITY_VARIANT, $variant->getId(), ['price']);
        }

        return ['id' => $product->getId()->toRfc4122()];
    }

    /** The product fields the origin journal tracks, read before a write. */
    private function productOriginState(Product $product): array
    {
        return [
            'copy' => $product->getCopy(),
            'brand' => $product->getBrand(),
            'categoryId' => $product->getCategory()->getId()->toRfc4122(),
            'attributes' => $product->getAttributes(),
        ];
    }

    /** The origin fields the write actually changed: unchanged fields are not re-journaled. */
    private function productOriginFields(array $before, Product $product): array
    {
        $fields = [];
        if ($product->getCategory()->getId()->toRfc4122() !== $before['categoryId']) {
            $fields[] = 'category';
        }
        $copy = $product->getCopy();
        foreach (['name', 'short', 'description'] as $part) {
            foreach (['cs', 'de', 'en'] as $locale) {
                if (($copy[$locale][$part] ?? null) !== ($before['copy'][$locale][$part] ?? null)) {
                    $fields[] = $part;
                    break;
                }
            }
        }
        if ($product->getBrand() !== $before['brand']) {
            $fields[] = 'brand';
        }
        if ($product->getAttributes() != $before['attributes']) {
            $fields[] = 'attributes';
        }

        return $fields;
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
        $this->referencePrices($variant, $input);
        $this->em->persist($variant);
        $this->history->record($variant, null, null, PriceChange::MANUAL);
        $this->em->flush();
        $fields = ['sku', 'label', 'price'];
        if (trim((string) $input->ean) !== '') {
            $fields[] = 'ean';
        }
        if (trim((string) $input->mpn) !== '') {
            $fields[] = 'mpn';
        }
        if ($input->attributes !== []) {
            $fields[] = 'attributes';
        }
        if ($input->rrpMinor !== null) {
            $fields[] = 'rrp';
        }
        if ($input->marketPriceMinor !== null) {
            $fields[] = 'market_price';
        }
        $this->origins->forAdmin(ImportFieldOrigin::ENTITY_VARIANT, $variant->getId(), $fields);

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
        $before = [
            'label' => $variant->getLabel(), 'priceCzk' => $variant->getPriceCzk(), 'priceEur' => $variant->getPriceEur(),
            'ean' => $variant->getEan(), 'mpn' => $variant->getMpn(), 'attributes' => $variant->getAttributes(),
            'rrpMinor' => $variant->getRrpMinor(), 'rrpCurrency' => $variant->getRrpCurrency(),
            'marketPriceMinor' => $variant->getMarketPriceMinor(),
        ];
        [$oldCzk, $oldEur] = [$variant->getPriceCzk(), $variant->getPriceEur()];
        $variant->update($this->variantLabels($input), $input->priceCzk, $input->priceEur, $input->active, $input->color, $input->size);
        $this->identify($variant, $input);
        $this->referencePrices($variant, $input);
        $this->history->record($variant, $oldCzk, $oldEur, PriceChange::MANUAL);
        $this->em->flush();
        $fields = [];
        if ($variant->getLabel() != $before['label']) {
            $fields[] = 'label';
        }
        if ($variant->getPriceCzk() !== $before['priceCzk'] || $variant->getPriceEur() !== $before['priceEur']) {
            $fields[] = 'price';
        }
        if ($variant->getEan() !== $before['ean']) {
            $fields[] = 'ean';
        }
        if ($variant->getMpn() !== $before['mpn']) {
            $fields[] = 'mpn';
        }
        if ($variant->getAttributes() != $before['attributes']) {
            $fields[] = 'attributes';
        }
        if ($variant->getRrpMinor() !== $before['rrpMinor'] || $variant->getRrpCurrency() !== $before['rrpCurrency']) {
            $fields[] = 'rrp';
        }
        if ($variant->getMarketPriceMinor() !== $before['marketPriceMinor']) {
            $fields[] = 'market_price';
        }
        $this->origins->forAdmin(ImportFieldOrigin::ENTITY_VARIANT, $variant->getId(), $fields);

        return ['id' => $id, 'sku' => $variant->getSku()];
    }

    public function orders(AdminOrdersQuery $query): array
    {
        $where = [];
        $params = [];
        if ($query->status !== null) {
            $where[] = 'o.status = :status';
            $params['status'] = $query->status;
        }
        if ($query->paymentStatus !== null) {
            $where[] = 'o.payment_status = :paymentStatus';
            $params['paymentStatus'] = $query->paymentStatus;
        }
        if ($query->queue !== null) {
            $where[] = '('.OrderQueueSql::conditions()[$query->queue].')';
            $params['today'] = OrderQueueSql::today($this->clock->now());
        }
        $search = trim((string) $query->q);
        if ($search !== '') {
            $params['search'] = '%'.strtr($search, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%';
            $match = ['o.reference ILIKE :search', 'o.customer_name ILIKE :search', 'o.email ILIKE :search',
                'EXISTS (SELECT 1 FROM order_item si WHERE si.order_id = o.id AND si.supplier_reference ILIKE :search)'];
            // a phone is looked up by its digits, whatever spaces or dashes were typed
            $digits = preg_replace('/\D/', '', $search);
            if (preg_match('/^[+\d\s().\/-]+$/', $search) && strlen($digits) >= 3) {
                $match[] = "regexp_replace(COALESCE(o.phone, ''), '[^0-9]', '', 'g') LIKE :phone";
                $params['phone'] = '%'.$digits.'%';
            }
            $where[] = '('.implode(' OR ', $match).')';
        }
        $filter = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM shop_order o'.$filter, $params);
        $pages = max(1, (int) ceil($total / 30));
        $page = min($query->page, $pages);

        return [
            'items' => $this->orderRows(30, ($page - 1) * 30, $filter, $params),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    private function orderRows(int $limit, int $offset, string $filter = '', array $params = []): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT o.id, o.reference, o.status, o.payment_status, o.customer_name, o.email, o.phone, o.country, o.currency, o.total_minor, o.created_at, '
                .OrderQueueSql::flagColumns().' FROM shop_order o'.$filter.' ORDER BY o.created_at DESC, o.id DESC LIMIT :limit OFFSET :offset',
            ['today' => OrderQueueSql::today($this->clock->now())] + $params + ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'id' => $row['id'], 'reference' => $row['reference'], 'status' => $row['status'], 'paymentStatus' => $row['payment_status'],
            'customerName' => $row['customer_name'], 'email' => $row['email'], 'phone' => $row['phone'], 'country' => $row['country'],
            'total' => ['amount' => (int) $row['total_minor'], 'currency' => $row['currency']],
            'createdAt' => (new \DateTimeImmutable($row['created_at']))->format(\DATE_ATOM),
            'delayed' => (bool) $row['q_'.OrderQueues::DELAYED],
            'queues' => array_values(array_filter(OrderQueues::ALL, static fn (string $queue): bool => (bool) $row['q_'.$queue])),
        ], $rows);
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

    /** Visible category by slug; a draft may keep its current category after it was deactivated or hidden by a parent. */
    private function category(string $slug, string $status, ?Category $current = null): Category
    {
        $category = $this->em->getRepository(Category::class)->findOneBy(['slug' => $slug]);
        if ($category === null) {
            throw new \InvalidArgumentException('Unknown category');
        }
        if (!CategoryIndex::load($this->db)->isVisible($category->getId()->toRfc4122())) {
            if ($category !== $current) {
                throw new \InvalidArgumentException('Choose an active category');
            }
            if ($status === 'published') {
                throw new \InvalidArgumentException('This category is inactive or hidden by a parent; move the product to an active one before publishing');
            }
        }

        return $category;
    }

    /**
     * Variant overrides are checked again after the product moved to another category. Keys the new category
     * doesn't define, and values it doesn't allow, are dropped rather than refused: the product form can't
     * edit variants, so refusing would make the move impossible from the admin.
     */
    private function recheckVariantAttributes(Product $product, Category $category): void
    {
        $definitions = CategoryIndex::load($this->db)->effectiveAttributes($category->getId()->toRfc4122());
        foreach ($this->em->getRepository(ProductVariant::class)->findBy(['product' => $product]) as $variant) {
            if ($variant->getAttributes() !== []) {
                $variant->identify($variant->getMpn(), $variant->getEan(), AttributeSchema::keepValid($variant->getAttributes(), $definitions));
            }
        }
    }

    /** Attribute values as a JSON object, so an empty set goes out as {} and not []. */
    private static function attributeMap(string $json): \stdClass
    {
        return (object) json_decode($json, true, flags: JSON_THROW_ON_ERROR);
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

    private function referencePrices(ProductVariant $variant, VariantWriteRequest $input): void
    {
        $date = static fn (?string $value): ?\DateTimeImmutable => $value === null || trim($value) === '' ? null : new \DateTimeImmutable($value);
        $text = static fn (?string $value): ?string => trim((string) $value) === '' ? null : trim((string) $value);
        $variant->setReferencePrices(
            $input->rrpMinor, $input->rrpCurrency, $text($input->rrpSource), $date($input->rrpCheckedAt),
            $input->marketPriceMinor, $text($input->marketPriceSource), $date($input->marketCheckedAt),
        );
    }

    private function variantLabels(VariantWriteRequest $input): array
    {
        return ['cs' => trim($input->labelCs), 'de' => trim($input->labelDe), 'en' => trim($input->labelEn)];
    }
}
