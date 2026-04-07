<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Tests\Support\ApiTestCase;

final class CategoryTreeApiTest extends ApiTestCase
{
    public function testTreeShowsActiveCategoriesAndCountsPublishedProductsBelowThem(): void
    {
        $b = $this->builder();
        $root = $b->category('t-parts', names: ['cs' => 'Díly', 'de' => 'Teile', 'en' => 'Parts']);
        $chains = $b->category('t-chains', $root);
        $pins = $b->category('t-chain-pins', $chains);
        $hidden = $b->category('t-hidden', $root, active: false);
        $b->sellable('t-root-item', $root);
        $b->sellable('t-chain-a', $chains);
        $b->sellable('t-chain-draft', $chains, status: 'draft');
        $b->sellable('t-pin', $pins);
        $b->sellable('t-hidden-item', $hidden);

        $tree = $this->getJson('/api/categories', ['locale' => 'cs']);

        self::assertResponseIsSuccessful();
        $parts = $this->node($tree, 't-parts');
        self::assertSame('Díly', $parts['name']);
        self::assertSame($root->getId()->toRfc4122(), $parts['id']);
        // own product + chains (one published) + pins; the hidden child and the draft don't count
        self::assertSame(3, $parts['count']);
        self::assertSame(['t-chains'], array_column($parts['children'], 'slug'));
        $chainsNode = $parts['children'][0];
        self::assertSame(2, $chainsNode['count']);
        self::assertSame([['id' => $pins->getId()->toRfc4122(), 'slug' => 't-chain-pins', 'name' => 't-chain-pins (cs)', 'count' => 1, 'children' => []]], $chainsNode['children']);
    }

    public function testInactiveRootHidesItsWholeBranch(): void
    {
        $b = $this->builder();
        $root = $b->category('t-off-root', active: false);
        $b->sellable('t-off-item', $b->category('t-off-child', $root));

        $tree = $this->getJson('/api/categories');

        self::assertNotContains('t-off-root', array_column($tree, 'slug'));
        self::assertNull($this->find($tree, 't-off-child'));
    }

    private function node(array $tree, string $slug): array
    {
        $node = $this->find($tree, $slug);
        self::assertNotNull($node, sprintf('Category "%s" is missing from the tree', $slug));

        return $node;
    }

    private function find(array $nodes, string $slug): ?array
    {
        foreach ($nodes as $node) {
            if ($node['slug'] === $slug) {
                return $node;
            }
            if (($found = $this->find($node['children'], $slug)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
