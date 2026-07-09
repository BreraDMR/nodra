<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class SupplierOfferAdminService
{
    public function __construct(private EntityManagerInterface $em, private \App\Import\OriginRecorder $origins) {}

    public function create(string $productId, SupplierOfferWriteRequest $input): ?array
    {
        $product = Uuid::isValid($productId) ? $this->em->find(Product::class, Uuid::fromString($productId)) : null;
        if ($product === null) {
            return null;
        }
        $offer = new SupplierOffer($product, $input->supplier, trim($input->url), trim($input->title), $input->currency, $input->priceMinor, $input->reportedQuantity, $this->checkedAt($input->checkedAt));
        $this->apply($offer, $input);
        $this->em->persist($offer);
        $this->flush();
        $this->origins->forAdmin(\App\Entity\ImportFieldOrigin::ENTITY_OFFER, $offer->getId(), ['url', 'title', 'price', 'stock', 'lead_time', 'seller', 'inbound_shipping', 'fx_rate', 'checked']);

        return ['id' => $offer->getId()->toRfc4122()];
    }

    public function update(string $id, SupplierOfferWriteRequest $input): ?array
    {
        $offer = Uuid::isValid($id) ? $this->em->find(SupplierOffer::class, Uuid::fromString($id)) : null;
        if ($offer === null) {
            return null;
        }
        $before = [
            'url' => $offer->getUrl(), 'title' => $offer->getTitle(), 'seller' => $offer->getSeller(),
            'priceMinor' => $offer->getPriceMinor(), 'reportedQuantity' => $offer->getReportedQuantity(),
            'checkedAt' => $offer->getCheckedAt(), 'leadMin' => $offer->getLeadTimeMinDays(), 'leadMax' => $offer->getLeadTimeMaxDays(),
            'inboundShippingMinor' => $offer->getInboundShippingMinor(), 'fxRateCzk' => $offer->getFxRateCzk(),
        ];
        $this->apply($offer, $input);
        $this->flush();
        $fields = [];
        if ($offer->getUrl() !== $before['url']) {
            $fields[] = 'url';
        }
        if ($offer->getTitle() !== $before['title']) {
            $fields[] = 'title';
        }
        if ($offer->getSeller() !== $before['seller']) {
            $fields[] = 'seller';
        }
        if ($offer->getPriceMinor() !== $before['priceMinor']) {
            $fields[] = 'price';
        }
        if ($offer->getReportedQuantity() !== $before['reportedQuantity']) {
            $fields[] = 'stock';
        }
        if ($offer->getCheckedAt() != $before['checkedAt']) {
            $fields[] = 'checked';
        }
        if ($offer->getLeadTimeMinDays() !== $before['leadMin'] || $offer->getLeadTimeMaxDays() !== $before['leadMax']) {
            $fields[] = 'lead_time';
        }
        if ($offer->getInboundShippingMinor() !== $before['inboundShippingMinor']) {
            $fields[] = 'inbound_shipping';
        }
        if ($offer->getFxRateCzk() !== $before['fxRateCzk']) {
            $fields[] = 'fx_rate';
        }
        $this->origins->forAdmin(\App\Entity\ImportFieldOrigin::ENTITY_OFFER, $offer->getId(), $fields);

        return ['id' => $id];
    }

    private function apply(SupplierOffer $offer, SupplierOfferWriteRequest $input): void
    {
        $variant = null;
        if ($input->variantId !== null && $input->variantId !== '') {
            $variant = $this->em->find(ProductVariant::class, Uuid::fromString($input->variantId)) ?? throw new \InvalidArgumentException('Variant not found');
        }
        $sql = 'SELECT 1 FROM supplier_offer WHERE url = :url AND id <> :id AND '.($variant === null ? 'variant_id IS NULL AND product_id = :owner' : 'variant_id = :owner');
        $owner = $variant === null ? $offer->getProduct()->getId()->toRfc4122() : $variant->getId()->toRfc4122();
        if ($this->em->getConnection()->fetchOne($sql, ['url' => trim($input->url), 'id' => $offer->getId()->toRfc4122(), 'owner' => $owner]) !== false) {
            throw new \DomainException('This listing is already recorded for the same product or variant');
        }
        $seller = trim((string) $input->seller);
        $offer->update(
            $input->supplier, trim($input->url), trim($input->title), $seller === '' ? null : $seller,
            $input->currency, $input->priceMinor, $input->reportedQuantity, $this->checkedAt($input->checkedAt),
            $input->leadTimeMinDays, $input->leadTimeMaxDays, $variant, $input->verificationStatus,
        );
        $offer->setCost($input->inboundShippingMinor, $input->fxRateCzk, $input->fxRateDate === null ? null : new \DateTimeImmutable($input->fxRateDate));
    }

    private function checkedAt(string $value): \DateTimeImmutable
    {
        try {
            $checkedAt = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException('checkedAt must be a date and time');
        }
        if ($checkedAt > new \DateTimeImmutable('+1 hour')) {
            throw new \InvalidArgumentException('checkedAt cannot be in the future');
        }

        return $checkedAt;
    }

    private function flush(): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('This listing is already recorded for the same product or variant');
        }
    }
}
