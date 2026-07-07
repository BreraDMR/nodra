<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportBatch;
use App\Entity\ImportRun;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The standing feed refresh (D03.4): the stored feed URL of a supplier is downloaded, planned once
 * and written in row batches, each batch its own transaction and journal row. The command
 * app:import:feed is the whole scheduler; nothing is deleted by a failing supplier.
 */
final class FeedRefresh
{
    public function __construct(
        private AwinCsvParser $parser,
        private ImportPlanner $planner,
        private ImportService $imports,
        private ImportSettings $settings,
        private FeedLoader $loader,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        #[Autowire(param: 'app.import.batch_rows')] private int $batchRows,
        #[Autowire(param: 'app.import.min_feed_interval')] private int $minFeedIntervalMinutes,
        #[Autowire(param: 'app.import.batch_attempts')] private int $batchAttempts,
        #[Autowire(param: 'app.import.stale_batch_minutes')] private int $staleBatchMinutes,
    ) {}

    /**
     * One scheduler pass: re-queue interrupted batches, retry failed ones with attempts left, then a
     * fresh run when the frequency limit allows it. Returns what happened, for the console output.
     *
     * @return list<array{supplier: string, runId: ?string, status: ?string, batchesDone: int, batchesFailed: int, note: ?string}>
     */
    public function refresh(?string $supplier, bool $force): array
    {
        $suppliers = $supplier !== null && $supplier !== ''
            ? [$supplier]
            : array_column($this->settings->list(), 'supplier');

        $results = [];
        foreach ($suppliers as $one) {
            try {
                $results[] = $this->refreshSupplier($one, $force);
            } catch (\Throwable $error) {
                $results[] = ['supplier' => $one, 'runId' => null, 'status' => null, 'batchesDone' => 0, 'batchesFailed' => 0, 'note' => $error->getMessage()];
            }
        }

        return $results;
    }

    /** @return array{supplier: string, runId: ?string, status: ?string, batchesDone: int, batchesFailed: int, note: ?string} */
    public function refreshSupplier(string $supplier, bool $force): array
    {
        $done = $this->requeueAndRun($supplier);

        // the frequency limit guards the cron: a supplier is refreshed at most once per interval
        $last = $this->lastRunStartedAt($supplier);
        if (!$force && $last !== null && $this->clock->now()->getTimestamp() - $last->getTimestamp() < $this->minFeedIntervalMinutes * 60) {
            return ['supplier' => $supplier, 'runId' => null, 'status' => null, 'batchesDone' => $done['done'], 'batchesFailed' => $done['failed'], 'note' => sprintf('The feed of %s was refreshed less than %d minutes ago; use --force to override', $supplier, $this->minFeedIntervalMinutes)];
        }

        $feedUrl = $this->settings->feedUrl($supplier);
        if ($feedUrl === null) {
            return ['supplier' => $supplier, 'runId' => null, 'status' => null, 'batchesDone' => $done['done'], 'batchesFailed' => $done['failed'], 'note' => 'No feed URL stored for this supplier'];
        }

        return $this->runFeed($supplier, $feedUrl);
    }

