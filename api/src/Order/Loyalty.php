<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\LoyaltyEntry;
use App\Entity\ShopOrder;
use Doctrine\ORM\EntityManagerInterface;

/** Points are earned once, when an order completes, and taken back for money refunded after that. Spending is off. */
final class Loyalty
{
    public function __construct(private EntityManagerInterface $em) {}

    /** One point per full 100 Kč of goods (4 € on the older EUR demo orders). */
    public static function points(int $goodsMinor, string $currency): int
    {
        return intdiv(max(0, $goodsMinor), $currency === 'CZK' ? 10_000 : 400);
    }

    /** Earns the points of a completed order's goods; 0 without an account or when already earned. */
    public function earn(ShopOrder $order): int
    {
        $account = $order->getAccount();
        $points = self::points($order->getSubtotalMinor(), $order->getCurrency());
        if ($account === null || $points === 0 || $this->earned($order) !== null) {
            return 0;
        }
        $this->em->persist(new LoyaltyEntry($account, $order, $points));

        return $points;
    }

    /**
     * After a refund on a completed order: the points for everything refunded since completion are taken back, never
     * more than the order earned. Returns the (negative) points written now, 0 when nothing changed.
     */
    public function afterRefund(ShopOrder $order, int $refundedSinceCompletionMinor): int
    {
        $earned = $this->earned($order);
        if ($earned === null) {
            return 0;
        }
        $takenBack = -(int) $this->em->createQuery('SELECT COALESCE(SUM(e.points), 0) FROM '.LoyaltyEntry::class.' e WHERE e.shopOrder = :order AND e.reason = :reason')
            ->setParameters(['order' => $order, 'reason' => LoyaltyEntry::REFUND])
            ->getSingleScalarResult();
        $due = min($earned->getPoints(), self::points($refundedSinceCompletionMinor, $order->getCurrency()));
        if ($due <= $takenBack) {
            return 0;
        }
        $this->em->persist(new LoyaltyEntry($earned->getAccount(), $order, $takenBack - $due, LoyaltyEntry::REFUND));

        return $takenBack - $due;
    }

    private function earned(ShopOrder $order): ?LoyaltyEntry
    {
        return $this->em->getRepository(LoyaltyEntry::class)->findOneBy(['shopOrder' => $order, 'reason' => LoyaltyEntry::EARN]);
    }
}
