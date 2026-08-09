<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The schema validator cannot express partial indexes, so the D09.3 indexes
 * become plain ones: same columns, same query plans, no validator drift.
 */
final class Version20260809125423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D09.3: make the queue and catalogue indexes plain so the schema validator agrees';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_order_item_active');
        $this->addSql('CREATE INDEX idx_order_item_active ON order_item (order_id, procurement_status)');
        $this->addSql('DROP INDEX idx_product_published');
        $this->addSql('CREATE INDEX idx_product_published ON product (featured_rank, slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_product_published');
        $this->addSql("CREATE INDEX idx_product_published ON product (featured_rank, slug) WHERE status = 'published'");
        $this->addSql('DROP INDEX idx_order_item_active');
        $this->addSql("CREATE INDEX idx_order_item_active ON order_item (order_id, procurement_status) WHERE state = 'active'");
    }
}
