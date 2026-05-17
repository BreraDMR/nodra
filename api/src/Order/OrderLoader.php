<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Shipment;
use App\Entity\ShopOrder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class OrderLoader
{
    public function __construct(private EntityManagerInterface $em, private Connection $db) {}

    /** @param bool $lock holds the order row until the transaction ends; call it inside one */
    public function load(string $orderId, bool $lock = false): ?OrderState
    {
        if (!Uuid::isValid($orderId)) {
            return null;
        }
        if ($lock) {
            $this->db->fetchOne('SELECT id FROM shop_order WHERE id = :id FOR UPDATE', ['id' => $orderId]);
        }
        $order = $this->em->find(ShopOrder::class, Uuid::fromString($orderId));

        return $order === null ? null : $this->state($order);
    }

    public function state(ShopOrder $order): OrderState
    {
        return new OrderState(
            $order,
            $this->em->getRepository(OrderItem::class)->findBy(['order' => $order], ['id' => 'ASC']),
            $this->em->getRepository(Shipment::class)->findBy(['order' => $order], ['position' => 'ASC']),
            $this->em->getRepository(Payment::class)->findBy(['order' => $order], ['recordedAt' => 'ASC', 'id' => 'ASC']),
        );
    }
}
