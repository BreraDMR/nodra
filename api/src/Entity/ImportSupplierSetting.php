<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Import defaults set once per supplier, not per feed row. Missing row means zero.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_supplier_setting')]
class ImportSupplierSetting
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $supplier;

    /** Default inbound shipping of the supplier's feed, in its currency. */
    #[ORM\Column(options: ['default' => 0])]
    private int $inboundShippingMinor = 0;

    public function __construct(string $supplier, int $inboundShippingMinor)
    {
        if (!in_array($supplier, SupplierOffer::SUPPLIERS, true)) {
            throw new \InvalidArgumentException('Unknown supplier');
        }
        if ($inboundShippingMinor < 0) {
            throw new \InvalidArgumentException('Inbound shipping cannot be negative');
        }
        $this->supplier = $supplier;
        $this->inboundShippingMinor = $inboundShippingMinor;
    }

    public function getSupplier(): string { return $this->supplier; }
    public function getInboundShippingMinor(): int { return $this->inboundShippingMinor; }

    public function setInboundShippingMinor(int $inboundShippingMinor): void
    {
        if ($inboundShippingMinor < 0) {
            throw new \InvalidArgumentException('Inbound shipping cannot be negative');
        }
        $this->inboundShippingMinor = $inboundShippingMinor;
    }
}
