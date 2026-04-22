<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One change of a variant's own CZK/EUR price. It's the record any later discount claim has to rest on,
 * so rows are only ever added. Old prices are null for the first price of a new variant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'price_change')]
#[ORM\Index(columns: ['variant_id', 'changed_at'], name: 'idx_price_change_variant')]
class PriceChange
{
    public const MANUAL = 'manual';
    public const REPRICE = 'reprice';
    public const IMPORT = 'import';
    public const REASONS = [self::MANUAL, self::REPRICE, self::IMPORT];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\Column(nullable: true)]
    private ?int $oldPriceCzk;

    #[ORM\Column]
    private int $newPriceCzk;

    #[ORM\Column(nullable: true)]
    private ?int $oldPriceEur;

    #[ORM\Column]
    private int $newPriceEur;

    #[ORM\Column(length: 16)]
    private string $reason;

    /** admin email, or "system" */
    #[ORM\Column(length: 180)]
    private string $changedBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    public function __construct(ProductVariant $variant, ?int $oldPriceCzk, ?int $oldPriceEur, string $reason, string $changedBy, \DateTimeImmutable $changedAt)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown price change reason');
        }
        $this->id = Uuid::v7();
        $this->variant = $variant;
        $this->oldPriceCzk = $oldPriceCzk;
        $this->newPriceCzk = $variant->getPriceCzk();
        $this->oldPriceEur = $oldPriceEur;
        $this->newPriceEur = $variant->getPriceEur();
        $this->reason = $reason;
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getVariant(): ProductVariant { return $this->variant; }
    public function getOldPriceCzk(): ?int { return $this->oldPriceCzk; }
    public function getNewPriceCzk(): int { return $this->newPriceCzk; }
    public function getOldPriceEur(): ?int { return $this->oldPriceEur; }
    public function getNewPriceEur(): int { return $this->newPriceEur; }
    public function getReason(): string { return $this->reason; }
    public function getChangedBy(): string { return $this->changedBy; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
