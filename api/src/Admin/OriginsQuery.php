<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\ImportFieldOrigin;
use App\Entity\ImportRun;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the latest origin of every written field for the entities the admin is looking at (D03.3):
 * kind seed | feed | admin, the run behind a feed or seed write, the admin behind a manual write.
 */
final class OriginsQuery
{
    private const TYPES = [ImportFieldOrigin::ENTITY_PRODUCT, ImportFieldOrigin::ENTITY_VARIANT, ImportFieldOrigin::ENTITY_OFFER];
    private const MAX_IDS = 50;

    public function __construct(private Connection $db) {}

    /** @param list<string> $ids */
    public function latest(string $type, array $ids): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('type must be one of %s', implode(', ', self::TYPES)));
        }
        if (count($ids) > self::MAX_IDS) {
            throw new \InvalidArgumentException(sprintf('At most %d ids per request', self::MAX_IDS));
        }
        foreach ($ids as $id) {
            if (!Uuid::isValid($id)) {
                throw new \InvalidArgumentException('ids must be UUIDs');
            }
        }
        if ($ids === []) {
            return ['origins' => new \stdClass()];
        }

        // latest write wins: written_at first, then the row id, so a same-timestamp write keeps order
        $rows = $this->db->fetchAllAssociative(
            'SELECT o.entity_id, o.field, o.written_at, o.admin_email, r.id AS run_id, r.source AS run_source, r.supplier
             FROM import_field_origin o
             LEFT JOIN import_run r ON r.id = o.run_id
             WHERE o.entity_type = :type AND o.entity_id IN (:ids)
             ORDER BY o.entity_id, o.field, o.written_at DESC, o.id DESC',
            ['type' => $type, 'ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $origins = [];
        foreach ($rows as $row) {
            $entityId = $row['entity_id'];
            $field = $row['field'];
            if (isset($origins[$entityId][$field])) {
                continue;
            }
            $origins[$entityId][$field] = [
                'kind' => $row['run_id'] === null ? 'admin' : ($row['run_source'] === ImportRun::SOURCE_SEED ? 'seed' : 'feed'),
                'runId' => $row['run_id'],
                'runSource' => $row['run_source'],
                'supplier' => $row['supplier'],
                'adminEmail' => $row['admin_email'],
                'writtenAt' => (new \DateTimeImmutable($row['written_at']))->format(\DATE_ATOM),
            ];
        }
        if ($origins === []) {
            $origins = new \stdClass();
        }

        return ['origins' => $origins];
    }
}
