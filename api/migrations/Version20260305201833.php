<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305201833 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index multilingual product name search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        foreach (['cs', 'de', 'en'] as $locale) {
            $this->addSql("CREATE INDEX idx_product_name_{$locale}_trgm ON product USING GIN ((copy -> '$locale' ->> 'name') gin_trgm_ops)");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['cs', 'de', 'en'] as $locale) {
            $this->addSql("DROP INDEX idx_product_name_{$locale}_trgm");
        }
    }
}
