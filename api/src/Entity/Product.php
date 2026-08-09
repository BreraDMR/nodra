<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product')]
#[ORM\Index(columns: ['category_id', 'status', 'featured_rank'], name: 'idx_product_browse')]
#[ORM\Index(columns: ['brand'], name: 'idx_product_brand')]
// the partial index (status = 'published') is created in migration Version20260809120856;
// the ORM copy keeps the schema validator in sync with its columns
#[ORM\Index(columns: ['featured_rank', 'slug'], name: 'idx_product_published')]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120, unique: true)]
    private string $slug;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Category $category;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $brand = null;

    /**
     * Attribute values keyed by definition key. Held as an object so an empty set is stored as {}, not [];
     * rows written before that may still come back as an array.
     *
     * @var \stdClass|array<string, string>
     */
    #[ORM\Column(type: Types::JSONB_OBJECT, options: ['default' => '{}'])]
    private \stdClass|array $attributes;

    /** First time brand or attributes were written, by the import or the admin. Null means never, so the import may fill them. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $describedAt = null;

    #[ORM\Column(length: 16)]
    private string $status = 'draft';

    /** @var array<string, array{name: string, short: string, description: string, details: list<string>, inBox?: string}> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $copy = [];

    #[ORM\Column(length: 255)]
    private string $image;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $images = [];

    /** Feed image URLs, kept as source references for D07; the web never displays them. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $sourceImages = [];

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $badge = null;

    #[ORM\Column]
    private int $featuredRank = 100;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $slug, Category $category, array $copy, string $image)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->category = $category;
        $this->copy = $copy;
        $this->image = $image;
        $this->images = [$image];
        $this->attributes = new \stdClass();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getCategory(): Category { return $this->category; }
    public function getBrand(): ?string { return $this->brand; }
    /** @return array<string, string> */
    public function getAttributes(): array { return (array) $this->attributes; }
    public function getStatus(): string { return $this->status; }
    public function getCopy(): array { return $this->copy; }
    public function getImage(): string { return $this->image; }
    public function getImages(): array { return $this->images; }
    /** @return list<string> */
    public function getSourceImages(): array { return $this->sourceImages; }
    /** @param list<string> $urls */
    public function setSourceImages(array $urls): void
    {
        $this->sourceImages = array_values(array_unique(array_filter(array_map('trim', $urls))));
    }
    public function getBadge(): ?string { return $this->badge; }
    public function getFeaturedRank(): int { return $this->featuredRank; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDescribedAt(): ?\DateTimeImmutable { return $this->describedAt; }

    public function update(string $slug, Category $category, array $copy, string $image, array $images, ?string $badge, int $featuredRank, string $status): void
    {
        $this->slug = $slug;
        $this->category = $category;
        $this->copy = $copy;
        $this->image = $image;
        $this->images = $images;
        $this->badge = $badge;
        $this->featuredRank = $featuredRank;
        $this->status = $status;
    }

    public function describe(?string $brand, array $attributes): void
    {
        $this->brand = $brand;
        $this->attributes = (object) $attributes;
        $this->describedAt ??= new \DateTimeImmutable();
    }
}
