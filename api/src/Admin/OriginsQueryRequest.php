<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/** The query string of the field-origin read: which entity type and up to 50 comma-separated ids. */
final class OriginsQueryRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['product', 'variant', 'offer'])] public string $type = 'variant',
        #[Assert\Length(max: 4000)] public string $ids = '',
    ) {}

    /** @return list<string> */
    public function ids(): array
    {
        $ids = [];
        foreach (explode(',', $this->ids) as $id) {
            if (trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        return $ids;
    }
}
