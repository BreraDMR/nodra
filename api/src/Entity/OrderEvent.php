<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The order journal. Written by every transition, money entry, terms change and customer agreement, never edited. */
#[ORM\Entity]
#[ORM\Table(name: 'order_event')]
#[ORM\Index(columns: ['order_id', 'created_at'], name: 'idx_order_event_order')]
class OrderEvent
{
    public const CUSTOMER = 'customer';
    public const SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShopOrder $order;

    #[ORM\Column(length: 40)]
    private string $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $data;

    /** customer, system or the admin email */
    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ShopOrder $order, string $type, array $data, string $actor)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->type = $type;
        $this->data = $data;
        $this->actor = $actor;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getType(): string { return $this->type; }
    public function getData(): array { return $this->data; }
    public function getActor(): string { return $this->actor; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
