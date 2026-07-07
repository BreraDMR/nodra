<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Category;
use App\Entity\ImportFieldOrigin;
use App\Entity\ImportRun;
use App\Entity\PriceChange;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SupplierOffer;
use App\Pricing\Money;
use App\Pricing\PriceHistory;
use App\Pricing\PricingSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The import run itself: preview parses, matches and journals without writing anything but the run,
 * apply executes the plan in one transaction. Rows are independent; nothing is ever deleted.
 */
final class ImportService
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private AwinCsvParser $parser,
        private ImportPlanner $planner,
        private EntityManagerInterface $em,
        private Connection $db,
        private PriceHistory $history,
        private PricingSettings $pricingSettings,
        private ImportSettings $settings,
        private ClockInterface $clock,
        private Security $security,
        #[Autowire(param: 'app.import.awin_supplier')] private string $feedSupplier,
    ) {}

    /** The report of the uploaded file; the only write is the run row itself. */
    public function preview(string $fileName, string $bytes): array
    {
        $now = $this->clock->now();
        $parsed = $this->parser->parse($bytes);
        $sha = hash('sha256', $bytes);
        $planned = $this->planner->plan($parsed, $this->feedSupplier, $now);

        $run = new ImportRun(ImportRun::SOURCE_AWIN, $now, $this->feedSupplier);
        $run->start($fileName, $sha, $parsed->totalRows);
        $run->performedBy($this->adminEmail());
        $report = $planned['report'] + ['runId' => $run->getId()->toRfc4122(), 'source' => ImportRun::SOURCE_AWIN, 'fileName' => $fileName, 'sha256' => $sha];
        $run->finish(ImportRun::STATUS_PREVIEWED, $report['counts'], $report, $parsed->errors, $now);
        $this->em->persist($run);
        $this->em->flush();

        return $report;
    }

    /**
     * Applies the previewed run from the same file, everything in one transaction. Null when the run
     * doesn't exist; ImportConflictException when the file changed or the run was already applied.
     *
     * @return array<string, mixed>
     */
    public function apply(string $runId, string $bytes): ?array
    {
        $run = Uuid::isValid($runId) ? $this->em->find(ImportRun::class, $runId) : null;
        if ($run === null) {
            return null;
        }
        if ($run->getStatus() === ImportRun::STATUS_APPLIED) {
            throw new ImportConflictException('already_applied', 'This import run was already applied');
        }
        if (!hash_equals((string) $run->getFileSha256(), hash('sha256', $bytes))) {
            throw new ImportConflictException('file_changed', 'The file changed since the preview; preview it again');
        }

        $now = $this->clock->now();
        $parsed = $this->parser->parse($bytes);
        $planned = $this->planner->plan($parsed, (string) $run->getSupplier(), $now);
        $entries = $this->serializePlan($planned['plan']);

        $written = ['products' => 0, 'variants' => 0, 'offers' => 0, 'offerUpdates' => 0, 'rrpWrites' => 0];
        $this->em->wrapInTransaction(function () use ($entries, $run, $parsed, $planned, $now, &$written): void {
            $written = $this->executeBatchPlan($entries, $run, $now);
            $report = $planned['report'] + ['runId' => $run->getId()->toRfc4122(), 'source' => ImportRun::SOURCE_AWIN, 'fileName' => $run->getFileName(), 'sha256' => $run->getFileSha256()];
            $run->finish(ImportRun::STATUS_APPLIED, $report['counts'], $report, $parsed->errors, $now);
            $this->em->flush();
        });

        return [
            'runId' => $run->getId()->toRfc4122(),
            'written' => $written,
            // new variants always start at 0: the feed's price is the supplier's, the shop's price is the admin's
            'draftsWithoutPrice' => $written['variants'],
            'report' => $run->getReport() ?? [],
        ];
    }

    /**
     * The plan as plain arrays, ordered by row number, one entry per written variant — the shape the
     * D03.4 batches store so a failed batch can be replayed without the feed file.
     *
     * @return list<array<string, mixed>>
     */
    public function serializePlan(ImportPlan $plan): array
    {
        $entries = [];
        foreach ($plan->newProducts as $product) {
            $first = true;
            foreach ($product->variants as $variant) {
                $entries[] = [
                    'kind' => 'new',
                    // the product rides on its first variant entry; writeNewProductVariant finds it by slug
                    'product' => $first ? [
                        'slug' => $product->slug,
                        'brand' => $product->brand,
                        'name' => $product->name,
                        'description' => $product->description,
                        'categoryRow' => ['id' => $product->categoryRow['id'], 'slug' => $product->categoryRow['slug']],
                        'sourceImages' => $product->sourceImages,
                    ] : null,
                    'variant' => [
                        'row' => $this->rowToArray($variant->row),
                        'ean' => $variant->ean,
                        'mpn' => $variant->mpn,
                        'label' => $variant->label,
                        'attributes' => $variant->attributes,
                        'leadTimeMinDays' => $variant->leadTimeMinDays,
                        'leadTimeMaxDays' => $variant->leadTimeMaxDays,
                    ],
                    'rowNo' => $variant->row->rowNumber,
                ];
                $first = false;
            }
        }
        foreach ($plan->updates as $update) {
            $entries[] = [
                'kind' => 'update',
                'variantId' => $update->variant->getId()->toRfc4122(),
                'offerId' => $update->existingOffer?->getId()->toRfc4122(),
                'rrp' => $update->rrp,
                'leadTimeMinDays' => $update->leadTimeMinDays,
                'leadTimeMaxDays' => $update->leadTimeMaxDays,
                'changes' => $update->changes,
                'row' => $this->rowToArray($update->row),
                'rowNo' => $update->row->rowNumber,
            ];
        }
        usort($entries, static fn (array $a, array $b): int => $a['rowNo'] <=> $b['rowNo']);

        return $entries;
    }

    /**
     * Writes the entries of one batch: new drafts and offer updates, rows independent. The caller owns
     * the transaction — apply wraps the whole run, a feed batch wraps one batch.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return array{products: int, variants: int, offers: int, offerUpdates: int, rrpWrites: int}
     */
    public function executeBatchPlan(array $entries, ImportRun $run, \DateTimeImmutable $now): array
    {
        $written = ['products' => 0, 'variants' => 0, 'offers' => 0, 'offerUpdates' => 0, 'rrpWrites' => 0];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'new') {
                $product = new PlannedNewProduct(
                    $entry['product']['slug'],
                    $entry['product']['brand'],
                    $entry['product']['name'],
                    $entry['product']['description'],
                    $entry['product']['categoryRow'],
                    $entry['product']['sourceImages'],
                    [],
                );
                $variant = $this->newVariantFromArray($entry['variant']);
                $writtenVariant = $this->writeNewProductVariant($product, $variant, $run, $now);
                $this->writeOffer($writtenVariant->getProduct(), $variant->row, $writtenVariant, $variant->leadTimeMinDays, $variant->leadTimeMaxDays, $run, $now);
                if ($variant->row->rrpMinor !== null) {
                    $this->writeVariantRrp($writtenVariant, $variant->row->rrpMinor, $variant->row->currency, $run, $now);
                    $written['rrpWrites']++;
                }
                $written['offers']++;
                $written['variants']++;
                if ($entry['product'] !== null) {
                    $written['products']++;
                }
                continue;
            }
            $variant = $this->em->find(ProductVariant::class, $entry['variantId'])
                ?? throw new \RuntimeException(sprintf('Variant %s is gone; the batch cannot be replayed', $entry['variantId']));
            $offer = $entry['offerId'] === null ? null : $this->em->find(SupplierOffer::class, $entry['offerId']);
            $update = new PlannedUpdate(
                $this->rowFromArray($entry['row']),
                $variant,
                $entry['changes'],
                $entry['rrp'],
                $entry['leadTimeMinDays'],
                $entry['leadTimeMaxDays'],
                $offer,
            );
            if ($update->rrp !== null) {
                $this->writeRrp($update, $run, $now);
                $written['rrpWrites']++;
            }
            if ($offer === null) {
                $this->writeOffer($update->variant->getProduct(), $update->row, $update->variant, $update->leadTimeMinDays, $update->leadTimeMaxDays, $run, $now);
                $written['offers']++;
            } else {
                $this->updateOffer($update, $run, $now);
                $written['offerUpdates']++;
            }
        }

        return $written;
    }

    /** @param array<string, mixed> $data */
    private function newVariantFromArray(array $data): PlannedNewVariant
    {
        return new PlannedNewVariant(
            $this->rowFromArray($data['row']),
            $data['ean'],
            $data['mpn'],
            $data['label'],
            $data['attributes'],
            $data['leadTimeMinDays'],
            $data['leadTimeMaxDays'],
        );
    }

    /** @param AwinRow $row */
    private function rowToArray(AwinRow $row): array
    {
        return [
            'rowNumber' => $row->rowNumber, 'productId' => $row->productId, 'name' => $row->name,
            'description' => $row->description, 'brandName' => $row->brandName, 'ean' => $row->ean,
            'mpn' => $row->mpn, 'colour' => $row->colour, 'size' => $row->size,
            'priceMinor' => $row->priceMinor, 'currency' => $row->currency, 'rrpMinor' => $row->rrpMinor,
            'inStock' => $row->inStock, 'stockStatus' => $row->stockStatus, 'quantity' => $row->quantity,
            'deliveryTime' => $row->deliveryTime, 'images' => $row->images, 'merchantCategory' => $row->merchantCategory,
            'categoryPath' => $row->categoryPath, 'deepLink' => $row->deepLink, 'lastUpdated' => $row->lastUpdated,
        ];
    }

    /** @param array<string, mixed> $data */
    private function rowFromArray(array $data): AwinRow
    {
        return new AwinRow(
            $data['rowNumber'], $data['productId'], $data['name'], $data['description'], $data['brandName'],
            $data['ean'], $data['mpn'], $data['colour'], $data['size'], $data['priceMinor'], $data['currency'],
            $data['rrpMinor'], $data['inStock'], $data['stockStatus'], $data['quantity'], $data['deliveryTime'],
            $data['images'], $data['merchantCategory'], $data['categoryPath'], $data['deepLink'], $data['lastUpdated'],
        );
    }

    /** @return array<string, mixed>|null */
    public function run(string $id): ?array
    {
        $run = Uuid::isValid($id) ? $this->em->find(ImportRun::class, $id) : null;
        if ($run === null) {
            return null;
        }

        return $this->runSummary($run) + ['errors' => $run->getErrors(), 'report' => $run->getReport()];
    }

    /** @return array<string, mixed> */
    public function runs(int $page): array
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM import_run');
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $ids = $this->db->fetchFirstColumn(
            'SELECT id FROM import_run ORDER BY started_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['limit' => self::PAGE_SIZE, 'offset' => ($page - 1) * self::PAGE_SIZE],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
        $items = [];
        foreach ($ids as $id) {
            $run = $this->em->find(ImportRun::class, $id);
            if ($run !== null) {
                $items[] = $this->runSummary($run);
            }
        }

        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /** @return array<string, mixed> */
    private function runSummary(ImportRun $run): array
    {
        return [
            'id' => $run->getId()->toRfc4122(),
            'source' => $run->getSource(),
            'supplier' => $run->getSupplier(),
            'fileName' => $run->getFileName(),
            'status' => $run->getStatus(),
            'totalRows' => $run->getTotalRows(),
            'counts' => $run->getCounts(),
            'startedAt' => $run->getStartedAt()->format(\DATE_ATOM),
            'finishedAt' => $run->getFinishedAt()?->format(\DATE_ATOM),
            'adminEmail' => $run->getAdminEmail(),
            'errorCount' => count($run->getErrors()),
        ];
    }

    /** The product is created once per group; its first variant rides along and the rest follow. */
    private function writeNewProductVariant(PlannedNewProduct $planned, PlannedNewVariant $plannedVariant, ImportRun $run, \DateTimeImmutable $now): ProductVariant
    {
        $category = $this->em->find(Category::class, $planned->categoryRow['id']) ?? throw new \RuntimeException(sprintf('Guessed category "%s" disappeared mid-run', $planned->categoryRow['slug']));
        $product = $this->em->getRepository(Product::class)->findOneBy(['slug' => $planned->slug]);
        if ($product === null) {
            $copy = [];
            foreach (['cs', 'de', 'en'] as $locale) {
                // one feed text for all locales; the admin rewrites the card before it is published
                $copy[$locale] = [
                    'name' => $planned->name,
                    'short' => mb_substr($planned->description ?? $planned->name, 0, 300),
                    'description' => $planned->description ?? $planned->name,
                    'details' => [],
                ];
            }
            $image = '/images/maintenance.png';
            $product = new Product($planned->slug, $category, $copy, $image);
            $product->update($planned->slug, $category, $copy, $image, [$image], null, 100, 'draft');
            $product->describe($planned->brand, []);
            $product->setSourceImages($planned->sourceImages);
            $this->em->persist($product);
            $this->origin($run, ImportFieldOrigin::ENTITY_PRODUCT, $product->getId(), ['category', 'name', 'short', 'description', 'brand', 'source_images'], $now);
        }

        $row = $plannedVariant->row;
        // the feed price is the supplier's; the retail price is the admin's decision, so a new variant starts at zero
        $variant = new ProductVariant($product, $this->skuOf($row->productId), ['cs' => $plannedVariant->label, 'de' => $plannedVariant->label, 'en' => $plannedVariant->label], 0, 0, 0);
        $variant->identify($plannedVariant->mpn, $plannedVariant->ean, $plannedVariant->attributes);
        $this->em->persist($variant);
        $this->history->record($variant, null, null, PriceChange::IMPORT);
        $fields = ['sku', 'label', 'price'];
        if ($plannedVariant->ean !== null) {
            $fields[] = 'ean';
        }
        if ($plannedVariant->mpn !== null) {
            $fields[] = 'mpn';
        }
        if ($plannedVariant->attributes !== []) {
            $fields[] = 'attributes';
        }
        $this->origin($run, ImportFieldOrigin::ENTITY_VARIANT, $variant->getId(), $fields, $now);

        return $variant;
    }

    private function writeOffer(Product $product, AwinRow $row, ProductVariant $variant, ?int $leadMin, ?int $leadMax, ImportRun $run, \DateTimeImmutable $now): void
    {
        $supplier = (string) $run->getSupplier();
        $offer = new SupplierOffer($product, $supplier, $row->deepLink, mb_substr($row->name, 0, 200), $row->currency, $row->priceMinor, $row->quantity, $now);
        $offer->update($supplier, $row->deepLink, mb_substr($row->name, 0, 200), null, $row->currency, $row->priceMinor, $row->quantity, $now, $leadMin, $leadMax, $variant, 'matched');
        $this->setFeedCost($offer, $row, $supplier, $now);
        $offer->setSupplierSku($row->productId);
        $this->em->persist($offer);
        $this->origin($run, ImportFieldOrigin::ENTITY_OFFER, $offer->getId(), ['url', 'title', 'supplier_sku', 'price', 'stock', 'inbound_shipping', 'fx_rate', 'checked'], $now);
    }

    private function updateOffer(PlannedUpdate $update, ImportRun $run, \DateTimeImmutable $now): void
    {
        $offer = $update->existingOffer;
        if ($offer === null) {
            return;
        }
        $row = $update->row;
        $offer->update($offer->getSupplier(), $row->deepLink, mb_substr($row->name, 0, 200), $offer->getSeller(), $row->currency, $row->priceMinor, $row->quantity, $now, $update->leadTimeMinDays, $update->leadTimeMaxDays, $update->variant, 'matched');
        // inbound shipping and the exchange rate are the admin's: an update never touches them
        $fields = ['checked'];
        foreach ($update->changes as $change) {
            if (in_array($change['field'], ['price', 'stock', 'leadTime', 'url', 'title'], true)) {
                $fields[] = $change['field'];
            }
        }
        $this->origin($run, ImportFieldOrigin::ENTITY_OFFER, $offer->getId(), $fields, $now);
    }

    private function writeRrp(PlannedUpdate $update, ImportRun $run, \DateTimeImmutable $now): void
    {
        $rrp = $update->rrp;
        if ($rrp === null) {
            return;
        }
        $this->writeVariantRrp($update->variant, $rrp[0], $rrp[1], $run, $now);
    }

    /** A feed RRP always comes with source "feed <supplier>" and today's date; a new variant has nothing to protect. */
    private function writeVariantRrp(ProductVariant $variant, int $minor, string $currency, ImportRun $run, \DateTimeImmutable $now): void
    {
        $variant->setReferencePrices(
            $minor, $currency, 'feed '.($run->getSupplier() ?? ''),
            new \DateTimeImmutable($now->format('Y-m-d')),
            $variant->getMarketPriceMinor(), $variant->getMarketPriceSource(), $variant->getMarketCheckedAt(),
        );
        $this->origin($run, ImportFieldOrigin::ENTITY_VARIANT, $variant->getId(), ['rrp'], $now);
    }

    /** The feed offer starts with the supplier's default inbound shipping and the default rate of its currency. */
    private function setFeedCost(SupplierOffer $offer, AwinRow $row, string $supplier, \DateTimeImmutable $now): void
    {
        $rate = match ($row->currency) {
            'CZK' => Money::RATE_ONE,
            'EUR' => $this->pricingSettings->eurRate,
            default => null,
        };
        $offer->setCost($this->settings->inboundShippingMinor($supplier), $rate, $rate === null ? null : new \DateTimeImmutable($now->format('Y-m-d')));
    }

    /** @param list<string> $fields */
    private function origin(ImportRun $run, string $entityType, Uuid $entityId, array $fields, \DateTimeImmutable $now): void
    {
        foreach ($fields as $field) {
            $this->em->persist(ImportFieldOrigin::fromRun($run, $entityType, $entityId, $field, $now));
        }
    }

    private function skuOf(string $productId): string
    {
        return 'ND-'.strtoupper(substr(hash('sha256', $productId), 0, 12));
    }

    private function adminEmail(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? 'system';
    }
}
