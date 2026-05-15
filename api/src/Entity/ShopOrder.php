<?php

declare(strict_types=1);

namespace App\Entity;

use App\Order\OrderStatus;
use App\Order\PaymentStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'shop_order')]
#[ORM\Index(columns: ['created_at'], name: 'idx_order_created')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_order_status')]
#[ORM\Index(columns: ['payment_status', 'created_at'], name: 'idx_order_payment_status')]
class ShopOrder
{
    public const FULFILMENTS = ['together', 'split'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20, unique: true)]
    private string $reference;

    #[ORM\Column(length: 80, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64)]
    private string $requestHash;

    #[ORM\Column(length: 64)]
    private string $lookupToken;

    #[ORM\Column(length: 16)]
    private string $status = OrderStatus::REQUESTED;

    #[ORM\Column(length: 20, options: ['default' => 'unpaid'])]
    private string $paymentStatus = PaymentStatus::UNPAID;

    #[ORM\Column(length: 8, options: ['default' => 'together'])]
    private string $fulfilment;

    #[ORM\Column(length: 2)]
    private string $locale;

    #[ORM\Column(length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(length: 160)]
    private string $customerName;

    #[ORM\Column(length: 180)]
    private string $email;

    // null on orders placed before D04
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $contactChannel;

    #[ORM\ManyToOne(targetEntity: CustomerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CustomerAccount $account = null;

    #[ORM\Column(length: 2)]
    private string $country = 'CZ';

    // address, city and postal code stay empty for pickup
    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 120, options: ['default' => ''])]
    private string $city;

    #[ORM\Column(length: 24)]
    private string $postalCode;

    #[ORM\Column(length: 120, options: ['default' => ''])]
    private string $district = '';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $deliveryNote;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $privacyConsentedAt;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $privacyTextVersion;

    #[ORM\Column(options: ['default' => false])]
    private bool $marketingConsent;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $marketingConsentedAt;

    #[ORM\Column]
    private int $subtotalMinor = 0;

    #[ORM\Column]
    private int $shippingMinor = 0;

    #[ORM\Column]
    private int $totalMinor = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array{name: string, email: string, phone: string, contactChannel: string, address: string, city: string, postalCode: string, district: string, deliveryNote: ?string} $customer
     */
    public function __construct(string $idempotencyKey, string $requestHash, string $locale, array $customer, string $fulfilment, string $privacyTextVersion, bool $marketingConsent)
    {
        $this->id = Uuid::v7();
        $this->reference = 'ND-'.strtoupper(bin2hex(random_bytes(4)));
        $this->lookupToken = bin2hex(random_bytes(32));
        $this->idempotencyKey = $idempotencyKey;
        $this->requestHash = $requestHash;
        $this->locale = $locale;
        $this->customerName = $customer['name'];
        $this->email = $customer['email'];
        $this->phone = $customer['phone'];
        $this->contactChannel = $customer['contactChannel'];
        $this->address = $customer['address'];
        $this->city = $customer['city'];
        $this->postalCode = $customer['postalCode'];
        $this->district = $customer['district'];
        $this->deliveryNote = $customer['deliveryNote'];
        $this->fulfilment = $fulfilment;
        $this->createdAt = new \DateTimeImmutable();
        $this->privacyConsentedAt = $this->createdAt;
        $this->privacyTextVersion = $privacyTextVersion;
        $this->marketingConsent = $marketingConsent;
        $this->marketingConsentedAt = $marketingConsent ? $this->createdAt : null;
    }

    public function getId(): Uuid { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getRequestHash(): string { return $this->requestHash; }
    public function getLookupToken(): string { return $this->lookupToken; }
    public function getStatus(): string { return $this->status; }
    public function getPaymentStatus(): string { return $this->paymentStatus; }
    public function getFulfilment(): string { return $this->fulfilment; }
    public function getLocale(): string { return $this->locale; }
    public function getCurrency(): string { return $this->currency; }
    public function getCustomerName(): string { return $this->customerName; }
    public function getEmail(): string { return $this->email; }
    public function getPhone(): ?string { return $this->phone; }
    public function getContactChannel(): ?string { return $this->contactChannel; }
    public function getAccount(): ?CustomerAccount { return $this->account; }
    public function getCountry(): string { return $this->country; }
    public function getAddress(): string { return $this->address; }
    public function getCity(): string { return $this->city; }
    public function getPostalCode(): string { return $this->postalCode; }
    public function getDistrict(): string { return $this->district; }
    public function getDeliveryNote(): ?string { return $this->deliveryNote; }
    public function getPrivacyConsentedAt(): ?\DateTimeImmutable { return $this->privacyConsentedAt; }
    public function getPrivacyTextVersion(): ?string { return $this->privacyTextVersion; }
    public function hasMarketingConsent(): bool { return $this->marketingConsent; }
    public function getMarketingConsentedAt(): ?\DateTimeImmutable { return $this->marketingConsentedAt; }
    public function getSubtotalMinor(): int { return $this->subtotalMinor; }
    public function getShippingMinor(): int { return $this->shippingMinor; }
    public function getTotalMinor(): int { return $this->totalMinor; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function assignAccount(CustomerAccount $account): void { $this->account = $account; }

    public function setTotals(int $subtotalMinor, int $shippingMinor): void
    {
        $this->subtotalMinor = $subtotalMinor;
        $this->shippingMinor = $shippingMinor;
        $this->totalMinor = $subtotalMinor + $shippingMinor;
    }

    public function setPaymentStatus(string $status): void
    {
        if (!in_array($status, PaymentStatus::ALL, true)) {
            throw new \InvalidArgumentException('Unknown payment status');
        }
        $this->paymentStatus = $status;
    }

    public function confirm(): void { $this->move(OrderStatus::CONFIRMED, [OrderStatus::REQUESTED]); }

    /** Terms changed after the customer agreed: the order waits for a new agreement. */
    public function reopen(): void { $this->move(OrderStatus::REQUESTED, [OrderStatus::CONFIRMED]); }

    public function complete(): void { $this->move(OrderStatus::COMPLETED, [OrderStatus::CONFIRMED]); }

    public function cancel(): void { $this->move(OrderStatus::CANCELLED, [OrderStatus::REQUESTED, OrderStatus::CONFIRMED]); }

    /** @param list<string> $from */
    private function move(string $to, array $from): void
    {
        if (!in_array($this->status, $from, true)) {
            throw new \DomainException(sprintf('An order that is %s cannot become %s', $this->status, $to));
        }
        $this->status = $to;
    }
}
