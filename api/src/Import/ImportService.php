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
        $plan = $planned['plan'];

        $written = ['products' => 0, 'variants' => 0, 'offers' => 0, 'offerUpdates' => 0, 'rrpWrites' => 0];
        $this->em->wrapInTransaction(function () use ($plan, $run, $parsed, $planned, $now, &$written): void {
            foreach ($plan->newProducts as $product) {
                foreach ($product->variants as $plannedVariant) {
                    $variant = $this->writeNewProductVariant($product, $plannedVariant, $run, $now);
                    $this->writeOffer($variant->getProduct(), $plannedVariant->row, $variant, $plannedVariant->leadTimeMinDays, $plannedVariant->leadTimeMaxDays, $run, $now);
                    if ($plannedVariant->row->rrpMinor !== null) {
                        $this->writeVariantRrp($variant, $plannedVariant->row->rrpMinor, $plannedVariant->row->currency, $run, $now);
                        $written['rrpWrites']++;
                    }
                    $written['offers']++;
                    $written['variants']++;
                }
                $written['products']++;
            }
            foreach ($plan->updates as $update) {
                if ($update->rrp !== null) {
                    $this->writeRrp($update, $run, $now);
                    $written['rrpWrites']++;
                }
                if ($update->existingOffer === null) {
                    $this->writeOffer($update->variant->getProduct(), $update->row, $update->variant, $update->leadTimeMinDays, $update->leadTimeMaxDays, $run, $now);
                    $written['offers']++;
                } else {
                    $this->updateOffer($update, $run, $now);
                    $written['offerUpdates']++;
                }
            }
            $report = $planned['report'] + ['runId' => $run->getId()->toRfc4122(), 'source' => ImportRun::SOURCE_AWIN, 'fileName' => $run->getFileName(), 'sha256' => $run->getFileSha256()];
            $run->finish(ImportRun::STATUS_APPLIED, $report['counts'], $report, $parsed->errors, $now);
            $this->em->flush();
        });

        return ['runId' => $run->getId()->toRfc4122(), 'written' => $written, 'report' => $run->getReport() ?? []];
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
            $this->em->persist(new ImportFieldOrigin($run, $entityType, $entityId, $field, $now));
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
