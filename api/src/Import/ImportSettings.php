<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportSupplierSetting;
use App\Entity\SupplierOffer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import defaults set once per supplier (spec: delivery_cost is a supplier-level value, not per row):
 * the inbound shipping and the standing feed URL the scheduled refresh reads (D03.4).
 * A supplier without a row reads as zero inbound shipping and no feed.
 */
final class ImportSettings
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return list<array{supplier: string, inboundShippingMinor: int, feedUrl: ?string}> */
    public function list(): array
    {
        $stored = [];
        foreach ($this->em->getRepository(ImportSupplierSetting::class)->findAll() as $setting) {
            $stored[$setting->getSupplier()] = $setting;
        }
        $list = [];
        foreach (SupplierOffer::SUPPLIERS as $supplier) {
            $setting = $stored[$supplier] ?? null;
            $list[] = [
                'supplier' => $supplier,
                'inboundShippingMinor' => $setting?->getInboundShippingMinor() ?? 0,
                'feedUrl' => $setting?->getFeedUrl(),
            ];
        }

        return $list;
    }

    public function inboundShippingMinor(string $supplier): int
    {
        $setting = $this->em->find(ImportSupplierSetting::class, $supplier);

        return $setting?->getInboundShippingMinor() ?? 0;
    }

    public function feedUrl(string $supplier): ?string
    {
        $setting = $this->em->find(ImportSupplierSetting::class, $supplier);

        return $setting?->getFeedUrl();
    }

    /** @return array{supplier: string, inboundShippingMinor: int, feedUrl: ?string} */
    public function save(string $supplier, int $inboundShippingMinor, ?string $feedUrl = null): array
    {
        if (!in_array($supplier, SupplierOffer::SUPPLIERS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown supplier "%s"', $supplier));
        }
        if ($inboundShippingMinor < 0) {
            throw new \InvalidArgumentException('Inbound shipping cannot be negative');
        }
        $setting = $this->em->find(ImportSupplierSetting::class, $supplier) ?? new ImportSupplierSetting($supplier, $inboundShippingMinor);
        $setting->setInboundShippingMinor($inboundShippingMinor);
        if (func_num_args() >= 3) {
            $setting->setFeedUrl($feedUrl);
        }
        $this->em->persist($setting);
        $this->em->flush();

        return ['supplier' => $supplier, 'inboundShippingMinor' => $setting->getInboundShippingMinor(), 'feedUrl' => $setting->getFeedUrl()];
    }
}
