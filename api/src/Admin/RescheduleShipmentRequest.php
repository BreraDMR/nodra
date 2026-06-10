<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RescheduleShipmentRequest
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        #[Assert\NotBlank(normalizer: 'trim'), Assert\Length(max: 500)]
        public string $reason,
    ) {}
}
