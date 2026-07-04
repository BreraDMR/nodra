<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The journal of one import: a feed file previewed and possibly applied, or a seed run.
 * Its per-row errors are the import-error log the admin works from (D05.5).
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_run')]
#[ORM\Index(columns: ['started_at'], name: 'idx_import_run_started')]
class ImportRun
{
    public const SOURCE_AWIN = 'awin_csv';
    public const SOURCE_SEED = 'seed';
    public const SOURCE_MERGE = 'merge';
    public const SOURCES = [self::SOURCE_AWIN, self::SOURCE_SEED, self::SOURCE_MERGE];
    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUSES = [self::STATUS_PREVIEWED, self::STATUS_APPLIED, self::STATUS_FAILED];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    private string $source;

    /** The supplier the feed belongs to; null for a seed run. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $supplier = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    /** Of the file as uploaded; apply refuses a different one. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fileSha256 = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PREVIEWED;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalRows = 0;

    /** Complete counts per report section, see the import contract. */
    #[ORM\Column(type: Types::JSONB_OBJECT, options: ['default' => '{}'])]
    private \stdClass|array $counts;

    /** The report the run ended with, lists truncated to 200 rows like the API sends them. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true], nullable: true)]
    private ?array $report = null;

    /** Every per-row error, truncation included. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $errors = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Admin email, or null for a console (seed) run. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $adminEmail = null;

    public function __construct(string $source, \DateTimeImmutable $startedAt, ?string $supplier = null)
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Unknown import source');
        }
        $this->id = Uuid::v7();
        $this->source = $source;
        $this->startedAt = $startedAt;
        $this->supplier = $supplier;
        $this->counts = new \stdClass();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSource(): string { return $this->source; }
    public function getSupplier(): ?string { return $this->supplier; }
    public function getFileName(): ?string { return $this->fileName; }
    public function getFileSha256(): ?string { return $this->fileSha256; }
    public function getStatus(): string { return $this->status; }
    public function getTotalRows(): int { return $this->totalRows; }
    /** @return array<string, int> */
    public function getCounts(): array { return (array) $this->counts; }
    public function getReport(): ?array { return $this->report; }
    /** @return list<array{row: int, message: string}> */
    public function getErrors(): array { return $this->errors; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getAdminEmail(): ?string { return $this->adminEmail; }

    /** File name and hash belong to a feed run; a seed run has neither. */
    public function start(?string $fileName, ?string $sha256, int $totalRows): void
    {
        $this->fileName = $fileName;
        $this->fileSha256 = $sha256;
        $this->totalRows = $totalRows;
    }

    public function finish(string $status, array $counts, array $report, array $errors, \DateTimeImmutable $finishedAt): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown import run status');
        }
        $this->status = $status;
        $this->counts = (object) $counts;
        $this->report = $report;
        $this->errors = $errors;
        $this->finishedAt = $finishedAt;
    }

    public function performedBy(string $adminEmail): void
    {
        $this->adminEmail = $adminEmail;
    }
}
