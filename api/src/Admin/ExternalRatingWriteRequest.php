<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ExternalRatingWriteRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9-_.]{1,59}$/', message: 'A source is a short lowercase slug')]
        public string $source,
        #[Assert\NotBlank]
        #[Assert\Length(max: 160)]
        public string $model,
        // the source's own number and scale, e.g. 4.5 out of 5 or 87 out of 100
        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?float $rating,
        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $ratingCount,
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $checkedAt,
        #[Assert\Positive]
        public ?float $ratingScale = null,
        #[Assert\Url]
        public ?string $url = null,
    ) {}
}
