<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add customer identities and an auditable, one-entry-per-order loyalty ledger.
 */
final class Version20260228171051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Google customer accounts and loyalty entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_account (id UUID NOT NULL, google_sub VARCHAR(255) NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(160) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_61EBF21AE788A50 ON customer_account (google_sub)');
        $this->addSql('CREATE TABLE loyalty_entry (id UUID NOT NULL, points INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, account_id UUID NOT NULL, shop_order_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_loyalty_order ON loyalty_entry (shop_order_id)');
        $this->addSql('CREATE INDEX IDX_81D77D0B9B6B5FBA ON loyalty_entry (account_id)');
        $this->addSql('ALTER TABLE loyalty_entry ADD CONSTRAINT FK_81D77D0B9B6B5FBA FOREIGN KEY (account_id) REFERENCES customer_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE loyalty_entry ADD CONSTRAINT FK_81D77D0B562797AE FOREIGN KEY (shop_order_id) REFERENCES shop_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE shop_order ADD account_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE shop_order ADD CONSTRAINT FK_323FC9CA9B6B5FBA FOREIGN KEY (account_id) REFERENCES customer_account (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_323FC9CA9B6B5FBA ON shop_order (account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shop_order DROP CONSTRAINT FK_323FC9CA9B6B5FBA');
        $this->addSql('ALTER TABLE loyalty_entry DROP CONSTRAINT FK_81D77D0B9B6B5FBA');
        $this->addSql('ALTER TABLE loyalty_entry DROP CONSTRAINT FK_81D77D0B562797AE');
        $this->addSql('DROP TABLE loyalty_entry');
        $this->addSql('DROP INDEX IDX_323FC9CA9B6B5FBA');
        $this->addSql('ALTER TABLE shop_order DROP account_id');
        $this->addSql('DROP TABLE customer_account');
    }
}
