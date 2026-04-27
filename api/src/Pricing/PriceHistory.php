<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Entity\PriceChange;
use App\Entity\ProductVariant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/** Writes and reads a variant's own price history. Every path that changes a price goes through record(). */
final class PriceHistory
{
    private const PAGE_SIZE = 30;

    public function __construct(private EntityManagerInterface $em, private Connection $db, private Security $security, private ClockInterface $clock) {}

    /**
     * Call after the variant got its new prices. Nothing is written when they didn't change;
     * null old prices mean a new variant's first price. Flushed together with the caller's changes.
     */
    public function record(ProductVariant $variant, ?int $oldPriceCzk, ?int $oldPriceEur, string $reason): void
    {
        if ($oldPriceCzk === $variant->getPriceCzk() && $oldPriceEur === $variant->getPriceEur()) {
            return;
        }
        $this->em->persist(new PriceChange($variant, $oldPriceCzk, $oldPriceEur, $reason, $this->actor(), $this->clock->now()));
    }

    /** Newest first; null when the variant doesn't exist. */
    public function page(string $variantId, int $page): ?array
    {
        if ($this->db->fetchOne('SELECT 1 FROM product_variant WHERE id = :id', ['id' => $variantId]) === false) {
            return null;
        }
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM price_change WHERE variant_id = :id', ['id' => $variantId]);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, old_price_czk, new_price_czk, old_price_eur, new_price_eur, reason, changed_by, changed_at
            FROM price_change WHERE variant_id = :id ORDER BY changed_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['id' => $variantId, 'limit' => self::PAGE_SIZE, 'offset' => ($page - 1) * self::PAGE_SIZE],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
        $int = static fn (mixed $value): ?int => $value === null ? null : (int) $value;

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'oldPriceCzk' => $int($row['old_price_czk']), 'newPriceCzk' => (int) $row['new_price_czk'],
                'oldPriceEur' => $int($row['old_price_eur']), 'newPriceEur' => (int) $row['new_price_eur'],
                'reason' => $row['reason'], 'changedBy' => $row['changed_by'],
                'changedAt' => (new \DateTimeImmutable($row['changed_at']))->format(\DATE_ATOM),
            ], $rows),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    private function actor(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? 'system';
    }
}
