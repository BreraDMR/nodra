<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260105193402 extends AbstractMigration
{
    public function getDescription(): string { return 'Bind idempotency keys to the original checkout payload'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE shop_order ADD request_hash VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE shop_order ALTER request_hash DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shop_order DROP request_hash');
    }
}