    /** @return array{done: int, failed: int} */
    private function requeueAndRun(string $supplier): array
    {
        $now = $this->clock->now();
        $staleBefore = $now->modify(sprintf('-%d minutes', $this->staleBatchMinutes));
        $done = 0;
        $failed = 0;
        // interrupted batches (a crashed worker) go back to the queue, failed ones retry on their own
        $due = $this->em->getRepository(ImportBatch::class)->createQueryBuilder('b')
            ->where('b.supplier = :supplier AND (b.status = :pending OR (b.status = :running AND b.startedAt < :stale) OR (b.status = :failed AND b.attempts < b.maxAttempts))')
            ->setParameter('supplier', $supplier)
            ->setParameter('pending', ImportBatch::STATUS_PENDING)
            ->setParameter('running', ImportBatch::STATUS_RUNNING)
            ->setParameter('failed', ImportBatch::STATUS_FAILED)
            ->setParameter('stale', $staleBefore)
            ->orderBy('b.batchNo', 'ASC')
            ->getQuery()->getResult();
        foreach ($due as $batch) {
            if ($batch->getStatus() === ImportBatch::STATUS_PENDING || $batch->getStatus() === ImportBatch::STATUS_RUNNING) {
                $batch->requeue();
                $this->em->flush();
            }
            $this->executeBatch($batch);
            $batch->getStatus() === ImportBatch::STATUS_DONE ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /** Download → parse → plan → batch → execute; a failure before the first write journals a failed run. */
    private function runFeed(string $supplier, string $feedUrl): array
    {
        $now = $this->clock->now();
        $run = new ImportRun(ImportRun::SOURCE_AWIN, $now, $supplier);
        try {
            $bytes = $this->loader->load($feedUrl);
            $parsed = $this->parser->parse($bytes);
        } catch (\Throwable $error) {
            // nothing was written: the catalogue is untouched, the journal says why
            $run->start(basename($feedUrl), null, 0);
            $run->finish(ImportRun::STATUS_FAILED, ['errors' => 1], [
                'source' => ImportRun::SOURCE_AWIN, 'fileName' => basename($feedUrl), 'sha256' => null, 'totalRows' => 0,
                'counts' => ['errors' => 1], 'newProducts' => [], 'updates' => [], 'conflicts' => [], 'unknowns' => [],
                'errors' => [['row' => 0, 'message' => $error->getMessage()]],
                'cost' => ['rows' => [], 'marginTooLow' => 0], 'batches' => [],
            ], [['row' => 0, 'message' => $error->getMessage()]], $this->clock->now());
            $this->em->persist($run);
            $this->em->flush();

            return ['supplier' => $supplier, 'runId' => $run->getId()->toRfc4122(), 'status' => ImportRun::STATUS_FAILED, 'batchesDone' => 0, 'batchesFailed' => 0, 'note' => $error->getMessage()];
        }

        $sha = hash('sha256', $bytes);
        $planned = $this->planner->plan($parsed, $supplier, $now);
        $report = $planned['report'] + ['runId' => $run->getId()->toRfc4122(), 'source' => ImportRun::SOURCE_AWIN, 'fileName' => basename($feedUrl), 'sha256' => $sha];
        $run->start(basename($feedUrl), $sha, $parsed->totalRows);
        $this->em->persist($run);

        // the plan is stored with the batches, so a retry replays the same writes without the file
        $entries = $this->imports->serializePlan($planned['plan']);
        $batches = [];
        $batchRows = max(1, $this->batchRows);
        $no = 0;
        foreach (array_chunk($entries, $batchRows) as $chunk) {
            $batch = new ImportBatch($run, ++$no, $chunk[0]['rowNo'], $chunk[count($chunk) - 1]['rowNo'], $now);
            $batch->setPlan($chunk);
            $batch->setMaxAttempts($this->batchAttempts);
            $this->em->persist($batch);
            $batches[] = $batch;
        }
        $this->em->flush();

        $done = 0;
        $failed = 0;
        foreach ($batches as $batch) {
            $this->executeBatch($batch);
            $batch->getStatus() === ImportBatch::STATUS_DONE ? $done++ : $failed++;
        }

        // the run keeps the planned report plus how its batches went, whatever the mix
        $this->em->clear();
        $fresh = $this->em->find(ImportRun::class, $run->getId());
        $counts = $report['counts'] + ['batchesDone' => $done, 'batchesFailed' => $failed];
        $report['counts'] = $counts;
        $report['batches'] = $this->batchSummaries($run->getId()->toRfc4122());
        $errors = $parsed->errors;
        foreach ($report['batches'] as $summary) {
            if ($summary['status'] === ImportBatch::STATUS_FAILED) {
                $errors[] = ['row' => $summary['rowFrom'], 'message' => sprintf('Batch %d (rows %d-%d): %s', $summary['batchNo'], $summary['rowFrom'], $summary['rowTo'], $summary['error'] ?? 'failed')];
            }
        }
        $fresh->finish(ImportRun::STATUS_APPLIED, $counts, $report, $errors, $this->clock->now());
        $this->em->flush();

        return ['supplier' => $supplier, 'runId' => $run->getId()->toRfc4122(), 'status' => ImportRun::STATUS_APPLIED, 'batchesDone' => $done, 'batchesFailed' => $failed, 'note' => null];
    }

    /** One attempt at a batch: its own transaction, its own journal row, partial writes thrown away. */
    public function executeBatch(ImportBatch $batch): void
    {
        $now = $this->clock->now();
        $batch->start($now);
        $this->em->flush();
        try {
            $this->em->wrapInTransaction(function () use ($batch, $now): void {
                $run = $this->em->find(ImportRun::class, $batch->getRun()->getId()) ?? throw new \RuntimeException('The batch run disappeared');
                $this->imports->executeBatchPlan($batch->getPlan(), $run, $now);
                $this->em->flush();
            });
            $batch->finish($batch->getPlan(), $this->clock->now());
            $this->em->flush();
        } catch (\Throwable $error) {
            // a failed transaction leaves partial writes in the unit of work: drop them all, then journal
            $this->em->clear();
            $fresh = $this->em->find(ImportBatch::class, $batch->getId());
            if ($fresh !== null) {
                $fresh->fail($error->getMessage(), $this->clock->now());
                $this->em->flush();
            }
        }
    }

    /** The manual restart of a batch that used up its automatic attempts. */
    public function retryBatch(string $batchId): ?ImportBatch
    {
        $batch = $this->em->find(ImportBatch::class, $batchId);
        if ($batch === null) {
            return null;
        }
        if ($batch->getStatus() === ImportBatch::STATUS_DONE) {
            throw new \DomainException('This batch is already done');
        }
        $this->executeBatch($batch);

        return $batch;
    }

    /** @return list<array<string, mixed>> */
    public function batchSummaries(string $runId): array
    {
        $rows = $this->em->getRepository(ImportBatch::class)->createQueryBuilder('b')
            ->select('b.id, b.batchNo, b.rowFrom, b.rowTo, b.status, b.attempts, b.maxAttempts, b.error, b.startedAt, b.finishedAt')
            ->where('IDENTITY(b.run) = :run')
            ->setParameter('run', $runId)
            ->orderBy('b.batchNo', 'ASC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'batchNo' => $row['batchNo'],
            'rowFrom' => $row['rowFrom'],
            'rowTo' => $row['rowTo'],
            'status' => $row['status'],
            'attempts' => $row['attempts'],
            'maxAttempts' => $row['maxAttempts'],
            'error' => $row['error'],
            'startedAt' => $row['startedAt']?->format(\DATE_ATOM),
            'finishedAt' => $row['finishedAt']?->format(\DATE_ATOM),
        ], $rows);
    }

    private function lastRunStartedAt(string $supplier): ?\DateTimeImmutable
    {
        $value = $this->em->getRepository(ImportRun::class)->createQueryBuilder('r')
            ->select('r.startedAt')
            ->where('r.source = :source AND r.supplier = :supplier AND r.status != :failed')
            ->setParameter('source', ImportRun::SOURCE_AWIN)
            ->setParameter('supplier', $supplier)
            ->setParameter('failed', ImportRun::STATUS_FAILED)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return is_array($value) ? $value['startedAt'] : null;
    }
}
