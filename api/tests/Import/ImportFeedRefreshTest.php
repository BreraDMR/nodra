<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Entity\Category;
use App\Entity\ImportBatch;
use App\Entity\ImportRun;
use App\Import\AwinCsvParser;
use App\Import\FeedLoader;
use App\Import\FeedRefresh;
use App\Import\ImportPlanner;
use App\Import\ImportService;
use App\Import\ImportSettings;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The D03.4 standing feed refresh: the stored feed of a supplier is planned once and written in
 * journaled row batches, with retries, a frequency limit and nothing ever deleted. The feed source
 * is a local file or an injected HTTP resolver — the tests never reach the internet.
 */
final class ImportFeedRefreshTest extends ApiTestCase
{
    private string $csrf;
    private Category $cassettes;
    private FeedRefresh $refresh;
    private FeedLoader $loader;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csrf = $this->loginAdmin();
        $container = static::getContainer();
        $this->loader = $container->get(FeedLoader::class);
        $this->em = $container->get(EntityManagerInterface::class);
        // 2 rows per batch: several batches from a handful of rows, small enough for the test memory
        $this->refresh = new FeedRefresh(
            $container->get(AwinCsvParser::class),
            $container->get(ImportPlanner::class),
            $container->get(ImportService::class),
            $container->get(ImportSettings::class),
            $this->loader,
            $this->em,
            $container->get(ClockInterface::class),
            batchRows: 2,
            minFeedIntervalMinutes: 60,
            batchAttempts: 3,
            staleBatchMinutes: 30,
        );

