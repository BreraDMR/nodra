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
}
