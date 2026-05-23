<?php

declare(strict_types=1);

namespace App\Admin;

final readonly class ScheduleShipmentRequest
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {}
}