        $b = $this->builder();
        $components = $b->category('components', names: ['cs' => 'Komponenty', 'de' => 'Komponenten', 'en' => 'Components']);
        $this->cassettes = $b->category('cassettes', $components, names: ['cs' => 'Kazety', 'de' => 'Kassetten', 'en' => 'Cassettes']);
    }

    public function testALocalFileFeedIsPlannedAndWrittenInBatches(): void
    {
        $this->storeFeedUrl($this->fixtureFile(AwinFeed::csv($this->rows(5))));
        $before = $this->productCount();

        $result = $this->refresh->refreshSupplier('bike_components', false);

        self::assertSame('applied', $result['status'], (string) $result['note']);
        self::assertSame(3, $result['batchesDone'], '5 planned rows split into 2-row batches');
        self::assertSame(0, $result['batchesFailed']);
        self::assertSame(5, $this->productCount() - $before, 'the refresh wrote the whole planned file');

        $run = $this->lastRun();
        $batches = $this->refresh->batchSummaries($run['id']);
        self::assertSame([1, 2, 3], array_column($batches, 'batchNo'));
        self::assertSame(['done', 'done', 'done'], array_column($batches, 'status'));
        self::assertSame(2, $batches[0]['rowTo'] - $batches[0]['rowFrom'] + 1);
        self::assertSame(1, $batches[2]['rowTo'] - $batches[2]['rowFrom'] + 1, 'the tail batch carries the remaining rows');
        self::assertSame(3, $run['counts']['batchesDone']);
        self::assertSame(0, $run['counts']['batchesFailed']);

        // the batches read back through the admin API with their states
        $viaApi = $this->getJson('/api/admin/imports/batches?runId='.$run['id']);
        self::assertCount(3, $viaApi['items']);
        self::assertSame('done', $viaApi['items'][0]['status']);
    }

    public function testAStoredHttpFeedParsesLikeALocalFile(): void
    {
        $this->loader->setHttpResolver(static fn (string $url): string => AwinFeed::csv());
        $this->storeFeedUrl('https://feeds.example/awin.csv');
        $before = $this->productCount();

        $result = $this->refresh->refreshSupplier('bike_components', false);

        self::assertSame('applied', $result['status'], (string) $result['note']);
        self::assertSame(2, $result['batchesDone'], 'the 3 default rows split into 2-row batches');
        self::assertSame(3, $this->productCount() - $before);
    }

    public function testAMissingSourceFailsTheRunAndWritesNothing(): void
    {
        $this->storeFeedUrl('/nonexistent/path/feed.csv');
        $before = $this->productCount();

        $result = $this->refresh->refreshSupplier('bike_components', false);

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('does not exist', $result['note']);
        $run = $this->lastRun();
        self::assertSame('failed', $run['status']);
        self::assertSame(0, $this->productCount() - $before, 'a failed download writes nothing');
        self::assertSame([], $this->refresh->batchSummaries($run['id']), 'the failed run has zero batches');
    }

    public function testADownloadFailureFailsTheRunTheSameWay(): void
    {
        $this->loader->setHttpResolver(static fn (string $url): string => throw new \RuntimeException('connection refused'));
        $this->storeFeedUrl('https://feeds.example/awin.csv');
        $before = $this->productCount();

        $result = $this->refresh->refreshSupplier('bike_components', false);

        self::assertSame('failed', $result['status']);
        self::assertSame('connection refused', $result['note']);
        self::assertSame(0, $this->productCount() - $before);
    }

    public function testAFailedBatchDoesNotStopTheOthers(): void
    {
        $b = $this->builder();
        $product = $b->product('seed-xt', $this->cassettes, 'Shimano');
        $variant = $b->variant($product, 'SEED-XT-1');
        $run = $this->journalRun();

        $good = $this->addBatch($run, 1, [$this->updateEntry($variant->getId()->toRfc4122(), 1)]);
        $bad = $this->addBatch($run, 2, [$this->updateEntry('01890000-0000-7000-8000-000000000000', 2)]);

        $this->refresh->executeBatch($good);
        $this->refresh->executeBatch($bad);

        self::assertSame(ImportBatch::STATUS_DONE, $good->getStatus());
        self::assertSame(ImportBatch::STATUS_FAILED, $bad->getStatus());
        self::assertSame(1, $bad->getAttempts());
        self::assertStringContainsString('gone', (string) $bad->getError());
        // the good batch wrote its offer; the bad one wrote nothing partial, nothing was deleted
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM supplier_offer WHERE variant_id = ?', [$variant->getId()->toRfc4122()]));
    }

    public function testARetryReplaysTheStoredPlanAndGrowsAttempts(): void
    {
        $b = $this->builder();
        $product = $b->product('seed-xt', $this->cassettes, 'Shimano');
        $variant = $b->variant($product, 'SEED-XT-1');
        $run = $this->journalRun();

        // the plan references a variant that has disappeared meanwhile: the batch fails
        $batch = $this->addBatch($run, 1, [$this->updateEntry('01890000-0000-7000-8000-000000000000', 1)]);
        $this->refresh->executeBatch($batch);
        self::assertSame(ImportBatch::STATUS_FAILED, $batch->getStatus());

        // the underlying problem is fixed (the plan now points at the real variant): the manual retry replays it
        // the failed attempt detached the test's copy — the journal row is re-read through the manager
        $fixed = $this->em->find(ImportBatch::class, $batch->getId());
        self::assertNotNull($fixed);
        $fixed->setPlan([$this->updateEntry($variant->getId()->toRfc4122(), 1)]);
        $this->em->flush();
        $result = $this->sendJson('POST', '/api/admin/imports/batches/'.$batch->getId()->toRfc4122().'/retry', [], $this->csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('done', $result['items'][0]['status']);
        self::assertSame(2, $result['items'][0]['attempts'], 'the retry grew the attempts');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM supplier_offer WHERE variant_id = ?', [$variant->getId()->toRfc4122()]));

        // a done batch is never run again, an unknown one is a 404
        $this->sendJson('POST', '/api/admin/imports/batches/'.$batch->getId()->toRfc4122().'/retry', [], $this->csrf);
        self::assertResponseStatusCodeSame(409);
        $this->sendJson('POST', '/api/admin/imports/batches/01890000-0000-7000-8000-000000000000/retry', [], $this->csrf);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnInterruptedBatchIsRequeuedByTheNextPass(): void
    {
        $b = $this->builder();
        $product = $b->product('seed-xt', $this->cassettes, 'Shimano');
        $variant = $b->variant($product, 'SEED-XT-1');
        $run = $this->journalRun();
        $batch = $this->addBatch($run, 1, [$this->updateEntry($variant->getId()->toRfc4122(), 1)]);
        // a worker died mid-batch: the journal still says running, past the stale window
        $this->db()->executeStatement("UPDATE import_batch SET status = 'running', started_at = date_trunc('second', now() - interval '40 minutes') WHERE id = ?", [$batch->getId()->toRfc4122()]);

        $result = $this->refresh->refreshSupplier('bike_components', false);

        self::assertSame(1, $result['batchesDone'], (string) $result['note']);
        $fresh = $this->refresh->batchSummaries($run->getId()->toRfc4122());
        self::assertSame('done', $fresh[0]['status']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM supplier_offer WHERE variant_id = ?', [$variant->getId()->toRfc4122()]));
    }

    public function testTheFrequencyLimitRefusesAndForceOverrides(): void
    {
        $this->storeFeedUrl($this->fixtureFile(AwinFeed::csv()));

        $first = $this->refresh->refreshSupplier('bike_components', false);
        self::assertSame('applied', $first['status']);

        // a second pass inside the interval starts no new run
        $second = $this->refresh->refreshSupplier('bike_components', false);
        self::assertNull($second['runId']);
        self::assertStringContainsString('less than 60 minutes', (string) $second['note']);

        // --force runs regardless
        $forced = $this->refresh->refreshSupplier('bike_components', true);
        self::assertSame('applied', $forced['status']);
        self::assertNotSame($first['runId'], $forced['runId']);

        // an elapsed interval lets the next scheduled pass through again
        $this->db()->executeStatement("UPDATE import_run SET started_at = started_at - interval '2 hours' WHERE supplier = 'bike_components'");
        $later = $this->refresh->refreshSupplier('bike_components', false);
        self::assertSame('applied', $later['status']);
    }

    public function testFeedUrlAndShippingSavePerSupplierAndValidate(): void
    {
        $this->client->request('PUT', '/api/admin/imports/settings/bike_components', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], content: '{"inboundShippingMinor": 495, "feedUrl": "https://feeds.example/awin.csv"}');
        self::assertResponseIsSuccessful();
        $settings = array_column($this->getJson('/api/admin/imports/settings')['settings'], null, 'supplier');
        self::assertSame('https://feeds.example/awin.csv', $settings['bike_components']['feedUrl']);
        self::assertSame(495, $settings['bike_components']['inboundShippingMinor']);
        self::assertNull($settings['other']['feedUrl']);

        // a wrong scheme and an unknown supplier are refused
        $this->client->request('PUT', '/api/admin/imports/settings/bike_components', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], content: '{"inboundShippingMinor": 495, "feedUrl": "ftp://feeds.example/awin.csv"}');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('PUT', '/api/admin/imports/settings/unknown_supplier', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], content: '{"inboundShippingMinor": 1, "feedUrl": "https://feeds.example/awin.csv"}');
        self::assertResponseStatusCodeSame(422);
    }

    public function testPublicJsonCarriesNoFeedRefreshData(): void
    {
        $this->storeFeedUrl($this->fixtureFile(AwinFeed::csv()));
        $this->refresh->refreshSupplier('bike_components', false);

        $this->client->request('GET', '/api/products', ['locale' => 'en']);
        $body = (string) $this->client->getResponse()->getContent();
        foreach (['feedUrl', 'feed_url', 'batches', 'origins', 'supplierSku'] as $needle) {
            self::assertStringNotContainsString($needle, $body, "the public catalogue leaks $needle");
        }
    }

    /**
     * A feed of $count distinct new cassettes: distinct EANs, MPNs and supplier SKUs.
     * @return list<array<string, string>>
     */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = AwinFeed::row([
                'product_id' => 'BC-'.(5000 + $i),
                'product_name' => 'Shimano XT cassette '.$i,
                'ean' => AwinFeed::ean($i),
                'mpn' => 'XTM8100-'.$i,
                'merchant_category' => 'Cassettes',
                'merchant_product_category_path' => 'Components > Cassettes',
            ], $i);
        }

        return $rows;
    }

    private function storeFeedUrl(string $url): void
    {
        $this->client->request('PUT', '/api/admin/imports/settings/bike_components', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], content: json_encode(['inboundShippingMinor' => 0, 'feedUrl' => $url], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    private function fixtureFile(string $csv): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'refresh');
        file_put_contents($tmp, $csv);

        return $tmp;
    }

    private function productCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM product');
    }

    /** @return array<string, mixed> the run the test has just made */
    private function lastRun(): array
    {
        $runs = $this->getJson('/api/admin/imports/runs');
        self::assertGreaterThanOrEqual(1, $runs['total']);

        return $runs['items'][0];
    }

    private function journalRun(): ImportRun
    {
        $run = new ImportRun(ImportRun::SOURCE_AWIN, static::getContainer()->get(ClockInterface::class)->now(), 'bike_components');
        $run->start('feed.csv', null, 3);
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /** @param list<array<string, mixed>> $plan */
    private function addBatch(ImportRun $run, int $no, array $plan): ImportBatch
    {
        $batch = new ImportBatch($run, $no, $plan[0]['rowNo'], end($plan)['rowNo'], static::getContainer()->get(ClockInterface::class)->now());
        $batch->setPlan($plan);
        $this->em->persist($batch);
        $this->em->flush();

        return $batch;
    }

    /**
     * One update entry of a stored batch plan: the shape ImportService::serializePlan writes.
     * @return array<string, mixed>
     */
    private function updateEntry(string $variantId, int $rowNo): array
    {
        return [
            'kind' => 'update',
            'variantId' => $variantId,
            'offerId' => null,
            'rrp' => null,
            'leadTimeMinDays' => 2,
            'leadTimeMaxDays' => 4,
            'changes' => [],
            'rowNo' => $rowNo,
            'row' => [
                'rowNumber' => $rowNo, 'productId' => 'BC-'.(1000 + $rowNo), 'name' => 'Shimano XT CS-M8100 cassette 12-speed',
                'description' => 'Lightweight 12-speed cassette.', 'brandName' => 'Shimano', 'ean' => null,
                'mpn' => 'CSM8100122', 'colour' => null, 'size' => null, 'priceMinor' => 8990, 'currency' => 'EUR',
                'rrpMinor' => null, 'inStock' => true, 'stockStatus' => 'In Stock', 'quantity' => 5,
                'deliveryTime' => '2-4 days', 'images' => ['https://img.example/xt.jpg'],
                'merchantCategory' => 'Cassettes', 'categoryPath' => 'Components > Cassettes',
                'deepLink' => 'https://t.example/x', 'lastUpdated' => '2026-09-28 10:00:00',
            ],
        ];
    }
}
