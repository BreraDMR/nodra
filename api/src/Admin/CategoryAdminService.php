<?php

declare(strict_types=1);

namespace App\Admin;

use App\Catalog\AttributeSchema;
use App\Catalog\CategoryIndex;
use App\Entity\Category;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CategoryAdminService
{
    public function __construct(private EntityManagerInterface $em, private Connection $db) {}

    public function list(): array
    {
        $index = CategoryIndex::load($this->db);
        $counts = array_map('intval', $this->db->fetchAllKeyValue('SELECT category_id, COUNT(*) FROM product GROUP BY category_id'));

        return array_map(static fn (array $category): array => [
            'id' => $category['id'],
            'slug' => $category['slug'],
            'parentId' => $category['parentId'],
            'depth' => $category['depth'],
            'names' => $category['names'],
            'position' => $category['position'],
            'active' => $category['active'],
            'attributes' => AttributeSchema::forAdmin($category['attributes']),
            'effectiveAttributes' => AttributeSchema::forAdmin($index->effectiveAttributes($category['id'])),
            'productCount' => $counts[$category['id']] ?? 0,
        ], $index->flatTree());
    }

    public function create(CategoryWriteRequest $input): array
    {
        $this->assertSlugFree($input->slug);
        $parent = $this->parent($input->parentId, null);
        $definitions = $this->definitions($input, $parent, null);
        $category = new Category($input->slug, $this->names($input), $parent, $input->position);
        $category->update($input->slug, $this->names($input), $parent, $input->position, $input->active, $definitions);
        $this->em->persist($category);
        $this->em->flush();

        return ['id' => $category->getId()->toRfc4122()];
    }

    public function update(string $id, CategoryWriteRequest $input): ?array
    {
        $category = Uuid::isValid($id) ? $this->em->find(Category::class, Uuid::fromString($id)) : null;
        if ($category === null) {
            return null;
        }
        $this->assertSlugFree($input->slug, $id);
        $parent = $this->parent($input->parentId, $id);
        $definitions = $this->definitions($input, $parent, $id);
        $index = CategoryIndex::load($this->db);
        // published products must stay reachable: the branch can't be deactivated or moved under a hidden parent while it has any
        if (!$input->active || ($parent !== null && !$index->isVisible($parent->getId()->toRfc4122()))) {
            $published = (int) $this->db->fetchOne("SELECT COUNT(*) FROM product WHERE status = 'published' AND category_id IN (:ids)", ['ids' => $index->subtreeIds($id)], ['ids' => ArrayParameterType::STRING]);
            if ($published > 0) {
                throw new \DomainException(sprintf('%d published products are in this category or below it; move or unpublish them first', $published));
            }
        }
        $category->update($input->slug, $this->names($input), $parent, $input->position, $input->active, $definitions);
        $this->em->flush();

        return ['id' => $id];
    }

    private function parent(?string $parentId, ?string $selfId): ?Category
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }
        $parent = Uuid::isValid($parentId) ? $this->em->find(Category::class, Uuid::fromString($parentId)) : null;
        if ($parent === null) {
            throw new \InvalidArgumentException('Parent category not found');
        }
        if ($selfId !== null && in_array($parentId, CategoryIndex::load($this->db)->subtreeIds($selfId), true)) {
            throw new \DomainException('A category cannot move under itself or one of its subcategories');
        }

        return $parent;
    }

    private function definitions(CategoryWriteRequest $input, ?Category $parent, ?string $selfId): array
    {
        $index = CategoryIndex::load($this->db);
        $inherited = $parent === null ? [] : array_column($index->effectiveAttributes($parent->getId()->toRfc4122()), 'key');
        $definitions = AttributeSchema::normalizeDefinitions($input->attributes, $inherited);
        if ($selfId !== null) {
            // a key added here, or inherited from a new parent, must not already exist further down the tree either
            $taken = [...$inherited, ...array_column($definitions, 'key')];
            foreach ($index->subtreeIds($selfId) as $childId) {
                if ($childId === $selfId) {
                    continue;
                }
                $clash = array_intersect($taken, array_column($index->get($childId)['attributes'], 'key'));
                if ($clash !== []) {
                    throw new \InvalidArgumentException(sprintf('Attribute "%s" is already defined in subcategory "%s"', reset($clash), $index->get($childId)['slug']));
                }
            }
        }

        return $definitions;
    }

    private function assertSlugFree(string $slug, ?string $exceptId = null): void
    {
        $existing = $this->db->fetchOne('SELECT id FROM category WHERE slug = :slug', ['slug' => $slug]);
        if ($existing !== false && $existing !== $exceptId) {
            throw new \DomainException('A category with this slug already exists');
        }
    }

    private function names(CategoryWriteRequest $input): array
    {
        return ['cs' => trim($input->nameCs), 'de' => trim($input->nameDe), 'en' => trim($input->nameEn)];
    }
}
