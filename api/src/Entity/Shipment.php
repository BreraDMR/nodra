<?php

declare(strict_types=1);

namespace App\Entity;

use App\Order\ShipmentStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One delivery or pickup of some lines of an order. Its fee is fixed at checkout and never raised later. */
#[ORM\Entity]
#[ORM\Table(name: 'shipment')]
#[ORM\Index(columns: ['order_id', 'position'], name: 'idx_shipment_order')]
class Shipment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShopOrder $order;

    /** 1, 2, ... in the order */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(length: 20)]
    private string $method;

    #[ORM\Column]
    private int $feeMinor;

    #[ORM\Column(length: 12)]
    private string $status = ShipmentStatus::PLANNED;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledFrom = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledTo = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $handedOverAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ShopOrder $order, int $position, string $method, int $feeMinor)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->position = $position;
        $this->method = $method;
        $this->feeMinor = $feeMinor;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getPosition(): int { return $this->position; }
    public function getMethod(): string { return $this->method; }
    public function getFeeMinor(): int { return $this->feeMinor; }
    public function getStatus(): string { return $this->status; }
    public function getScheduledFrom(): ?\DateTimeImmutable { return $this->scheduledFrom; }
    public function getScheduledTo(): ?\DateTimeImmutable { return $this->scheduledTo; }
    public function getHandedOverAt(): ?\DateTimeImmutable { return $this->handedOverAt; }

    /** Still counts towards the order total. */
    public function isCharged(): bool { return $this->status !== ShipmentStatus::CANCELLED; }

    public function schedule(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($to <= $from) {
            throw new \InvalidArgumentException('The window must end after it starts');
        }
        $this->move(ShipmentStatus::SCHEDULED, [ShipmentStatus::PLANNED, ShipmentStatus::SCHEDULED, ShipmentStatus::REFUSED]);
        $this->scheduledFrom = $from;
        $this->scheduledTo = $to;
    }

    public function handOver(): void
    {
        $this->move(ShipmentStatus::HANDED_OVER, [ShipmentStatus::SCHEDULED]);
        $this->handedOverAt = new \DateTimeImmutable();
    }

    public function refuse(): void { $this->move(ShipmentStatus::REFUSED, [ShipmentStatus::SCHEDULED]); }

    public function cancel(): void { $this->move(ShipmentStatus::CANCELLED, [ShipmentStatus::PLANNED, ShipmentStatus::SCHEDULED, ShipmentStatus::REFUSED]); }

    /** @param list<string> $from */
    private function move(string $to, array $from): void
    {
        if (!in_array($this->status, $from, true)) {
            throw new \DomainException(sprintf('A shipment that is %s cannot become %s', $this->status, $to));
        }
        $this->status = $to;
    }
}
