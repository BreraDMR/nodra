<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CategoryWriteRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 60), Assert\Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')] public string $slug,
        #[Assert\NotBlank, Assert\Length(max: 80)] public string $nameCs,
        #[Assert\NotBlank, Assert\Length(max: 80)] public string $nameDe,
        #[Assert\NotBlank, Assert\Length(max: 80)] public string $nameEn,
        #[Assert\Uuid] public ?string $parentId = null,
        public int $position = 0,
        public bool $active = true,
        #[Assert\Count(max: 30)] public array $attributes = [],
    ) {}
}
