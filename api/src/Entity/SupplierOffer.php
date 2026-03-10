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
#[ORM\UniqueConstraint(name: 'uniq_supplier_offer_product_url', columns: ['product_id', 'url'], options: ['where' => '(variant_id IS NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_supplier_offer_variant_url', columns: ['variant_id', 'url'], options: ['where' => '(variant_id IS NOT NULL)'])]
class SupplierOffer
{
    public const SUPPLIERS = ['allegro_cz', 'allegro_pl', 'bikeinn', 'bike24', 'bike_discount', 'other'];
    public const STATUSES = ['snapshot', 'matched', 'rejected'];

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
    }

    public function getId(): Uuid { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getVariant(): ?ProductVariant { return $this->variant; }
    public function getUrl(): string { return $this->url; }
    public function getVerificationStatus(): string { return $this->verificationStatus; }

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
        $this->currency = $currency;
        $this->priceMinor = $priceMinor;
        $this->reportedQuantity = $reportedQuantity;
        $this->checkedAt = $checkedAt;
        $this->leadTimeMinDays = $leadTimeMinDays;
        $this->leadTimeMaxDays = $leadTimeMaxDays;
        $this->variant = $variant;
        $this->verificationStatus = $verificationStatus;
    }
}
