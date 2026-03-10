<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Snapshot of the whole category table. It's small, so reading it at once is cheaper
 * than walking the tree with recursive queries every time.
 */
final class CategoryIndex
{
    /** @var array<string, array{id: string, parentId: ?string, slug: string, names: array<string, string>, position: int, active: bool, attributes: list<array<string, mixed>>}> */
    private array $byId = [];

    /** @var array<string, string> slug => id */
    private array $idsBySlug = [];

    /** @var array<string, list<string>> parent id ('' for roots) => child ids in display order */
    private array $children = [];

    public static function load(Connection $db): self
    {
        $index = new self();
        $rows = $db->fetchAllAssociative('SELECT id, parent_id, slug, names, position, active, attributes FROM category ORDER BY position, slug');
        foreach ($rows as $row) {
            $index->byId[$row['id']] = [
                'id' => $row['id'],
                'parentId' => $row['parent_id'],
                'slug' => $row['slug'],
                'names' => json_decode($row['names'], true, flags: JSON_THROW_ON_ERROR),
                'position' => (int) $row['position'],
                'active' => (bool) $row['active'],
                'attributes' => json_decode($row['attributes'], true, flags: JSON_THROW_ON_ERROR),
            ];
            $index->idsBySlug[$row['slug']] = $row['id'];
            $index->children[$row['parent_id'] ?? ''][] = $row['id'];
        }

        return $index;
    }

    public function get(string $id): ?array
    {
        return $this->byId[$id] ?? null;
    }

    public function bySlug(string $slug): ?array
    {
        return isset($this->idsBySlug[$slug]) ? $this->byId[$this->idsBySlug[$slug]] : null;
    }

    /** @return list<array> rows ordered as a depth-first tree, each with its depth */
    public function flatTree(): array
    {
        $rows = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$rows): void {
            foreach ($this->children[$parent] ?? [] as $id) {
                $rows[] = $this->byId[$id] + ['depth' => $depth];
                $walk($id, $depth + 1);
            }
        };
        $walk('', 0);

        return $rows;
    }

    /** @return list<string> the category and all categories below it */
    public function subtreeIds(string $id, bool $activeOnly = false): array
    {
        $ids = [];
        $stack = [$id];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (!isset($this->byId[$current]) || ($activeOnly && !$this->byId[$current]['active'])) {
                continue;
            }
            $ids[] = $current;
            foreach ($this->children[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $ids;
    }

    /** @return list<array> categories from the root down to the given one */
    public function path(string $id): array
    {
        $path = [];
        $seen = [];
        for ($current = $id; $current !== null && isset($this->byId[$current]) && !isset($seen[$current]); $current = $this->byId[$current]['parentId']) {
            $seen[$current] = true;
            array_unshift($path, $this->byId[$current]);
        }

        return $path;
    }

    /** @return list<array<string, mixed>> parent definitions first, then the category's own */
    public function effectiveAttributes(string $id): array
    {
        $definitions = [];
        foreach ($this->path($id) as $category) {
            array_push($definitions, ...$category['attributes']);
        }

        return $definitions;
    }

    /** Active only if the whole path up to the root is active. */
    public function isVisible(string $id): bool
    {
        $path = $this->path($id);

        return $path !== [] && array_filter($path, static fn (array $category): bool => !$category['active']) === [];
    }

    /**
     * Nested public tree of visible categories.
     *
     * @param array<string, int> $directCounts published products per category id
     */
    public function publicTree(string $locale, array $directCounts): array
    {
        $build = function (string $parent) use (&$build, $locale, $directCounts): array {
            $nodes = [];
            foreach ($this->children[$parent] ?? [] as $id) {
                $category = $this->byId[$id];
                if (!$category['active']) {
                    continue;
                }
                $children = $build($id);
                $count = ($directCounts[$id] ?? 0) + array_sum(array_column($children, 'count'));
                $nodes[] = ['id' => $id, 'slug' => $category['slug'], 'name' => $category['names'][$locale], 'count' => $count, 'children' => $children];
            }

            return $nodes;
        };

        return $build('');
    }
}
