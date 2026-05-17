<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\OrderEvent;
use App\Entity\ShopOrder;
use Doctrine\ORM\EntityManagerInterface;

final class OrderJournal
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @param array<string, mixed> $data */
    public function record(ShopOrder $order, string $type, array $data, string $actor): void
    {
        $this->em->persist(new OrderEvent($order, $type, $data, $actor));
    }
}
