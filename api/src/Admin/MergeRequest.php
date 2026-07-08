<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/** The target of a manual product merge: the source is the {id} of the route. */
final readonly class MergeRequest
{
    public function __construct(
        #[Assert\Uuid] public string $targetId,
    ) {}
}
