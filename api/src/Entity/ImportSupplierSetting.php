<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Import defaults set once per supplier, not per feed row: the inbound shipping and the standing
 * feed URL the scheduled refresh reads (D03.4). A missing row means zero shipping and no feed.
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

    /** Where the scheduled refresh reads the feed from: an http(s) URL or a local path. */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $feedUrl = null;

    public function __construct(string $supplier, int $inboundShippingMinor)
    {
        if (!in_array($supplier, SupplierOffer::SUPPLIERS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown supplier "%s"', $supplier));
        }
        if ($inboundShippingMinor < 0) {
            throw new \InvalidArgumentException('Inbound shipping cannot be negative');
        }
        $this->supplier = $supplier;
        $this->inboundShippingMinor = $inboundShippingMinor;
    }

    public function getSupplier(): string { return $this->supplier; }
    public function getInboundShippingMinor(): int { return $this->inboundShippingMinor; }
    public function getFeedUrl(): ?string { return $this->feedUrl; }

    public function setInboundShippingMinor(int $inboundShippingMinor): void
    {
        if ($inboundShippingMinor < 0) {
            throw new \InvalidArgumentException('Inbound shipping cannot be negative');
        }
        $this->inboundShippingMinor = $inboundShippingMinor;
    }

    public function setFeedUrl(?string $feedUrl): void
    {
        $feedUrl = $feedUrl === null ? null : trim($feedUrl);
        if ($feedUrl === '') {
            $feedUrl = null;
        }
        if ($feedUrl !== null && !preg_match('#^(https?://|file://|/)#i', $feedUrl)) {
            throw new \InvalidArgumentException('The feed URL must be an http(s) URL or a local path');
        }
        $this->feedUrl = $feedUrl === null ? null : mb_substr($feedUrl, 0, 2048);
    }
}
