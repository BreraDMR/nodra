<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ApplySuggestionRequest
{
    public function __construct(
        /** the suggestion the admin saw; a different current one is a 409 */
        #[Assert\PositiveOrZero] public int $suggestedPriceCzk,
    ) {}
}
