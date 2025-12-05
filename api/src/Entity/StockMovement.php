<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'stock_movement')]
#[ORM\Index(columns: ['variant_id', 'created_at'], name: 'idx_stock_movement_variant')]
class StockMovement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ProductVariant $variant;

    #[ORM\Column]
    private int $delta;

    #[ORM\Column(length: 200)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ProductVariant $variant, int $delta, string $reason)
    {
        $this->id = Uuid::v7();
        $this->variant = $variant;
        $this->delta = $delta;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getVariant(): ProductVariant { return $this->variant; }
    public function getDelta(): int { return $this->delta; }
    public function getReason(): string { return $this->reason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
