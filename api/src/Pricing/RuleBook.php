<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Active pricing rules plus the category tree. The rule for a cost is the one on the nearest
 * category up the tree whose band contains it; if no category has one, the default rule's.
 */
final class RuleBook
{
    /** @var array<string, list<RuleFacts>> category id ('' for default rules) => rules */
    private array $byCategory = [];

    /**
     * @param list<RuleFacts> $rules inactive ones are ignored
     * @param array<string, ?string> $parents category id => parent id
     */
    public function __construct(array $rules, private array $parents)
    {
        foreach ($rules as $rule) {
            if ($rule->active) {
                $this->byCategory[$rule->categoryId ?? ''][] = $rule;
            }
        }
    }

    public function find(string $categoryId, int $landedCostCzk): ?RuleFacts
    {
        $seen = [];
        for ($current = $categoryId; $current !== null && !isset($seen[$current]); $current = $this->parents[$current] ?? null) {
            $seen[$current] = true;
            if (($rule = $this->inBand($this->byCategory[$current] ?? [], $landedCostCzk)) !== null) {
                return $rule;
            }
        }

        return $this->inBand($this->byCategory[''] ?? [], $landedCostCzk);
    }

    /** @param list<RuleFacts> $rules */
    private function inBand(array $rules, int $cost): ?RuleFacts
    {
        foreach ($rules as $rule) {
            if ($rule->contains($cost)) {
                return $rule;
            }
        }

        return null;
    }
}
