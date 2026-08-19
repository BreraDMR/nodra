<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819213717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Q19: count every claim settle deadline from the day the customer contacted, not from the day it was entered';
    }

    public function up(Schema $schema): void
    {
        // claims entered before Q15 keep a deadline counted from the day the admin recorded them; the ČOI rule
        // counts from the day the customer lodged the case, so every deadline moves onto the contact day,
        // the already correct ones included (the recomputation is idempotent)
        $this->addSql("UPDATE return_claim SET due_at = contacted_on + (CASE WHEN kind = 'return' THEN 14 ELSE 30 END)");
    }

    public function down(Schema $schema): void
    {
        // the pre-Q15 rule counted from the admin's day; the registry only recorded opened_at, so down takes that
        // day as the best the stored data can offer — for the old records it equals the contact day
        $this->addSql("UPDATE return_claim SET due_at = (opened_at AT TIME ZONE 'Europe/Prague')::date + (CASE WHEN kind = 'return' THEN 14 ELSE 30 END)");
    }
}
