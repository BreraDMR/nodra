<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A rating of the product's model as an outside source shows it (D07.3). Kept apart from any
 * NODRA review; no rating is ever scraped or invented — an admin types in what they saw at
 * the source, and the row keeps the model name and the day they checked it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'external_rating')]
#[ORM\Index(columns: ['product_id'], name: 'idx_external_rating_product')]
#[ORM\UniqueConstraint(name: 'uniq_external_rating_source_model', columns: ['product_id', 'source', 'model'])]
class ExternalRating
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    /** where the rating stands, e.g. bike-components or heureka; lowercase */
    #[ORM\Column(length: 60)]
    private string $source;

    /** the model designation as the source names it, may differ from the NODRA name */
    #[ORM\Column(length: 160)]
    private string $model;

    /** the source's number, e.g. 4.5 */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3)]
    private string $rating;

    /** what the number is out of, e.g. 5 or 100 */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3)]
    private string $ratingScale = '5';

    /** how many reviews the rating counts at the source */
    #[ORM\Column]
    private int $ratingCount;

    /** the Prague day an admin saw the rating at the source */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $checkedAt;

    /** the page the rating was seen on, admin-only reference */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Product $product, string $source, string $model, string $rating, string $ratingScale, int $ratingCount, \DateTimeImmutable $checkedAt, ?string $url)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-_.]{1,59}$/', $source)) {
            throw new \InvalidArgumentException('A source is a short lowercase slug');
        }
        if ((float) $rating < 0 || (float) $ratingScale <= 0 || (float) $rating > (float) $ratingScale) {
            throw new \InvalidArgumentException('A rating must sit between zero and its scale');
        }
        if ($ratingCount < 0) {
            throw new \InvalidArgumentException('A rating count cannot be negative');
        }
        $this->id = Uuid::v7();
        $this->product = $product;
        $this->source = $source;
        $this->model = $model;
        $this->rating = $rating;
        $this->ratingScale = $ratingScale;
        $this->ratingCount = $ratingCount;
        $this->checkedAt = $checkedAt;
        $this->url = $url;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function update(string $rating, string $ratingScale, int $ratingCount, \DateTimeImmutable $checkedAt, ?string $url): void
    {
        if ((float) $rating < 0 || (float) $ratingScale <= 0 || (float) $rating > (float) $ratingScale) {
            throw new \InvalidArgumentException('A rating must sit between zero and its scale');
        }
        if ($ratingCount < 0) {
            throw new \InvalidArgumentException('A rating count cannot be negative');
        }
        $this->rating = $rating;
        $this->ratingScale = $ratingScale;
        $this->ratingCount = $ratingCount;
        $this->checkedAt = $checkedAt;
        $this->url = $url;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getSource(): string { return $this->source; }
    public function getModel(): string { return $this->model; }
    public function getRating(): string { return $this->rating; }
    public function getRatingScale(): string { return $this->ratingScale; }
    public function getRatingCount(): int { return $this->ratingCount; }
    public function getCheckedAt(): \DateTimeImmutable { return $this->checkedAt; }
    public function getUrl(): ?string { return $this->url; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
