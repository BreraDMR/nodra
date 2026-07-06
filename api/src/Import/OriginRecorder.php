<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportFieldOrigin;
use App\Entity\ImportRun;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the origin rows of one write: either a run (seed, feed, merge) or an admin edit.
 * The field-origin UI (D03.3) reads them back through OriginsQuery.
 */
final class OriginRecorder
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Security $security,
    ) {}

    /** @param list<string> $fields */
    public function forAdmin(string $entityType, Uuid $entityId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $now = $this->clock->now();
        $email = $this->security->getUser()?->getUserIdentifier() ?? 'system';
        foreach ($fields as $field) {
            $this->em->persist(ImportFieldOrigin::admin($entityType, $entityId, $field, $email, $now));
        }
        $this->em->flush();
    }

    /** @param list<string> $fields */
    public function forRun(ImportRun $run, string $entityType, Uuid $entityId, array $fields, \DateTimeImmutable $now): void
    {
        foreach ($fields as $field) {
            $this->em->persist(ImportFieldOrigin::fromRun($run, $entityType, $entityId, $field, $now));
        }
    }
}
