<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A feed row the admin tied to an existing variant by hand: the demo catalogue has no EANs or MPNs,
 * so a real feed can only reach those variants through this link. Keyed by supplier + supplier SKU,
 * it is remembered for every following run of that feed (D03.3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_feed_binding')]
#[ORM\UniqueConstraint(name: 'uniq_feed_binding_supplier_sku', columns: ['supplier', 'supplier_sku'])]
#[ORM\Index(columns: ['variant_id'], name: 'idx_feed_binding_variant')]
class ImportFeedBinding
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\Column(length: 32)]
    private string $supplier;

    #[ORM\Column(length: 120)]
    private string $supplierSku;

    #[ORM\Column(length: 180)]
    private string $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ProductVariant $variant, string $supplier, string $supplierSku, string $createdBy, \DateTimeImmutable $createdAt)
    {
        if (!in_array($supplier, SupplierOffer::SUPPLIERS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown supplier "%s"', $supplier));
        }
        $sku = trim($supplierSku);
        if ($sku === '') {
            throw new \InvalidArgumentException('The supplier SKU of a binding cannot be empty');
        }
        $this->id = Uuid::v7();
        $this->variant = $variant;
        $this->supplier = $supplier;
        $this->supplierSku = mb_substr($sku, 0, 120);
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getVariant(): ProductVariant { return $this->variant; }
    public function getSupplier(): string { return $this->supplier; }
    public function getSupplierSku(): string { return $this->supplierSku; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
