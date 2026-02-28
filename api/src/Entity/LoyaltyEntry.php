<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'loyalty_entry')]
#[ORM\UniqueConstraint(name: 'uniq_loyalty_order', columns: ['shop_order_id'])]
class LoyaltyEntry
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CustomerAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerAccount $account;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShopOrder $shopOrder;

    #[ORM\Column]
    private int $points;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(CustomerAccount $account, ShopOrder $order, int $points)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->shopOrder = $order;
        $this->points = $points;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getPoints(): int { return $this->points; }
}
