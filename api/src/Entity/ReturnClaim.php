<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An after-sale case — a return (withdrawal) or a warranty claim — kept as its own record beside the order.
 * The claim never changes the order: line states, stock and the payment ledger stay with the order actions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'return_claim')]
#[ORM\Index(columns: ['order_id'], name: 'idx_return_claim_order')]
#[ORM\Index(columns: ['status', 'opened_at'], name: 'idx_return_claim_status')]
#[ORM\UniqueConstraint(name: 'uniq_return_claim_number', columns: ['number'])]
class ReturnClaim
{
    public const KIND_RETURN = 'return';
    public const KIND_WARRANTY = 'warranty';
    public const KINDS = [self::KIND_RETURN, self::KIND_WARRANTY];

    public const OPEN = 'open';
    public const WAITING = 'waiting';
    public const ACCEPTED = 'accepted';
    /** closed with a refusal, terminal */
    public const REJECTED = 'rejected';
    /** closed with the outcome done, terminal */
    public const RESOLVED = 'resolved';
    public const STATUSES = [self::OPEN, self::WAITING, self::ACCEPTED, self::REJECTED, self::RESOLVED];
    public const OPEN_STATUSES = [self::OPEN, self::WAITING, self::ACCEPTED];

    public const RESOLUTION_REFUND = 'refund';
    public const RESOLUTION_REPLACEMENT = 'replacement';
    public const RESOLUTION_REPAIR = 'repair';
    public const RESOLUTIONS = [self::RESOLUTION_REFUND, self::RESOLUTION_REPLACEMENT, self::RESOLUTION_REPAIR];

    private const RETURN_WINDOW_DAYS = 14;
    private const WARRANTY_WINDOW_MONTHS = 24;
    /** ČOI: the seller refunds within 14 days of the withdrawal, not of its acceptance */
    private const RETURN_REFUND_DAYS = 14;
    /** ČOI: the claim is settled within 30 days of the day the customer lodged it; D00.7 may set the final text */
    private const WARRANTY_SETTLE_DAYS = 30;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShopOrder $order;

    /** the goods the customer names; null when the claim is about the order as a whole */
    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrderItem $item = null;

    #[ORM\Column(length: 20)]
    private string $number;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 16)]
    private string $status = self::OPEN;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note;

    /** the agreed refund, CZK haléře; set at acceptance */
    #[ORM\Column(nullable: true)]
    private ?int $refundAmountMinor = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $resolution = null;

    /** why it was rejected, how it was settled */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $resolutionNote = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(length: 180)]
    private string $openedBy;

    /** Prague calendar day the customer lodged the case; both windows and the settle deadline count from it */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $contactedOn;

    /** Prague calendar date the goods were handed over; both windows count from it */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $handoverDate;

    /** the last day of the customer's window: return handover + 14 days, warranty handover + 24 months */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $windowEnd;

    /** when the case must be settled: fixed once from the contact day, accepting again never moves it */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /**
     * @param \DateTimeImmutable $contactedOn Prague calendar day the customer lodged the case
     * @param \DateTimeImmutable $handoverDate Prague calendar date the goods were handed over
     * @param \DateTimeImmutable $windowEnd    the last day of the customer's window, from {@see self::windowEnd()}
     */
    public function __construct(ShopOrder $order, ?OrderItem $item, string $kind, ?string $note, string $openedBy, \DateTimeImmutable $contactedOn, \DateTimeImmutable $handoverDate, \DateTimeImmutable $windowEnd)
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown claim kind');
        }
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->item = $item;
        $this->number = 'RC-'.strtoupper(bin2hex(random_bytes(4)));
        $this->kind = $kind;
        $this->note = $note;
        $this->openedAt = new \DateTimeImmutable();
        $this->openedBy = $openedBy;
        $this->contactedOn = $contactedOn;
        $this->handoverDate = $handoverDate;
        $this->windowEnd = $windowEnd;
        $days = $kind === self::KIND_RETURN ? self::RETURN_REFUND_DAYS : self::WARRANTY_SETTLE_DAYS;
        $this->dueAt = $contactedOn->modify(sprintf('+%d days', $days));
    }

    public function wait(): void { $this->move(self::WAITING, [self::OPEN]); }

    /** Acknowledge the case; accepted -> accepted corrects the agreed refund and never the deadline. */
    public function accept(?int $refundAmountMinor): void
    {
        $this->move(self::ACCEPTED, [self::OPEN, self::WAITING, self::ACCEPTED]);
        if ($refundAmountMinor !== null && $refundAmountMinor < 0) {
            throw new \InvalidArgumentException('A refund amount cannot be negative');
        }
        $this->refundAmountMinor = $refundAmountMinor;
    }

    public function reject(string $reason): void
    {
        $this->move(self::REJECTED, [self::OPEN, self::WAITING, self::ACCEPTED]);
        $this->resolutionNote = $reason;
    }

    public function resolve(string $resolution): void
    {
        if (!in_array($resolution, self::RESOLUTIONS, true)) {
            throw new \InvalidArgumentException('Unknown claim resolution');
        }
        $this->move(self::RESOLVED, [self::ACCEPTED]);
        $this->resolution = $resolution;
        $this->resolvedAt = new \DateTimeImmutable();
    }

    /** The customer lodged the case on or before the last day of the window; both are Prague calendar dates. */
    public function isOnTime(): bool
    {
        return $this->contactedOn <= $this->windowEnd->setTime(0, 0);
    }

    /** The settle deadline has passed and the case is still not closed. */
    public function isOverdue(\DateTimeImmutable $today): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true) && $this->dueAt !== null && $today->setTime(0, 0) > $this->dueAt->setTime(0, 0);
    }

    public function isOpen(): bool { return in_array($this->status, self::OPEN_STATUSES, true); }

    public static function windowEnd(string $kind, \DateTimeImmutable $handoverDate): \DateTimeImmutable
    {
        return $kind === self::KIND_RETURN
            ? $handoverDate->modify(sprintf('+%d days', self::RETURN_WINDOW_DAYS))
            : $handoverDate->modify(sprintf('+%d months', self::WARRANTY_WINDOW_MONTHS));
    }

    public function getId(): Uuid { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getItem(): ?OrderItem { return $this->item; }
    public function getNumber(): string { return $this->number; }
    public function getKind(): string { return $this->kind; }
    public function getStatus(): string { return $this->status; }
    public function getNote(): ?string { return $this->note; }
    public function getRefundAmountMinor(): ?int { return $this->refundAmountMinor; }
    public function getResolution(): ?string { return $this->resolution; }
    public function getResolutionNote(): ?string { return $this->resolutionNote; }
    public function getOpenedAt(): \DateTimeImmutable { return $this->openedAt; }
    public function getOpenedBy(): string { return $this->openedBy; }
    public function getContactedOn(): \DateTimeImmutable { return $this->contactedOn; }
    public function getHandoverDate(): \DateTimeImmutable { return $this->handoverDate; }
    public function getWindowEnd(): \DateTimeImmutable { return $this->windowEnd; }
    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }

    /** @param list<string> $from */
    private function move(string $to, array $from): void
    {
        if (!in_array($this->status, $from, true)) {
            throw new \DomainException(sprintf('A claim that is %s cannot become %s', $this->status, $to));
        }
        $this->status = $to;
    }
}
