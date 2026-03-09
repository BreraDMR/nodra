<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260309213543 extends AbstractMigration
{
    private const LEGACY = [
        ['bags', 'Brašny', 'Taschen', 'Bags', 40],
        ['apparel', 'Oblečení', 'Bekleidung', 'Apparel', 50],
        ['lights', 'Světla', 'Beleuchtung', 'Lights', 30],
        ['accessories', 'Doplňky', 'Zubehör', 'Accessories', 20],
    ];

    public function getDescription(): string
    {
        return 'Category tree, product brand and attributes, variant identifiers, supplier offers matched to variants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE category (id UUID NOT NULL, parent_id UUID DEFAULT NULL, slug VARCHAR(60) NOT NULL, names JSONB NOT NULL, position INT DEFAULT 0 NOT NULL, active BOOLEAN DEFAULT true NOT NULL, attributes JSONB DEFAULT '[]' NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_category_slug ON category (slug)');
        $this->addSql('CREATE INDEX idx_category_parent ON category (parent_id, position)');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_category_parent FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT chk_category_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');
        // the four old fixed categories become roots so current product links keep working
        foreach (self::LEGACY as [$slug, $cs, $de, $en, $position]) {
            $this->addSql(
                'INSERT INTO category (id, slug, names, position) VALUES (gen_random_uuid(), :slug, :names, :position)',
                ['slug' => $slug, 'names' => json_encode(['cs' => $cs, 'de' => $de, 'en' => $en], JSON_UNESCAPED_UNICODE), 'position' => $position],
            );
        }

        $this->addSql('ALTER TABLE product ADD category_id UUID DEFAULT NULL');
        $this->addSql('UPDATE product p SET category_id = c.id FROM category c WHERE c.slug = p.category');
        $this->addSql('ALTER TABLE product ALTER category_id SET NOT NULL');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_product_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('DROP INDEX idx_product_browse');
        $this->addSql('ALTER TABLE product DROP category');
        $this->addSql('CREATE INDEX idx_product_browse ON product (category_id, status, featured_rank)');
        $this->addSql("ALTER TABLE product ADD brand VARCHAR(80) DEFAULT NULL, ADD attributes JSONB DEFAULT '{}' NOT NULL");
        $this->addSql('CREATE INDEX idx_product_brand ON product (brand)');

        $this->addSql("ALTER TABLE product_variant ADD mpn VARCHAR(64) DEFAULT NULL, ADD ean VARCHAR(14) DEFAULT NULL, ADD attributes JSONB DEFAULT '{}' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_variant_ean ON product_variant (ean) WHERE (ean IS NOT NULL)');
        $this->addSql('CREATE INDEX idx_variant_mpn ON product_variant (mpn)');

        $this->addSql('ALTER TABLE supplier_offer ADD variant_id UUID DEFAULT NULL, ADD seller VARCHAR(120) DEFAULT NULL, ADD lead_time_min_days SMALLINT DEFAULT NULL, ADD lead_time_max_days SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier_offer ADD CONSTRAINT FK_supplier_offer_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_supplier_offer_variant ON supplier_offer (variant_id)');
        $this->addSql('DROP INDEX uniq_supplier_offer_product_url');
        $this->addSql('CREATE UNIQUE INDEX uniq_supplier_offer_product_url ON supplier_offer (product_id, url) WHERE (variant_id IS NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_supplier_offer_variant_url ON supplier_offer (variant_id, url) WHERE (variant_id IS NOT NULL)');
        $this->addSql('ALTER TABLE supplier_offer ADD CONSTRAINT chk_supplier_offer_lead_time CHECK (lead_time_min_days IS NULL OR lead_time_max_days IS NULL OR lead_time_min_days <= lead_time_max_days)');
        $this->addSql("ALTER TABLE supplier_offer ADD CONSTRAINT chk_supplier_offer_match CHECK (verification_status <> 'matched' OR variant_id IS NOT NULL)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier_offer DROP CONSTRAINT chk_supplier_offer_match');
        $this->addSql('ALTER TABLE supplier_offer DROP CONSTRAINT chk_supplier_offer_lead_time');
        $this->addSql('DROP INDEX uniq_supplier_offer_variant_url');
        $this->addSql('DROP INDEX uniq_supplier_offer_product_url');
        // one row per product and URL survives, the old unique index can't hold per-variant rows
        $this->addSql('DELETE FROM supplier_offer a USING supplier_offer b WHERE a.product_id = b.product_id AND a.url = b.url AND a.id > b.id');
        $this->addSql('ALTER TABLE supplier_offer DROP CONSTRAINT FK_supplier_offer_variant');
        $this->addSql('ALTER TABLE supplier_offer DROP variant_id, DROP seller, DROP lead_time_min_days, DROP lead_time_max_days');
        $this->addSql('CREATE UNIQUE INDEX uniq_supplier_offer_product_url ON supplier_offer (product_id, url)');

        $this->addSql('ALTER TABLE product_variant DROP mpn, DROP ean, DROP attributes');

        $this->addSql('ALTER TABLE product ADD category VARCHAR(40) DEFAULT NULL');
        // products from newer branches fall back to accessories, the old enum had nothing closer
        $this->addSql("WITH RECURSIVE path (id, parent_id, slug, start_id) AS (
                SELECT id, parent_id, slug, id FROM category
                UNION ALL
                SELECT c.id, c.parent_id, c.slug, path.start_id FROM category c JOIN path ON c.id = path.parent_id
            )
            UPDATE product p SET category = CASE WHEN root.slug IN ('bags', 'apparel', 'lights', 'accessories') THEN root.slug ELSE 'accessories' END
            FROM path root WHERE root.start_id = p.category_id AND root.parent_id IS NULL");
        $this->addSql('ALTER TABLE product ALTER category SET NOT NULL');
        $this->addSql('DROP INDEX idx_product_browse');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_product_category');
        $this->addSql('ALTER TABLE product DROP category_id, DROP brand, DROP attributes');
        $this->addSql('CREATE INDEX idx_product_browse ON product (status, category, featured_rank)');
        $this->addSql('DROP TABLE category');
    }
}
