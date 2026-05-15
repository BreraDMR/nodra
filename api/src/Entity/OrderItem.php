<?php

declare(strict_types=1);

namespace App\Entity;

use App\Order\LineState;
use App\Order\Procurement;
use Doctrine\DBAL\Types\Types;
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

    #[ORM\ManyToOne(targetEntity: Shipment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Shipment $shipment;

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

    #[ORM\Column(length: 12)]
    private string $procurementStatus;

    #[ORM\Column(length: 10)]
    private string $state = LineState::ACTIVE;

    /** supplier order number or link, admin-only */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $supplierReference = null;

    /** the failed line this one replaces */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $replacesItem = null;

    // sourcing as it was at checkout, admin-only; null on orders placed before D02
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $availabilityStatus = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $leadTimeMinDays = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $leadTimeMaxDays = null;

    #[ORM\ManyToOne(targetEntity: SupplierOffer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SupplierOffer $supplierOffer = null;

    #[ORM\Column(nullable: true)]
    private ?int $unitCostCzkMinor = null;

    public function __construct(ShopOrder $order, Shipment $shipment, ProductVariant $variant, string $productName, string $variantLabel, int $quantity, int $unitPriceMinor, bool $fromStock)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->shipment = $shipment;
        $this->variant = $variant;
        $this->productName = $productName;
        $this->variantLabel = $variantLabel;
        $this->sku = $variant->getSku();
        $this->quantity = $quantity;
        $this->unitPriceMinor = $unitPriceMinor;
        $this->lineTotalMinor = $quantity * $unitPriceMinor;
        $this->procurementStatus = $fromStock ? Procurement::FROM_STOCK : Procurement::TO_ORDER;
    }

    public function getId(): Uuid { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getShipment(): Shipment { return $this->shipment; }
    public function getVariant(): ?ProductVariant { return $this->variant; }
    public function getProductName(): string { return $this->productName; }
    public function getVariantLabel(): string { return $this->variantLabel; }
    public function getSku(): string { return $this->sku; }
    public function getQuantity(): int { return $this->quantity; }
    public function getUnitPriceMinor(): int { return $this->unitPriceMinor; }
    public function getLineTotalMinor(): int { return $this->lineTotalMinor; }
    public function getProcurementStatus(): string { return $this->procurementStatus; }
    public function getState(): string { return $this->state; }
    public function isActive(): bool { return $this->state === LineState::ACTIVE; }
    public function getSupplierReference(): ?string { return $this->supplierReference; }
    public function getReplacesItem(): ?self { return $this->replacesItem; }
    public function getAvailabilityStatus(): ?string { return $this->availabilityStatus; }
    public function getLeadTimeMinDays(): ?int { return $this->leadTimeMinDays; }
    public function getLeadTimeMaxDays(): ?int { return $this->leadTimeMaxDays; }
    public function getSupplierOffer(): ?SupplierOffer { return $this->supplierOffer; }
    public function getUnitCostCzkMinor(): ?int { return $this->unitCostCzkMinor; }

    /** The goods are here or in own stock, nothing left to buy. */
    public function hasGoods(): bool
    {
        return in_array($this->procurementStatus, [Procurement::FROM_STOCK, Procurement::RECEIVED], true);
    }

    public function recordSourcing(string $availabilityStatus, ?int $leadTimeMinDays, ?int $leadTimeMaxDays, ?SupplierOffer $offer, ?int $unitCostCzkMinor): void
    {
        $this->availabilityStatus = $availabilityStatus;
        $this->leadTimeMinDays = $leadTimeMinDays;
        $this->leadTimeMaxDays = $leadTimeMaxDays;
        $this->supplierOffer = $offer;
        $this->unitCostCzkMinor = $unitCostCzkMinor;
    }

    public function replaces(self $failed): void { $this->replacesItem = $failed; }

    public function changePrice(int $unitPriceMinor): void
    {
        $this->unitPriceMinor = $unitPriceMinor;
        $this->lineTotalMinor = $this->quantity * $unitPriceMinor;
    }

    public function changeLeadTime(int $minDays, int $maxDays): void
    {
        if ($minDays > $maxDays) {
            throw new \InvalidArgumentException('The minimum lead time cannot be above the maximum');
        }
        $this->leadTimeMinDays = $minDays;
        $this->leadTimeMaxDays = $maxDays;
    }

    public function markOrdered(string $supplierReference): void
    {
        $this->moveProcurement(Procurement::ORDERED, [Procurement::TO_ORDER]);
        $this->supplierReference = $supplierReference;
    }

    public function markReceived(): void { $this->moveProcurement(Procurement::RECEIVED, [Procurement::ORDERED]); }

    public function markFailed(): void { $this->moveProcurement(Procurement::FAILED, [Procurement::TO_ORDER, Procurement::ORDERED]); }

    /** Goods of a refused shipment went to own stock and are taken from it again. */
    public function takeFromStock(): void { $this->moveProcurement(Procurement::FROM_STOCK, [Procurement::FROM_STOCK, Procurement::RECEIVED]); }

    public function cancel(): void { $this->moveState(LineState::CANCELLED); }

    public function markReturned(): void { $this->moveState(LineState::RETURNED); }

    /** @param list<string> $from */
    private function moveProcurement(string $to, array $from): void
    {
        if (!in_array($this->procurementStatus, $from, true)) {
            throw new \DomainException(sprintf('A line that is %s cannot become %s', $this->procurementStatus, $to));
        }
        $this->procurementStatus = $to;
    }

    private function moveState(string $to): void
    {
        if ($this->state !== LineState::ACTIVE) {
            throw new \DomainException(sprintf('A line that is %s cannot become %s', $this->state, $to));
        }
        $this->state = $to;
    }
}
