<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'category')]
#[ORM\Index(columns: ['parent_id', 'position'], name: 'idx_category_parent')]
#[ORM\UniqueConstraint(name: 'uniq_category_slug', columns: ['slug'])]
class Category
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Category $parent = null;

    #[ORM\Column(length: 60)]
    private string $slug;

    /** @var array{cs: string, de: string, en: string} */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $names;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** @var list<array<string, mixed>> own attribute definitions, see AttributeSchema */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $attributes = [];

    /** When the attribute definitions were first written, by the seed or the admin. Null means the seed may still fill an empty category; the admin clearing them never resets it. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $seededAt = null;

    public function __construct(string $slug, array $names, ?Category $parent = null, int $position = 0)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->names = $names;
        $this->parent = $parent;
        $this->position = $position;
    }

    public function getId(): Uuid { return $this->id; }
    public function getParent(): ?Category { return $this->parent; }
    public function getSlug(): string { return $this->slug; }
    public function getNames(): array { return $this->names; }
    public function getPosition(): int { return $this->position; }
    public function isActive(): bool { return $this->active; }
    public function getAttributes(): array { return $this->attributes; }

    public function update(string $slug, array $names, ?Category $parent, int $position, bool $active, array $attributes): void
    {
        $this->slug = $slug;
        $this->names = $names;
        $this->parent = $parent;
        $this->position = $position;
        $this->active = $active;
        $this->attributes = $attributes;
    }

    /** Whoever writes definitions — seed or admin — takes them over; the seed stops refilling after this. */
    public function markSeeded(\DateTimeImmutable $at): void
    {
        $this->seededAt = $at;
    }

    public function getSeededAt(): ?\DateTimeImmutable
    {
        return $this->seededAt;
    }
}
