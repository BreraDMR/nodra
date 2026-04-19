<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Markup for a band of landed cost. A rule without category is a default rule.
 * The band is min inclusive, max exclusive, and null max means no upper limit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pricing_rule')]
#[ORM\Index(columns: ['category_id'], name: 'idx_pricing_rule_category')]
class PricingRule
{
    public const MAX_MARKUP_BP = 100_000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Category $category;

    #[ORM\Column(options: ['default' => 0])]
    private int $minCostCzkMinor;

    #[ORM\Column(nullable: true)]
    private ?int $maxCostCzkMinor;

    /** 4000 = 40 % on top of the landed cost */
    #[ORM\Column]
    private int $markupBp;

    #[ORM\Column(options: ['default' => true])]
    private bool $active;

    public function __construct(?Category $category, int $minCostCzkMinor, ?int $maxCostCzkMinor, int $markupBp, bool $active = true)
    {
        $this->id = Uuid::v7();
        $this->update($category, $minCostCzkMinor, $maxCostCzkMinor, $markupBp, $active);
    }

    public function getId(): Uuid { return $this->id; }
    public function getCategory(): ?Category { return $this->category; }
    public function getMinCostCzkMinor(): int { return $this->minCostCzkMinor; }
    public function getMaxCostCzkMinor(): ?int { return $this->maxCostCzkMinor; }
    public function getMarkupBp(): int { return $this->markupBp; }
    public function isActive(): bool { return $this->active; }

    public function update(?Category $category, int $minCostCzkMinor, ?int $maxCostCzkMinor, int $markupBp, bool $active): void
    {
        if ($minCostCzkMinor < 0 || ($maxCostCzkMinor !== null && $maxCostCzkMinor <= $minCostCzkMinor)) {
            throw new \InvalidArgumentException('The cost band needs 0 <= min < max');
        }
        if ($markupBp < 0 || $markupBp > self::MAX_MARKUP_BP) {
            throw new \InvalidArgumentException('Markup must be between 0 and 100000 basis points');
        }
        $this->category = $category;
        $this->minCostCzkMinor = $minCostCzkMinor;
        $this->maxCostCzkMinor = $maxCostCzkMinor;
        $this->markupBp = $markupBp;
        $this->active = $active;
    }
}
