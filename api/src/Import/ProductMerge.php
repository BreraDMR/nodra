<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportFieldOrigin;
use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The manual merge of two products (D03.3): everything of the source moves to the target and the
 * source ends archived — nothing is deleted, no offer is lost. A source variant with exactly one
 * counterpart in the target (EAN, else brand + MPN, else colour/size) has its offers re-pointed and
 * its unused identity moved over; a variant without a counterpart moves as a whole.
 */
final class ProductMerge
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Security $security,
    ) {}

    /** Null when either product doesn't exist; a refused merge is a DomainException the controller maps to 409. */
    public function merge(string $sourceId, string $targetId): ?array
    {
        $source = $this->em->find(Product::class, $sourceId);
        $target = $this->em->find(Product::class, $targetId);
        if ($source === null || $target === null) {
            return null;
        }
        if ($source->getId()->equals($target->getId())) {
            throw new \DomainException('A product cannot be merged into itself');
        }
        if ($target->getStatus() === 'archived') {
            throw new \DomainException('The target product is archived; unarchive it first');
        }

        $now = $this->clock->now();
        $run = new ImportRun(ImportRun::SOURCE_MERGE, $now);
        $run->performedBy($this->adminEmail());

        $merged = [];
        $moved = [];
        $offersRepointed = 0;
        $identityCopies = 0;
        foreach ($this->variantsOf($source) as $variant) {
            $counterpart = $this->counterpart($variant, $target);
            if ($counterpart !== null) {
                $offersRepointed += $this->mergeVariantInto($variant, $counterpart, $run, $now, $identityCopies);
                $merged[] = ['sku' => $variant->getSku(), 'targetSku' => $counterpart->getSku(), 'variantId' => $variant->getId()->toRfc4122()];
            } else {
                $offersRepointed += $this->moveVariant($variant, $target, $run, $now);
                $moved[] = ['sku' => $variant->getSku(), 'variantId' => $variant->getId()->toRfc4122()];
            }
        }
        foreach ($this->offersOf($source) as $offer) {
            if ($offer->getVariant() === null) {
                $offer->moveTo($target, null);
                $offersRepointed++;
                $this->em->persist(ImportFieldOrigin::fromRun($run, ImportFieldOrigin::ENTITY_OFFER, $offer->getId(), 'product', $now));
            }
        }
        $source->update($source->getSlug(), $source->getCategory(), $source->getCopy(), $source->getImage(), $source->getImages(), $source->getBadge(), $source->getFeaturedRank(), 'archived');

        $counts = [
            'totalRows' => count($merged) + count($moved),
            'newProducts' => 0, 'updates' => 0, 'conflicts' => 0, 'unknowns' => 0, 'errors' => 0,
            'rowsWithCost' => 0, 'suggestionsMarginTooLow' => 0,
            'variantsMerged' => count($merged), 'variantsMoved' => count($moved),
            'offersRepointed' => $offersRepointed, 'identityCopies' => $identityCopies,
            'archived' => 1,
        ];
        $report = [
            'source' => ImportRun::SOURCE_MERGE, 'fileName' => null, 'sha256' => null,
            'sourceProductId' => $sourceId, 'targetProductId' => $targetId,
            'totalRows' => $counts['totalRows'], 'counts' => $counts,
            'newProducts' => [], 'updates' => [], 'conflicts' => [], 'unknowns' => [], 'errors' => [],
            'cost' => ['rows' => [], 'marginTooLow' => 0],
            'decisions' => ['merged' => $merged, 'moved' => $moved],
        ];
        $run->finish(ImportRun::STATUS_APPLIED, $counts, $report, [], $now);
        $this->em->persist($run);
        $this->em->flush();

        return [
            'runId' => $run->getId()->toRfc4122(),
            'sourceProductId' => $sourceId,
            'targetProductId' => $targetId,
            'variantsMerged' => $counts['variantsMerged'],
            'variantsMoved' => $counts['variantsMoved'],
            'offersRepointed' => $counts['offersRepointed'],
            'identityCopies' => $counts['identityCopies'],
        ];
    }

    /** The one variant of the target that is the same physical item, or null when there is none or several. */
    private function counterpart(ProductVariant $variant, Product $target): ?ProductVariant
    {
        $candidates = $this->byEan($variant, $target);
        if ($candidates === []) {
            $candidates = $this->byBrandAndMpn($variant, $target);
        }
        if ($candidates === []) {
            $candidates = $this->byCharacteristics($variant, $target);
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** @return list<ProductVariant> */
    private function byEan(ProductVariant $variant, Product $target): array
    {
        if ($variant->getEan() === null) {
            return [];
        }

        return array_values(array_filter(
            $this->variantsOf($target),
            static fn (ProductVariant $candidate): bool => $candidate->getEan() === $variant->getEan(),
        ));
    }

    /** @return list<ProductVariant> */
    private function byBrandAndMpn(ProductVariant $variant, Product $target): array
    {
        $brand = $variant->getProduct()->getBrand();
        if ($variant->getMpn() === null || $brand === null || $target->getBrand() === null) {
            return [];
        }
        if (self::norm($brand) !== self::norm($target->getBrand())) {
            return [];
        }

        return array_values(array_filter(
            $this->variantsOf($target),
            static fn (ProductVariant $candidate): bool => $candidate->getMpn() !== null
                && self::norm($candidate->getMpn()) === self::norm($variant->getMpn()),
        ));
    }

    /**
     * Colour/size decide, but only where both sides carry the key, and every carried key must agree.
     * @return list<ProductVariant>
     */
    private function byCharacteristics(ProductVariant $variant, Product $target): array
    {
        $matches = [];
        foreach ($this->variantsOf($target) as $candidate) {
            $comparable = false;
            $agrees = true;
            foreach (['colour', 'size'] as $key) {
                $own = $this->effectiveAttribute($variant, $key);
                $other = $this->effectiveAttribute($candidate, $key);
                if ($own === null || $other === null) {
                    continue;
                }
                $comparable = true;
                if (self::norm($own) !== self::norm($other)) {
                    $agrees = false;
                    break;
                }
            }
            if ($comparable && $agrees) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }

    /** Offers go to the counterpart, the identity the counterpart lacks moves over, the twin is deactivated. */
    private function mergeVariantInto(ProductVariant $variant, ProductVariant $target, ImportRun $run, \DateTimeImmutable $now, int &$identityCopies): int
    {
        $repointed = 0;
        foreach ($this->offersOfVariant($variant) as $offer) {
            $offer->moveTo($target->getProduct(), $target);
            $this->em->persist(ImportFieldOrigin::fromRun($run, ImportFieldOrigin::ENTITY_OFFER, $offer->getId(), 'variant', $now));
            $repointed++;
        }
        $movedFields = [];
        $ean = $target->getEan() ?? $variant->getEan();
        if ($target->getEan() === null && $variant->getEan() !== null) {
            $movedFields[] = 'ean';
        }
        $mpn = $target->getMpn() ?? $variant->getMpn();
        if ($target->getMpn() === null && $variant->getMpn() !== null) {
            $movedFields[] = 'mpn';
        }
        $attributes = $target->getAttributes();
        foreach ($variant->getAttributes() as $key => $value) {
            if (!isset($attributes[$key])) {
                $attributes[$key] = $value;
                $movedFields[] = 'attributes';
            }
        }
        if ($movedFields !== []) {
            $target->identify($mpn, $ean, $attributes);
            $identityCopies++;
            foreach (array_unique($movedFields) as $field) {
                $this->em->persist(ImportFieldOrigin::fromRun($run, ImportFieldOrigin::ENTITY_VARIANT, $target->getId(), $field, $now));
            }
        }
        // the identity now lives on the counterpart; the twin keeps nothing that could collide with it
        if ($variant->isActive()) {
            $variant->deactivate();
        }
        $variant->identify(null, null, []);

        return $repointed;
    }

    private function moveVariant(ProductVariant $variant, Product $target, ImportRun $run, \DateTimeImmutable $now): int
    {
        $variant->moveTo($target);
        $moved = 0;
        foreach ($this->offersOfVariant($variant) as $offer) {
            $offer->moveTo($target, $variant);
            $this->em->persist(ImportFieldOrigin::fromRun($run, ImportFieldOrigin::ENTITY_OFFER, $offer->getId(), 'product', $now));
            $moved++;
        }

        return $moved;
    }

    /** @return list<ProductVariant> */
    private function variantsOf(Product $product): array
    {
        return $this->em->getRepository(ProductVariant::class)->findBy(['product' => $product], ['sku' => 'ASC']);
    }

    /** @return list<SupplierOffer> */
    private function offersOf(Product $product): array
    {
        return $this->em->getRepository(SupplierOffer::class)->findBy(['product' => $product]);
    }

    /** @return list<SupplierOffer> */
    private function offersOfVariant(ProductVariant $variant): array
    {
        return $this->em->getRepository(SupplierOffer::class)->findBy(['variant' => $variant]);
    }

    private function effectiveAttribute(ProductVariant $variant, string $key): ?string
    {
        $current = $variant->getAttributes()[$key] ?? $variant->getProduct()->getAttributes()[$key] ?? null;
        if ($current === null && $key === 'colour') {
            $current = $variant->getColor();
        }
        if ($current === null && $key === 'size') {
            $current = $variant->getSize();
        }

        return $current === null ? null : (string) $current;
    }

    private static function norm(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function adminEmail(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? 'system';
    }
}
