<?php

declare(strict_types=1);

namespace App\Entity;

use App\Purchase\PurchaseStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One order placed by a human on a supplier's site for lines of several customer orders. The system only records
 * it: the reference, the rate and the inbound shipping split across the lines. Admin-only, never in public JSON.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase')]
#[ORM\Index(columns: ['status', 'ordered_at'], name: 'idx_purchase_status')]
class Purchase
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** supplier code, as on offers */
    #[ORM\Column(length: 32)]
    private string $supplier;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $seller;

    /** the supplier's order number */
    #[ORM\Column(length: 120)]
    private string $reference;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** CZK per purchase-currency unit in millionths, 1 000 000 for CZK */
    #[ORM\Column]
    private int $fxRateCzk;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fxRateDate;

    /** delivery to NODRA for the whole purchase, purchase currency */
    #[ORM\Column]
    private int $inboundShippingMinor;

    #[ORM\Column(length: 12)]
    private string $status = PurchaseStatus::ORDERED;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $orderedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $cancelReason = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note;

    /** admin email */
    #[ORM\Column(length: 180)]
    private string $createdBy;

    #[ORM\Column(length: 80, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64)]
    private string $requestHash;

    public function __construct(
        string $supplier,
        ?string $seller,
        string $reference,
        string $currency,
        int $fxRateCzk,
        ?\DateTimeImmutable $fxRateDate,
        int $inboundShippingMinor,
        ?string $note,
        string $createdBy,
        string $idempotencyKey,
        string $requestHash,
    ) {
        if ($fxRateCzk <= 0 || $inboundShippingMinor < 0) {
            throw new \InvalidArgumentException('The rate must be above zero and the shipping not negative');
        }
        $this->id = Uuid::v7();
        $this->supplier = $supplier;
        $this->seller = $seller;
        $this->reference = $reference;
        $this->currency = $currency;
        $this->fxRateCzk = $fxRateCzk;
        $this->fxRateDate = $fxRateDate;
        $this->inboundShippingMinor = $inboundShippingMinor;
        $this->note = $note;
        $this->createdBy = $createdBy;
        $this->idempotencyKey = $idempotencyKey;
        $this->requestHash = $requestHash;
        $this->orderedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSupplier(): string { return $this->supplier; }
    public function getSeller(): ?string { return $this->seller; }
    public function getReference(): string { return $this->reference; }
    public function getCurrency(): string { return $this->currency; }
    public function getFxRateCzk(): int { return $this->fxRateCzk; }
    public function getFxRateDate(): ?\DateTimeImmutable { return $this->fxRateDate; }
    public function getInboundShippingMinor(): int { return $this->inboundShippingMinor; }
    public function getStatus(): string { return $this->status; }
    public function getOrderedAt(): \DateTimeImmutable { return $this->orderedAt; }
    public function getReceivedAt(): ?\DateTimeImmutable { return $this->receivedAt; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function getCancelReason(): ?string { return $this->cancelReason; }
    public function getNote(): ?string { return $this->note; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getRequestHash(): string { return $this->requestHash; }

    public function receive(): void
    {
        $this->move(PurchaseStatus::RECEIVED);
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function cancel(string $reason): void
    {
        $this->move(PurchaseStatus::CANCELLED);
        $this->cancelledAt = new \DateTimeImmutable();
        $this->cancelReason = $reason;
    }

    private function move(string $to): void
    {
        if ($this->status !== PurchaseStatus::ORDERED) {
            throw new \DomainException(sprintf('A purchase that is %s cannot become %s', $this->status, $to));
        }
        $this->status = $to;
    }
}
