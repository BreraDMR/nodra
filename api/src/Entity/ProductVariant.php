<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_variant')]
#[ORM\Index(columns: ['product_id', 'active'], name: 'idx_variant_product')]
#[ORM\Index(columns: ['mpn'], name: 'idx_variant_mpn')]
#[ORM\UniqueConstraint(name: 'uniq_variant_ean', columns: ['ean'], options: ['where' => '(ean IS NOT NULL)'])]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 80, unique: true)]
    private string $sku;

    /** @var array{cs: string, de: string, en: string} */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $label;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $color;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $size;

    #[ORM\Column]
    private int $priceCzk;

    #[ORM\Column]
    private int $priceEur;

    #[ORM\Column]
    private int $stock;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mpn = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $ean = null;

    /** @var \stdClass|array<string, string> values that override the product's, an object for the same reason as Product::$attributes */
    #[ORM\Column(type: Types::JSONB_OBJECT, options: ['default' => '{}'])]
    private \stdClass|array $attributes;

    public function __construct(Product $product, string $sku, array $label, int $priceCzk, int $priceEur, int $stock, ?string $color = null, ?string $size = null)
    {
        $this->id = Uuid::v7();
        $this->product = $product;
        $this->sku = $sku;
        $this->label = $label;
        $this->priceCzk = $priceCzk;
        $this->priceEur = $priceEur;
        $this->stock = $stock;
        $this->color = $color;
        $this->size = $size;
        $this->attributes = new \stdClass();
    }

    public function getId(): Uuid { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getSku(): string { return $this->sku; }
    public function getLabel(): array { return $this->label; }
    public function getColor(): ?string { return $this->color; }
    public function getSize(): ?string { return $this->size; }
    public function getPriceCzk(): int { return $this->priceCzk; }
    public function getPriceEur(): int { return $this->priceEur; }
    public function getStock(): int { return $this->stock; }
    public function isActive(): bool { return $this->active; }
    public function getMpn(): ?string { return $this->mpn; }
    public function getEan(): ?string { return $this->ean; }
    /** @return array<string, string> */
    public function getAttributes(): array { return (array) $this->attributes; }

    public function adjustStock(int $delta): void
    {
        if ($this->stock + $delta < 0) {
            throw new \DomainException('Insufficient stock');
        }
        $this->stock += $delta;
    }

    public function update(array $label, int $priceCzk, int $priceEur, bool $active, ?string $color, ?string $size): void
    {
        $this->label = $label;
        $this->priceCzk = $priceCzk;
        $this->priceEur = $priceEur;
        $this->active = $active;
        $this->color = $color;
        $this->size = $size;
    }

    public function identify(?string $mpn, ?string $ean, array $attributes): void
    {
        $this->mpn = $mpn;
        $this->ean = $ean;
        $this->attributes = (object) $attributes;
    }
}
