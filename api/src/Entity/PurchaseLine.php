<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One order line bought in a purchase, with its share of the inbound shipping and the actual unit cost it came to. */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_line')]
#[ORM\Index(columns: ['order_item_id'], name: 'idx_purchase_line_item')]
class PurchaseLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Purchase::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Purchase $purchase;

    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrderItem $orderItem;

    #[ORM\Column]
    private int $quantity;

    /** purchase currency */
    #[ORM\Column]
    private int $unitPriceMinor;

    /** this line's share of the purchase's inbound shipping, purchase currency */
    #[ORM\Column]
    private int $allocatedShippingMinor;

    /** actual landed cost per unit in haléře */
    #[ORM\Column]
    private int $unitCostCzkMinor;

    public function __construct(Purchase $purchase, OrderItem $orderItem, int $unitPriceMinor, int $allocatedShippingMinor, int $unitCostCzkMinor)
    {
        $this->id = Uuid::v7();
        $this->purchase = $purchase;
        $this->orderItem = $orderItem;
        $this->quantity = $orderItem->getQuantity();
        $this->unitPriceMinor = $unitPriceMinor;
        $this->allocatedShippingMinor = $allocatedShippingMinor;
        $this->unitCostCzkMinor = $unitCostCzkMinor;
    }

    public function getId(): Uuid { return $this->id; }
    public function getPurchase(): Purchase { return $this->purchase; }
    public function getOrderItem(): OrderItem { return $this->orderItem; }
    public function getQuantity(): int { return $this->quantity; }
    public function getUnitPriceMinor(): int { return $this->unitPriceMinor; }
    public function getAllocatedShippingMinor(): int { return $this->allocatedShippingMinor; }
    public function getUnitCostCzkMinor(): int { return $this->unitCostCzkMinor; }
}
