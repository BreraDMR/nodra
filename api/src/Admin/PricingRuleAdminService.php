<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Category;
use App\Entity\PricingRule;
use App\Pricing\PricingData;
use App\Pricing\RuleFacts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class PricingRuleAdminService
{
    public function __construct(private EntityManagerInterface $em, private PricingData $data) {}

    public function list(): array
    {
        return array_map(static fn (array $row): array => RuleFacts::fromRow($row)->toArray(), $this->data->rules());
    }

    public function create(PricingRuleWriteRequest $input): array
    {
        $category = $this->category($input->categoryId);
        $this->assertNoOverlap($category, $input, null);
        $rule = new PricingRule($category, $input->minCostCzkMinor, $input->maxCostCzkMinor, $input->markupBp, $input->active);
        $this->em->persist($rule);
        $this->em->flush();

        return ['id' => $rule->getId()->toRfc4122()];
    }

    public function update(string $id, PricingRuleWriteRequest $input): ?array
    {
        $rule = $this->em->find(PricingRule::class, Uuid::fromString($id));
        if ($rule === null) {
            return null;
        }
        $category = $this->category($input->categoryId);
        $this->assertNoOverlap($category, $input, $rule->getId()->toRfc4122());
        $rule->update($category, $input->minCostCzkMinor, $input->maxCostCzkMinor, $input->markupBp, $input->active);
        $this->em->flush();

        return ['id' => $id];
    }

    public function delete(string $id): bool
    {
        $rule = $this->em->find(PricingRule::class, Uuid::fromString($id));
        if ($rule === null) {
            return false;
        }
        $this->em->remove($rule);
        $this->em->flush();

        return true;
    }

    private function category(?string $id): ?Category
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->em->find(Category::class, Uuid::fromString($id)) ?? throw new \InvalidArgumentException('Unknown category');
    }

    /** Active bands of one category (or of the default rules) may not overlap, otherwise the rule for a cost would be ambiguous. */
    private function assertNoOverlap(?Category $category, PricingRuleWriteRequest $input, ?string $exceptId): void
    {
        if ($input->maxCostCzkMinor !== null && $input->maxCostCzkMinor <= $input->minCostCzkMinor) {
            throw new \InvalidArgumentException('maxCostCzkMinor must be above minCostCzkMinor');
        }
        if (!$input->active) {
            return;
        }
        $categoryId = $category?->getId()->toRfc4122();
        foreach ($this->data->rules() as $row) {
            $other = RuleFacts::fromRow($row);
            if ($other->id === $exceptId || !$other->active || $other->categoryId !== $categoryId) {
                continue;
            }
            if ($other->overlaps($input->minCostCzkMinor, $input->maxCostCzkMinor)) {
                throw new \DomainException(sprintf(
                    'The band overlaps the active rule %s–%s Kč of the same category',
                    intdiv($other->minCostCzkMinor, 100),
                    $other->maxCostCzkMinor === null ? '∞' : intdiv($other->maxCostCzkMinor, 100),
                ));
            }
        }
    }
}
