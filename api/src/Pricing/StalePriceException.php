<?php

declare(strict_types=1);

namespace App\Pricing;

/** The suggested price the admin saw is not the current suggestion anymore. */
final class StalePriceException extends \DomainException
{
    /** @param list<string> $variantIds */
    public function __construct(public readonly array $variantIds)
    {
        parent::__construct(sprintf('The suggested price changed for %d variant(s) since the preview; nothing was applied, load it again', count($variantIds)));
    }
}
