<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Points ledger: one `earn` entry per completed order, negative `refund` entries after money went back, and
 * `correction` entries (either sign) when a voided ledger entry changed what the customer paid.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loyalty_entry')]
#[ORM\Index(columns: ['shop_order_id'], name: 'idx_loyalty_order')]
#[ORM\UniqueConstraint(name: 'uniq_loyalty_earn', columns: ['shop_order_id'], options: ['where' => "((reason)::text = 'earn'::text)"])]
class LoyaltyEntry
{
    public const EARN = 'earn';
    public const REFUND = 'refund';
    public const CORRECTION = 'correction';

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

    #[ORM\Column(length: 10, options: ['default' => 'earn'])]
    private string $reason;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(CustomerAccount $account, ShopOrder $order, int $points, string $reason = self::EARN)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->shopOrder = $order;
        $this->points = $points;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getAccount(): CustomerAccount { return $this->account; }
    public function getPoints(): int { return $this->points; }
    public function getReason(): string { return $this->reason; }
}
