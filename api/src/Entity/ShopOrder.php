<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'shop_order')]
#[ORM\Index(columns: ['created_at'], name: 'idx_order_created')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_order_status')]
class ShopOrder
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20, unique: true)]
    private string $reference;

    #[ORM\Column(length: 80, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64)]
    private string $lookupToken;

    #[ORM\Column(length: 16)]
    private string $status = 'placed';

    #[ORM\Column(length: 2)]
    private string $locale;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 160)]
    private string $customerName;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 24)]
    private string $postalCode;

    #[ORM\Column]
    private int $subtotalMinor;

    #[ORM\Column]
    private int $shippingMinor;

    #[ORM\Column]
    private int $totalMinor;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $idempotencyKey, string $locale, string $currency, string $customerName, string $email, string $country, string $address, string $postalCode, int $subtotalMinor, int $shippingMinor)
    {
        $this->id = Uuid::v7();
        $this->reference = 'ND-'.strtoupper(bin2hex(random_bytes(4)));
        $this->lookupToken = bin2hex(random_bytes(32));
        $this->idempotencyKey = $idempotencyKey;
        $this->locale = $locale;
        $this->currency = $currency;
        $this->customerName = $customerName;
        $this->email = $email;
        $this->country = $country;
        $this->address = $address;
        $this->postalCode = $postalCode;
        $this->subtotalMinor = $subtotalMinor;
        $this->shippingMinor = $shippingMinor;
        $this->totalMinor = $subtotalMinor + $shippingMinor;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getLookupToken(): string { return $this->lookupToken; }
    public function getStatus(): string { return $this->status; }
    public function getLocale(): string { return $this->locale; }
    public function getCurrency(): string { return $this->currency; }
    public function getCustomerName(): string { return $this->customerName; }
    public function getEmail(): string { return $this->email; }
}
