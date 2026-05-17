<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\ProductVariant;
use App\Entity\ShopOrder;
use App\Entity\StockMovement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Own stock changes made by orders, each one a stock movement with the order reference. */
final class StockKeeper
{
    public function __construct(private EntityManagerInterface $em, private Connection $db) {}

    /** Takes goods out of own stock for an order; false, and nothing changed, when there aren't enough. */
    public function take(Uuid $variantId, int $quantity, ShopOrder $order, string $why): bool
    {
        $taken = $this->db->executeStatement(
            'UPDATE product_variant SET stock = stock - :quantity WHERE id = :id AND stock >= :quantity',
            ['quantity' => $quantity, 'id' => $variantId->toRfc4122()],
        );
        if ($taken !== 1) {
            return false;
        }
        $this->movement($variantId, -$quantity, $order, $why);

        return true;
    }

    /** Goods of an order go (back) to own stock. */
    public function put(Uuid $variantId, int $quantity, ShopOrder $order, string $why): void
    {
        $this->db->executeStatement('UPDATE product_variant SET stock = stock + :quantity WHERE id = :id', ['quantity' => $quantity, 'id' => $variantId->toRfc4122()]);
        $this->movement($variantId, $quantity, $order, $why);
    }

    private function movement(Uuid $variantId, int $delta, ShopOrder $order, string $why): void
    {
        $variant = $this->em->getReference(ProductVariant::class, $variantId);
        $this->em->persist(new StockMovement($variant, $delta, mb_substr(sprintf('Order %s: %s', $order->getReference(), $why), 0, 200), $order));
    }
}
