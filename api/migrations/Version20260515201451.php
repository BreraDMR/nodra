<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515201451 extends AbstractMigration
{
    private const REASON = 'Demo supplier snapshot cleared (D04)';

    public function getDescription(): string
    {
        return 'D04: product_variant.stock means goods NODRA holds, clear the demo values copied from supplier snapshots';
    }

    public function up(Schema $schema): void
    {
        // each cleared amount is kept as a stock movement, down puts it back from there
        $this->addSql('INSERT INTO stock_movement (id, variant_id, delta, reason, created_at)
            SELECT gen_random_uuid(), id, -stock, :reason, NOW() FROM product_variant WHERE stock > 0', ['reason' => self::REASON]);
        $this->addSql('UPDATE product_variant SET stock = 0 WHERE stock > 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE product_variant v SET stock = v.stock - m.delta
            FROM (SELECT variant_id, SUM(delta) AS delta FROM stock_movement WHERE reason = :reason GROUP BY variant_id) m
            WHERE m.variant_id = v.id', ['reason' => self::REASON]);
        $this->addSql('DELETE FROM stock_movement WHERE reason = :reason', ['reason' => self::REASON]);
    }
}
