<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729200613 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D08.4: the after-sale claims registry — returns and warranty cases as their own records beside the order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE return_claim (id UUID NOT NULL, order_id UUID NOT NULL, item_id UUID DEFAULT NULL, number VARCHAR(20) NOT NULL, kind VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, note VARCHAR(500) DEFAULT NULL, refund_amount_minor INT DEFAULT NULL, resolution VARCHAR(16) DEFAULT NULL, resolution_note VARCHAR(500) DEFAULT NULL, opened_at TIMESTAMPTZ NOT NULL, opened_by VARCHAR(180) NOT NULL, handover_date DATE NOT NULL, window_end DATE NOT NULL, due_at DATE DEFAULT NULL, resolved_at TIMESTAMPTZ DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_return_claim_number ON return_claim (number)');
        $this->addSql('CREATE INDEX idx_return_claim_order ON return_claim (order_id)');
        $this->addSql('CREATE INDEX idx_return_claim_status ON return_claim (status, opened_at)');
        $this->addSql('ALTER TABLE return_claim ADD CONSTRAINT FK_return_claim_order FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE return_claim ADD CONSTRAINT FK_return_claim_item FOREIGN KEY (item_id) REFERENCES order_item (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE return_claim');
    }
}
