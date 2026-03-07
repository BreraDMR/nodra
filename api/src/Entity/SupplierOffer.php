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
#[ORM\UniqueConstraint(name: 'uniq_supplier_offer_product_url', columns: ['product_id', 'url'])]
class SupplierOffer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 32)]
    private string $supplier;

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
}
