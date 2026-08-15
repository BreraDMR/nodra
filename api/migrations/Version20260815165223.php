<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815165223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D08.1: evening installation bookings beside the order, with a preliminary and a confirmed work window';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE installation_booking (id UUID NOT NULL, order_id UUID NOT NULL, status VARCHAR(16) NOT NULL, planned_from TIMESTAMPTZ NOT NULL, planned_to TIMESTAMPTZ NOT NULL, work_from TIMESTAMPTZ DEFAULT NULL, work_to TIMESTAMPTZ DEFAULT NULL, works JSON NOT NULL, price_minor INT DEFAULT NULL, note VARCHAR(500) DEFAULT NULL, compatibility_note VARCHAR(500) DEFAULT NULL, result_note VARCHAR(500) DEFAULT NULL, cancelled_reason VARCHAR(500) DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, created_by VARCHAR(180) NOT NULL, confirmed_at TIMESTAMPTZ DEFAULT NULL, completed_at TIMESTAMPTZ DEFAULT NULL, cancelled_at TIMESTAMPTZ DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_installation_status ON installation_booking (status, planned_from)');
        $this->addSql('CREATE INDEX idx_installation_order ON installation_booking (order_id)');
        $this->addSql('ALTER TABLE installation_booking ADD CONSTRAINT FK_installation_order FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE installation_booking');
    }
}
