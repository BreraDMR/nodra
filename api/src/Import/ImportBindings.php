<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportFeedBinding;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The admin's manual link between a feed row (supplier + supplier SKU) and an existing variant.
 * The planner starts its matching from these bindings, so the link is remembered for every
 * following run of that feed (D03.3).
 */
final class ImportBindings
{
    private const PAGE_SIZE = 50;

    public function __construct(private EntityManagerInterface $em, private Connection $db) {}

    /** @return array<string, mixed> */
    public function list(?string $variantId, int $page): array
    {
        $params = [];
        $where = '';
        if ($variantId !== null && $variantId !== '') {
            $where = ' WHERE variant_id = :variant';
            $params['variant'] = $variantId;
        }
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM import_feed_binding'.$where, $params);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, supplier, supplier_sku, variant_id, created_by, created_at FROM import_feed_binding'.$where.' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => self::PAGE_SIZE, 'offset' => ($page - 1) * self::PAGE_SIZE],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'supplier' => $row['supplier'],
                'supplierSku' => $row['supplier_sku'],
                'variantId' => $row['variant_id'],
                'createdBy' => $row['created_by'],
                'createdAt' => (new \DateTimeImmutable($row['created_at']))->format(\DATE_ATOM),
            ], $rows),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /** Null when the variant doesn't exist; the controller turns that into a 404. */
    public function create(string $supplier, string $supplierSku, string $variantId, string $adminEmail, \DateTimeImmutable $now): ?array
    {
        $variant = $this->em->find(ProductVariant::class, $variantId);
        if ($variant === null) {
            return null;
        }
        $binding = new ImportFeedBinding($variant, $supplier, $supplierSku, $adminEmail, $now);
        $taken = $this->db->fetchOne(
            'SELECT id FROM import_feed_binding WHERE supplier = :supplier AND supplier_sku = :sku',
            ['supplier' => $binding->getSupplier(), 'sku' => $binding->getSupplierSku()],
        );
        if ($taken !== false) {
            throw new \DomainException('This supplier SKU is already bound to a variant');
        }
        $this->em->persist($binding);
        $this->em->flush();

        return $this->row($binding);
    }

    public function delete(string $id): bool
    {
        $binding = $this->em->find(ImportFeedBinding::class, $id);
        if ($binding === null) {
            return false;
        }
        $this->em->remove($binding);
        $this->em->flush();

        return true;
    }

    /** @return array<string, mixed> */
    private function row(ImportFeedBinding $binding): array
    {
        return [
            'id' => $binding->getId()->toRfc4122(),
            'supplier' => $binding->getSupplier(),
            'supplierSku' => $binding->getSupplierSku(),
            'variantId' => $binding->getVariant()->getId()->toRfc4122(),
            'createdBy' => $binding->getCreatedBy(),
            'createdAt' => $binding->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
