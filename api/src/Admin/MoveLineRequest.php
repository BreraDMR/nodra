<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MoveLineRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim'), Assert\Length(max: 500)]
        public string $reason,
        /** another shipment of the order; null starts a new part */
        #[Assert\Uuid]
        public ?string $shipmentId = null,
    ) {}
}
