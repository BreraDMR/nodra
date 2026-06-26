<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportSupplierSetting;
use App\Entity\SupplierOffer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import defaults set once per supplier (spec: delivery_cost is a supplier-level value, not per row).
 * A supplier without a row reads as zero inbound shipping.
 */
final class ImportSettings
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return list<array{supplier: string, inboundShippingMinor: int}> */
    public function list(): array
    {
        $stored = [];
        foreach ($this->em->getRepository(ImportSupplierSetting::class)->findAll() as $setting) {
            $stored[$setting->getSupplier()] = $setting->getInboundShippingMinor();
        }
        $list = [];
        foreach (SupplierOffer::SUPPLIERS as $supplier) {
            $list[] = ['supplier' => $supplier, 'inboundShippingMinor' => $stored[$supplier] ?? 0];
        }

        return $list;
    }

    public function inboundShippingMinor(string $supplier): int
    {
        $setting = $this->em->find(ImportSupplierSetting::class, $supplier);

        return $setting?->getInboundShippingMinor() ?? 0;
    }

    /** @return array{supplier: string, inboundShippingMinor: int} */
    public function save(string $supplier, int $inboundShippingMinor): array
    {
        if (!in_array($supplier, SupplierOffer::SUPPLIERS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown supplier "%s"', $supplier));
        }
        if ($inboundShippingMinor < 0) {
            throw new \InvalidArgumentException('Inbound shipping cannot be negative');
        }
        $setting = $this->em->find(ImportSupplierSetting::class, $supplier) ?? new ImportSupplierSetting($supplier, $inboundShippingMinor);
        $setting->setInboundShippingMinor($inboundShippingMinor);
        $this->em->persist($setting);
        $this->em->flush();

        return ['supplier' => $supplier, 'inboundShippingMinor' => $setting->getInboundShippingMinor()];
    }
}
