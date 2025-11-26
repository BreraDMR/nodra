<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'order_item')]
#[ORM\Index(columns: ['order_id'], name: 'idx_order_item_order')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShopOrder $order;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProductVariant $variant;

    #[ORM\Column(length: 160)]
    private string $productName;

    #[ORM\Column(length: 120)]
    private string $variantLabel;

    #[ORM\Column(length: 80)]
    private string $sku;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column]
    private int $unitPriceMinor;

    #[ORM\Column]
    private int $lineTotalMinor;

    public function __construct(ShopOrder $order, ProductVariant $variant, string $productName, string $variantLabel, int $quantity, int $unitPriceMinor)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->variant = $variant;
        $this->productName = $productName;
        $this->variantLabel = $variantLabel;
        $this->sku = $variant->getSku();
        $this->quantity = $quantity;
        $this->unitPriceMinor = $unitPriceMinor;
        $this->lineTotalMinor = $quantity * $unitPriceMinor;
    }

    public function getId(): Uuid { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getVariant(): ?ProductVariant { return $this->variant; }
    public function getProductName(): string { return $this->productName; }
    public function getVariantLabel(): string { return $this->variantLabel; }
    public function getSku(): string { return $this->sku; }
    public function getQuantity(): int { return $this->quantity; }
    public function getUnitPriceMinor(): int { return $this->unitPriceMinor; }
}
