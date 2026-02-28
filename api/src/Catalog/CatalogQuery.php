<?php

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CatalogQuery
{
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
    ) {}
}
