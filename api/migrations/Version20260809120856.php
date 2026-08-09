<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809120856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D09.3: indexes behind the EXPLAIN of the real queue and catalogue queries';
    }

    public function up(Schema $schema): void
    {
        // the work queues and the to-purchase screen check every order's active lines:
        // an EXISTS per order turns into one index lookup instead of a line scan
        $this->addSql("CREATE INDEX idx_order_item_active ON order_item (order_id, procurement_status) WHERE state = 'active'");
        // the storefront lists published products by featured rank; the partial index
        // keeps the default sort cheap as the catalogue grows past the first hundred cards
        $this->addSql("CREATE INDEX idx_product_published ON product (featured_rank, slug) WHERE status = 'published'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_order_item_active');
        $this->addSql('DROP INDEX idx_product_published');
    }
}
