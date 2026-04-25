<?php

declare(strict_types=1);

namespace App\Pricing;

final readonly class RuleFacts
{
    public function __construct(
        public string $id,
        public ?string $categoryId,
        public int $minCostCzkMinor,
        public ?int $maxCostCzkMinor,
        public int $markupBp,
        public ?string $categorySlug = null,
        public bool $active = true,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            $row['id'], $row['category_id'], (int) $row['min_cost_czk_minor'],
            $row['max_cost_czk_minor'] === null ? null : (int) $row['max_cost_czk_minor'],
            (int) $row['markup_bp'], $row['category_slug'] ?? null, (bool) $row['active'],
        );
    }

    /** min inclusive, max exclusive */
    public function contains(int $cost): bool
    {
        return $cost >= $this->minCostCzkMinor && ($this->maxCostCzkMinor === null || $cost < $this->maxCostCzkMinor);
    }

    public function overlaps(int $min, ?int $max): bool
    {
        return ($this->maxCostCzkMinor === null || $min < $this->maxCostCzkMinor) && ($max === null || $this->minCostCzkMinor < $max);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'categoryId' => $this->categoryId, 'categorySlug' => $this->categorySlug,
            'minCostCzkMinor' => $this->minCostCzkMinor, 'maxCostCzkMinor' => $this->maxCostCzkMinor,
            'markupBp' => $this->markupBp, 'active' => $this->active,
        ];
    }
}
