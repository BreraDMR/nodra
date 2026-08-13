<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One line of the append-only payment ledger. There are no setters on purpose: entries are never edited. A mistaken
 * entry is voided by a `correction` that cancels its amount and points at it, once.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment')]
#[ORM\Index(columns: ['order_id', 'recorded_at'], name: 'idx_payment_order')]
#[ORM\Index(columns: ['claim_id'], name: 'idx_payment_claim')]
class Payment
{
    public const PAYMENT = 'payment';
    public const REFUND = 'refund';
    /** voids the entry it points at */
    public const CORRECTION = 'correction';
    /** what the admin records by hand; corrections come only from voiding */
    public const KINDS = [self::PAYMENT, self::REFUND];
    public const METHODS = ['cash', 'bank_transfer', 'card', 'carrier_cod'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShopOrder $order;

    #[ORM\ManyToOne(targetEntity: Shipment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Shipment $shipment;

    #[ORM\Column(length: 12)]
    private string $kind;

    #[ORM\Column(length: 16)]
    private string $method;

    #[ORM\Column]
    private int $amountMinor;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(length: 180)]
    private string $recordedBy;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 80, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64)]
    private string $requestHash;

    /** the entry a correction voids; unique, so an entry is voided once */
    #[ORM\OneToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?self $corrects = null;

    /** refunds only: the claim whose money this entry is; one entry settles one claim */
    #[ORM\ManyToOne(targetEntity: ReturnClaim::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ReturnClaim $claim = null;

    public function __construct(ShopOrder $order, ?Shipment $shipment, string $kind, string $method, int $amountMinor, string $recordedBy, ?string $note, string $idempotencyKey, string $requestHash, ?ReturnClaim $claim = null)
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('An amount must be above zero');
        }
        if ($claim !== null && $kind !== self::REFUND) {
            throw new \InvalidArgumentException('Only a refund can settle a claim');
        }
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->shipment = $shipment;
        $this->kind = $kind;
        $this->method = $method;
        $this->amountMinor = $amountMinor;
        $this->recordedAt = new \DateTimeImmutable();
        $this->recordedBy = $recordedBy;
        $this->note = $note;
        $this->idempotencyKey = $idempotencyKey;
        $this->requestHash = $requestHash;
        $this->claim = $claim;
    }

    /** A correction that cancels the amount of a payment or a refund. */
    public static function voiding(self $entry, string $reason, string $recordedBy): self
    {
        if ($entry->kind === self::CORRECTION) {
            throw new \DomainException('A correction cannot be voided');
        }
        $id = $entry->id->toRfc4122();
        $correction = new self($entry->order, $entry->shipment, self::CORRECTION, $entry->method, $entry->amountMinor, $recordedBy, $reason, 'void-'.$id, hash('sha256', 'void '.$id));
        $correction->corrects = $entry;

        return $correction;
    }

    public function getId(): Uuid { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getShipment(): ?Shipment { return $this->shipment; }
    public function getClaim(): ?ReturnClaim { return $this->claim; }
    public function getKind(): string { return $this->kind; }
    public function getMethod(): string { return $this->method; }
    public function getAmountMinor(): int { return $this->amountMinor; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function getRecordedBy(): string { return $this->recordedBy; }
    public function getNote(): ?string { return $this->note; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getRequestHash(): string { return $this->requestHash; }
    public function getCorrects(): ?self { return $this->corrects; }
}
