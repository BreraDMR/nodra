<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One chunk of a feed refresh: the planned writes for a row range, replayable without the file.
 * A failed batch never stops the others — rows are independent and nothing is ever deleted (D03.4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_batch')]
#[ORM\Index(columns: ['run_id', 'batch_no'], name: 'idx_import_batch_run')]
#[ORM\Index(columns: ['supplier', 'status'], name: 'idx_import_batch_status')]
class ImportBatch
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_DONE, self::STATUS_FAILED];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ImportRun::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ImportRun $run;

    #[ORM\Column(length: 32)]
    private string $supplier;

    #[ORM\Column]
    private int $batchNo;

    #[ORM\Column]
    private int $rowFrom;

    #[ORM\Column]
    private int $rowTo;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(options: ['default' => 3])]
    private int $maxAttempts = 3;

    /** The planned writes of this batch, replayed by a retry without the feed file. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $plan = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(ImportRun $run, int $batchNo, int $rowFrom, int $rowTo, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->run = $run;
        $this->supplier = (string) $run->getSupplier();
        $this->batchNo = $batchNo;
        $this->rowFrom = $rowFrom;
        $this->rowTo = $rowTo;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getRun(): ImportRun { return $this->run; }
    public function getSupplier(): string { return $this->supplier; }
    public function getBatchNo(): int { return $this->batchNo; }
    public function getRowFrom(): int { return $this->rowFrom; }
    public function getRowTo(): int { return $this->rowTo; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function getPlan(): array { return $this->plan; }
    public function getError(): ?string { return $this->error; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    public function setPlan(array $plan): void { $this->plan = $plan; }

    public function setMaxAttempts(int $maxAttempts): void
    {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('A batch needs at least one attempt');
        }
        $this->maxAttempts = $maxAttempts;
    }

    /** An attempt starts: whether the automatic retries are used up. */
    public function start(\DateTimeImmutable $at): bool
    {
        if ($this->status === self::STATUS_DONE) {
            throw new \DomainException('A done batch is never run again');
        }
        $this->attempts++;
        $this->status = self::STATUS_RUNNING;
        $this->startedAt = $at;
        $this->error = null;

        return $this->attempts < $this->maxAttempts;
    }

    public function finish(array $plan, \DateTimeImmutable $at): void
    {
        $this->plan = $plan;
        $this->status = self::STATUS_DONE;
        $this->finishedAt = $at;
        $this->error = null;
    }

    public function fail(string $message, \DateTimeImmutable $at): void
    {
        $this->status = self::STATUS_FAILED;
        $this->finishedAt = $at;
        $this->error = $message;
    }

    /** Back to the queue: an interrupted (stale running) or a manually restarted batch. */
    public function requeue(): void
    {
        if ($this->status === self::STATUS_DONE) {
            throw new \DomainException('A done batch is never re-queued');
        }
        $this->status = self::STATUS_PENDING;
    }
}
