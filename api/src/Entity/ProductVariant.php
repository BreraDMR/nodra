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
    public const RRP_CURRENCIES = ['CZK', 'EUR'];

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

    // RRP and market price are admin-only reference data. RRP is never shown as an old NODRA price.
    #[ORM\Column(nullable: true)]
    private ?int $rrpMinor = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $rrpCurrency = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $rrpSource = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rrpCheckedAt = null;

    /** Lowest price a Czech customer would realistically pay elsewhere, in haléře. */
    #[ORM\Column(nullable: true)]
    private ?int $marketPriceMinor = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $marketPriceSource = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $marketCheckedAt = null;

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
    public function getRrpMinor(): ?int { return $this->rrpMinor; }
    public function getRrpCurrency(): ?string { return $this->rrpCurrency; }
    public function getRrpSource(): ?string { return $this->rrpSource; }
    public function getRrpCheckedAt(): ?\DateTimeImmutable { return $this->rrpCheckedAt; }
    public function getMarketPriceMinor(): ?int { return $this->marketPriceMinor; }
    public function getMarketPriceSource(): ?string { return $this->marketPriceSource; }
    public function getMarketCheckedAt(): ?\DateTimeImmutable { return $this->marketCheckedAt; }

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

    /** Only the prices; callers write the price history, see PriceHistory. */
    public function changePrice(int $priceCzk, int $priceEur): void
    {
        if ($priceCzk < 0 || $priceEur < 0) {
            throw new \InvalidArgumentException('Prices cannot be negative');
        }
        $this->priceCzk = $priceCzk;
        $this->priceEur = $priceEur;
    }

    /** RRP and market price. Without an amount the source and date of that price are dropped too. */
    public function setReferencePrices(?int $rrpMinor, ?string $rrpCurrency, ?string $rrpSource, ?\DateTimeImmutable $rrpCheckedAt, ?int $marketPriceMinor, ?string $marketPriceSource, ?\DateTimeImmutable $marketCheckedAt): void
    {
        if ($rrpMinor !== null && ($rrpMinor <= 0 || !in_array($rrpCurrency, self::RRP_CURRENCIES, true))) {
            throw new \InvalidArgumentException('RRP needs a positive amount and the currency CZK or EUR');
        }
        if ($marketPriceMinor !== null && $marketPriceMinor <= 0) {
            throw new \InvalidArgumentException('Market price must be positive');
        }
        $this->rrpMinor = $rrpMinor;
        $this->rrpCurrency = $rrpMinor === null ? null : $rrpCurrency;
        $this->rrpSource = $rrpMinor === null ? null : $rrpSource;
        $this->rrpCheckedAt = $rrpMinor === null ? null : $rrpCheckedAt;
        $this->marketPriceMinor = $marketPriceMinor;
        $this->marketPriceSource = $marketPriceMinor === null ? null : $marketPriceSource;
        $this->marketCheckedAt = $marketPriceMinor === null ? null : $marketCheckedAt;
    }
}
