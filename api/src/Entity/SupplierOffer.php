<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'supplier_offer')]
#[ORM\Index(columns: ['product_id', 'checked_at'], name: 'idx_supplier_offer_product')]
#[ORM\Index(columns: ['variant_id'], name: 'idx_supplier_offer_variant')]
#[ORM\Index(columns: ['supplier', 'supplier_sku'], name: 'idx_supplier_offer_supplier_sku')]
#[ORM\UniqueConstraint(name: 'uniq_supplier_offer_product_url', columns: ['product_id', 'url'], options: ['where' => '(variant_id IS NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_supplier_offer_variant_url', columns: ['variant_id', 'url'], options: ['where' => '(variant_id IS NOT NULL)'])]
class SupplierOffer
{
    public const SUPPLIERS = ['allegro_cz', 'allegro_pl', 'bikeinn', 'bike24', 'bike_discount', 'bike_components', 'other'];
    public const STATUSES = ['snapshot', 'matched', 'rejected'];
    /** 1.0 in integer millionths, the rate of a CZK offer */
    public const CZK_RATE = 1_000_000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProductVariant $variant = null;

    #[ORM\Column(length: 32)]
    private string $supplier;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $seller = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $leadTimeMinDays = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $leadTimeMaxDays = null;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column]
    private int $priceMinor;

    #[ORM\Column(nullable: true)]
    private ?int $reportedQuantity;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $checkedAt;

    #[ORM\Column(length: 24)]
    private string $verificationStatus = 'snapshot';

    /** The supplier's own article number, the re-import key of a feed row (Awin product_id). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $supplierSku = null;

    /** Delivery to NODRA per unit, in the offer currency. */
    #[ORM\Column(options: ['default' => 0])]
    private int $inboundShippingMinor = 0;

    /** CZK per offer-currency unit in millionths. Null while the rate isn't known, such an offer can't be priced. */
    #[ORM\Column(nullable: true)]
    private ?int $fxRateCzk;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fxRateDate = null;

    public function __construct(Product $product, string $supplier, string $url, string $title, string $currency, int $priceMinor, ?int $reportedQuantity, \DateTimeImmutable $checkedAt)
    {
        $this->id = Uuid::v7();
        $this->product = $product;
        $this->supplier = $supplier;
        $this->url = $url;
        $this->title = $title;
        $this->currency = $currency;
        $this->priceMinor = $priceMinor;
        $this->reportedQuantity = $reportedQuantity;
        $this->checkedAt = $checkedAt;
        $this->fxRateCzk = $currency === 'CZK' ? self::CZK_RATE : null;
    }

    public function getId(): Uuid { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getVariant(): ?ProductVariant { return $this->variant; }
    public function getUrl(): string { return $this->url; }
    public function getVerificationStatus(): string { return $this->verificationStatus; }
    public function getSupplierSku(): ?string { return $this->supplierSku; }
    public function getSupplier(): string { return $this->supplier; }
    public function getSeller(): ?string { return $this->seller; }
    public function getCurrency(): string { return $this->currency; }
    public function getPriceMinor(): int { return $this->priceMinor; }
    public function getReportedQuantity(): ?int { return $this->reportedQuantity; }
    public function getCheckedAt(): \DateTimeImmutable { return $this->checkedAt; }
    public function getLeadTimeMinDays(): ?int { return $this->leadTimeMinDays; }
    public function getLeadTimeMaxDays(): ?int { return $this->leadTimeMaxDays; }
    public function getInboundShippingMinor(): int { return $this->inboundShippingMinor; }
    public function getFxRateCzk(): ?int { return $this->fxRateCzk; }
    public function getFxRateDate(): ?\DateTimeImmutable { return $this->fxRateDate; }

    public function update(string $supplier, string $url, string $title, ?string $seller, string $currency, int $priceMinor, ?int $reportedQuantity, \DateTimeImmutable $checkedAt, ?int $leadTimeMinDays, ?int $leadTimeMaxDays, ?ProductVariant $variant, string $verificationStatus): void
    {
        if ($verificationStatus === 'matched' && $variant === null) {
            throw new \InvalidArgumentException('A matched offer needs the exact variant');
        }
        if ($variant !== null && !$variant->getProduct()->getId()->equals($this->product->getId())) {
            throw new \InvalidArgumentException('The variant belongs to another product');
        }
        if ($leadTimeMinDays !== null && $leadTimeMaxDays !== null && $leadTimeMinDays > $leadTimeMaxDays) {
            throw new \InvalidArgumentException('Lead time minimum is above the maximum');
        }
        $this->supplier = $supplier;
        $this->url = $url;
        $this->title = $title;
        $this->seller = $seller;
        if ($currency !== $this->currency) {
            // a rate for the old currency means nothing for the new one
            $this->fxRateCzk = $currency === 'CZK' ? self::CZK_RATE : null;
            $this->fxRateDate = null;
        }
        $this->currency = $currency;
        $this->priceMinor = $priceMinor;
        $this->reportedQuantity = $reportedQuantity;
        $this->checkedAt = $checkedAt;
        $this->leadTimeMinDays = $leadTimeMinDays;
        $this->leadTimeMaxDays = $leadTimeMaxDays;
        $this->variant = $variant;
        $this->verificationStatus = $verificationStatus;
    }

    /** Inbound shipping and exchange rate. A CZK offer always has the rate 1. */
    public function setCost(int $inboundShippingMinor, ?int $fxRateCzk, ?\DateTimeImmutable $fxRateDate): void
    {
        if ($inboundShippingMinor < 0) {
            throw new \InvalidArgumentException('Inbound shipping cannot be negative');
        }
        if ($this->currency === 'CZK') {
            if ($fxRateCzk !== null && $fxRateCzk !== self::CZK_RATE) {
                throw new \InvalidArgumentException('A CZK offer has the exchange rate 1 (1000000)');
            }
            $fxRateCzk = self::CZK_RATE;
        } elseif ($fxRateCzk !== null && $fxRateCzk <= 0) {
            throw new \InvalidArgumentException('The exchange rate must be positive');
        }
        $this->inboundShippingMinor = $inboundShippingMinor;
        $this->fxRateCzk = $fxRateCzk;
        $this->fxRateDate = $fxRateDate;
    }

    /** Written by the feed import; the admin form doesn't carry it. */
    public function setSupplierSku(?string $supplierSku): void
    {
        $this->supplierSku = $supplierSku === null || trim($supplierSku) === '' ? null : mb_substr(trim($supplierSku), 0, 120);
    }
}
