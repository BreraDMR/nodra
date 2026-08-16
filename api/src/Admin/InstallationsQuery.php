<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\InstallationBooking;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class InstallationsQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Choice(choices: InstallationBooking::STATUSES)]
        public ?string $status = null,
        /** a Prague evening YYYY-MM-DD */
        #[Assert\Date]
        public ?string $date = null,
    ) {}
}
