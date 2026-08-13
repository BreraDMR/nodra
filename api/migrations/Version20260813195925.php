<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813195925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Q15: claims record the day the customer contacted, refunds carry the tag of the claim they settle';
    }

    public function up(Schema $schema): void
    {
        // old records keep their meaning: the registry only knew the entry date, so the customer contact
        // is backfilled from it; claims that never got a due date take it from the same day
        $this->addSql('ALTER TABLE return_claim ADD COLUMN contacted_on DATE DEFAULT NULL');
        $this->addSql("UPDATE return_claim SET contacted_on = (opened_at AT TIME ZONE 'Europe/Prague')::date");
        $this->addSql("UPDATE return_claim SET due_at = contacted_on + (CASE WHEN kind = 'return' THEN 14 ELSE 30 END) WHERE due_at IS NULL");
        $this->addSql('ALTER TABLE return_claim ALTER COLUMN contacted_on SET NOT NULL');

        // one refund settles one claim: the tag lives on the ledger entry
        $this->addSql('ALTER TABLE payment ADD COLUMN claim_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_payment_claim ON payment (claim_id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_payment_claim FOREIGN KEY (claim_id) REFERENCES return_claim (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_payment_claim');
        $this->addSql('DROP INDEX idx_payment_claim');
        $this->addSql('ALTER TABLE payment DROP COLUMN claim_id');
        $this->addSql('ALTER TABLE return_claim DROP COLUMN contacted_on');
    }
}
