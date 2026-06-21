<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One field an import run wrote: the origin the field-origin UI (D03.3) shows.
 * entity is 'product', 'variant' or 'offer'; field names follow the import contract.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_field_origin')]
#[ORM\Index(columns: ['entity_type', 'entity_id'], name: 'idx_import_origin_entity')]
#[ORM\Index(columns: ['run_id'], name: 'idx_import_origin_run')]
class ImportFieldOrigin
{
    public const ENTITY_PRODUCT = 'product';
    public const ENTITY_VARIANT = 'variant';
    public const ENTITY_OFFER = 'offer';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ImportRun::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ImportRun $run;

    #[ORM\Column(length: 32)]
    private string $entityType;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $entityId;

    #[ORM\Column(length: 64)]
    private string $field;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $writtenAt;

    public function __construct(ImportRun $run, string $entityType, Uuid $entityId, string $field, \DateTimeImmutable $writtenAt)
    {
        if (!in_array($entityType, [self::ENTITY_PRODUCT, self::ENTITY_VARIANT, self::ENTITY_OFFER], true)) {
            throw new \InvalidArgumentException('Unknown import origin entity');
        }
        $this->id = Uuid::v7();
        $this->run = $run;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->field = $field;
        $this->writtenAt = $writtenAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getRun(): ImportRun { return $this->run; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityId(): Uuid { return $this->entityId; }
    public function getField(): string { return $this->field; }
    public function getWrittenAt(): \DateTimeImmutable { return $this->writtenAt; }
}
