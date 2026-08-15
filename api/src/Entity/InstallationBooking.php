<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An evening installation booked on an order (D08.1–D08.2): NODRA installs the bought parts at the customer's
 * place. The booking never touches the order — lines, stock and the ledger stay with the order actions.
 * The preliminary window is what was agreed with the customer; the work window is what confirm fixed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'installation_booking')]
#[ORM\Index(columns: ['status', 'planned_from'], name: 'idx_installation_status')]
#[ORM\Index(columns: ['order_id'], name: 'idx_installation_order')]
class InstallationBooking
{
    public const PLANNED = 'planned';
    public const CONFIRMED = 'confirmed';
    public const DONE = 'done';
    /** closed, terminal */
    public const CANCELLED = 'cancelled';
    public const STATUSES = [self::PLANNED, self::CONFIRMED, self::DONE, self::CANCELLED];
    /** bookings that hold an evening slot */
    public const OPEN_STATUSES = [self::PLANNED, self::CONFIRMED];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShopOrder $order;

    #[ORM\Column(length: 16)]
    private string $status = self::PLANNED;

    /** the window agreed with the customer */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $plannedFrom;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $plannedTo;

    /** the confirmed actual work; set at confirm */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $workFrom = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $workTo = null;

    /** codes of app.installation.works; empty while the owner has not filled the list (D00.4) */
    #[ORM\Column(type: Types::JSON)]
    private array $works = [];

    /** sum of the chosen works' prices; null while any chosen work is unpriced */
    #[ORM\Column(nullable: true)]
    private ?int $priceMinor = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note;

    /** what was checked with the customer before confirmation (D08.2) */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $compatibilityNote = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $resultNote = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $cancelledReason = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 180)]
    private string $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /**
     * @param list<string> $works
     */
    public function __construct(ShopOrder $order, \DateTimeImmutable $from, \DateTimeImmutable $to, array $works, ?int $priceMinor, ?string $note, string $createdBy)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->plannedFrom = $from;
        $this->plannedTo = $to;
        $this->works = $works;
        $this->priceMinor = $priceMinor;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
        $this->createdBy = $createdBy;
    }

    /** Acknowledge the case: compatibility and conditions checked, the actual work window fixed. */
    public function confirm(\DateTimeImmutable $workFrom, \DateTimeImmutable $workTo, string $compatibilityNote): void
    {
        $this->move(self::CONFIRMED, [self::PLANNED]);
        $this->workFrom = $workFrom;
        $this->workTo = $workTo;
        $this->compatibilityNote = $compatibilityNote;
        $this->confirmedAt = new \DateTimeImmutable();
    }

    public function complete(string $resultNote): void
    {
        $this->move(self::DONE, [self::CONFIRMED]);
        $this->resultNote = $resultNote;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function cancel(string $reason): void
    {
        $this->move(self::CANCELLED, [self::PLANNED, self::CONFIRMED]);
        $this->cancelledReason = $reason;
        $this->cancelledAt = new \DateTimeImmutable();
    }

    public function reschedulePlanned(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($this->status !== self::PLANNED) {
            throw new \DomainException('Only a planned booking moves its preliminary window');
        }
        $this->plannedFrom = $from;
        $this->plannedTo = $to;
    }

    public function rescheduleWork(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($this->status !== self::CONFIRMED) {
            throw new \DomainException('Only a confirmed booking moves its work window');
        }
        $this->workFrom = $from;
        $this->workTo = $to;
    }

    /** The window the capacity rules work with: the work window once confirmed, the preliminary one before. */
    public function windowFrom(): \DateTimeImmutable { return $this->workFrom ?? $this->plannedFrom; }
    public function windowTo(): \DateTimeImmutable { return $this->workTo ?? $this->plannedTo; }

    public function isOpen(): bool { return in_array($this->status, self::OPEN_STATUSES, true); }

    /** @param string $to */
    private function move(string $to, array $from): void
    {
        if (!in_array($this->status, $from, true)) {
            throw new \DomainException(sprintf('A booking that is %s cannot become %s', $this->status, $to));
        }
        $this->status = $to;
    }

    public function getId(): Uuid { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getStatus(): string { return $this->status; }
    public function getPlannedFrom(): \DateTimeImmutable { return $this->plannedFrom; }
    public function getPlannedTo(): \DateTimeImmutable { return $this->plannedTo; }
    public function getWorkFrom(): ?\DateTimeImmutable { return $this->workFrom; }
    public function getWorkTo(): ?\DateTimeImmutable { return $this->workTo; }
    /** @return list<string> */
    public function getWorks(): array { return $this->works; }
    public function getPriceMinor(): ?int { return $this->priceMinor; }
    public function getNote(): ?string { return $this->note; }
    public function getCompatibilityNote(): ?string { return $this->compatibilityNote; }
    public function getResultNote(): ?string { return $this->resultNote; }
    public function getCancelledReason(): ?string { return $this->cancelledReason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
}
