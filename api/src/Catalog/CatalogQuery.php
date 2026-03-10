<?php

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CatalogQuery
{
    /**
     * @param array<string, string> $attr attribute key => comma-separated accepted values
     */
    public function __construct(
        #[Assert\Choice(choices: ['cs', 'de', 'en'])]
        public string $locale = 'en',
        public ?string $category = null,
        #[Assert\Length(max: 80)]
        public ?string $q = null,
        #[Assert\Choice(choices: ['featured', 'price_asc', 'price_desc', 'newest'])]
        public string $sort = 'featured',
        #[Assert\Positive]
        public int $page = 1,
        public bool $availableOnly = false,
        #[Assert\Length(max: 80)]
        public ?string $brand = null,
        #[Assert\Count(max: 10)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 200)])]
        public array $attr = [],
    ) {}
}
