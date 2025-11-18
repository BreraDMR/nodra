<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product')]
#[ORM\Index(columns: ['status', 'category', 'featured_rank'], name: 'idx_product_browse')]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120, unique: true)]
    private string $slug;

    #[ORM\Column(length: 40)]
    private string $category;

    #[ORM\Column(length: 16)]
    private string $status = 'draft';

    /** @var array<string, array{name: string, short: string, description: string, details: list<string>}> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $copy = [];

    #[ORM\Column(length: 255)]
    private string $image;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $images = [];

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $badge = null;

    #[ORM\Column]
    private int $featuredRank = 100;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $slug, string $category, array $copy, string $image)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->category = $category;
        $this->copy = $copy;
        $this->image = $image;
        $this->images = [$image];
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getCategory(): string { return $this->category; }
    public function getStatus(): string { return $this->status; }
    public function getCopy(): array { return $this->copy; }
    public function getImage(): string { return $this->image; }
    public function getImages(): array { return $this->images; }
    public function getBadge(): ?string { return $this->badge; }
    public function getFeaturedRank(): int { return $this->featuredRank; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function update(string $slug, string $category, array $copy, string $image, array $images, ?string $badge, int $featuredRank, string $status): void
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
}
