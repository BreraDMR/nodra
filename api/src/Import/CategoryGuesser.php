<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalog\CategoryIndex;

/**
 * Guesses the NODRA category of a feed row from the merchant's category path. Imports never create
 * categories: a path nothing matches leaves the row parked as unknown. Returns the CategoryIndex row
 * (id, slug, names, …) or null.
 */
final class CategoryGuesser
{
    private CategoryIndex $index;

    /** @var array<string, string> normalised token (slug or any name) => category id */
    private array $byToken;

    public function __construct(CategoryIndex $index)
    {
        $this->index = $index;
        $this->byToken = [];
        foreach ($index->flatTree() as $category) {
            foreach ([$category['slug'], ...array_values($category['names'])] as $name) {
                $token = self::token($name);
                if ($token !== '' && !isset($this->byToken[$token])) {
                    $this->byToken[$token] = $category['id'];
                }
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function guess(?string $categoryPath, ?string $merchantCategory): ?array
    {
        $segments = [];
        foreach ([$categoryPath, $merchantCategory] as $source) {
            foreach (preg_split('/[>\/|]/', (string) $source) ?: [] as $segment) {
                if (trim($segment) !== '') {
                    $segments[] = $segment;
                }
            }
        }
        // the deepest segment is the most specific, so walk the path from its end
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $id = $this->byToken[self::token($segments[$i])] ?? null;
            if ($id !== null) {
                return $this->index->get($id);
            }
        }

        return null;
    }

    /** Lowercase letters, numbers and spaces; everything else is a separator, so "brake-pads" matches "Brake pads". */
    private static function token(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return trim((string) preg_replace('/[^a-z0-9\x{00c0}-\x{024f}]+/u', ' ', $name));
    }
}
