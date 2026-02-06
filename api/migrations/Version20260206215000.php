<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260206215000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record Czech delivery district on orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shop_order ADD district VARCHAR(120) DEFAULT \'\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shop_order DROP district');
    }
}
